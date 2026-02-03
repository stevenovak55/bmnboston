# Claude Code Reference - BMN Schools Plugin

Quick reference for AI-assisted development.

**Current Version:** 0.6.35
**Current Phase:** 7 - School Research Platform (Web Pages)
**Last Updated:** December 23, 2025

---

## Quick Start

This plugin provides comprehensive Massachusetts school data for the BMN Boston real estate platform. It serves as the central database and API hub for school information consumed by both the iOS app and website.

### Testing Environment

**IMPORTANT:** All testing is done against **PRODUCTION** (bmnboston.com), NOT localhost.
- **API Base URL:** `https://bmnboston.com/wp-json/bmn-schools/v1`
- **Status:** Phase 6 complete with all UX improvements

### Current Data Status

| Data Type | Records | Source | Status |
|-----------|---------|--------|--------|
| Schools | 2,636 | MassGIS | Complete |
| Districts | 342 | NCES EDGE | Complete with boundaries |
| District Boundaries | 342 | NCES EDGE GeoJSON | Complete |
| District Spending | 289 | E2C Hub (er3w-dyti) | Complete (2023-2024) |
| Test Scores (MCAS) | 44,213 | MA DESE | 2022-2025 data |
| Demographics | 5,460 | E2C Hub (t8td-gens) | Complete |
| Features | 21,787+ | E2C Hub (multiple) | AP, graduation, attendance, staffing (multi-year) |
| Geocoded Schools | 95.1% | Nominatim | 2,507/2,636 with coordinates |
| Rankings | 4,930 | Calculated | 2024 + 2025 rankings with YoY trends |
| State Benchmarks | 4 | Calculated | State averages by school level |
| District Rankings | 275 | Calculated | District composite scores (80% of MA) |
| College Outcomes | 71 | E2C Hub (vj54-j4q3) | Where graduates go (4yr/2yr/employed) |
| Discipline Data | 335 | DESE SSDR (manual) | District suspension/expulsion rates |
| Sports Data | 8,114 | MIAA (manual) | High school sports participation (347 schools) |

---

## Recent Changes (v0.6.35)

### Ranking Algorithm Alignment with Niche (Dec 23, 2025)

**Problem:** Rankings diverged significantly from external sources like Niche:
- Wakefield ranked #38, but Niche has it at #104
- Reading ranked #44, but Niche has it at #57
- Winchester ranked #88, but Niche has it at #7

**Root Causes Identified:**
1. Schools without MCAS data were being ranked (daycares, special ed, alternative programs)
2. Staffing year mismatch - using 2024 staff counts with 2025 enrollment
3. Student-teacher ratio weighted too heavily (20% originally)
4. AP performance weighted too lightly (6% originally)

**Fixes Applied (v0.6.32-v0.6.35):**

1. **MCAS Required for Ranking** (v0.6.32)
   - Schools must have MCAS data to be ranked
   - Reduced ranked schools from 1943 to 1610
   - District rankings now only use schools with MCAS

2. **Staffing Year Fix** (v0.6.33)
   - Ratio calculation now matches staffing and enrollment by year
   - Fixed Chenery score from 27.6 to 71.3

3. **Weight Rebalancing** (v0.6.34-v0.6.35)
   - MCAS: 25% → 40% (aligns with Niche's 50% academics weight)
   - AP Performance: 6% → 9%
   - Student-Teacher Ratio: 20% → 5%
   - Added College Outcomes: 4% (new factor)

**Results:**

| District | Before | After | Niche | Improvement |
|----------|--------|-------|-------|-------------|
| Winchester | #88 | #6 | #7 | +82 positions |
| Belmont | #21 | #12 | #3 | +9 positions |
| Hopkinton | #20 | #8 | #5 | +12 positions |
| Reading | #44 | #33 | #57 | +11 positions |
| Wakefield | #33 | #48 | #104 | Correctly dropped |

---

## Recent Changes (v0.6.27)

### School Research Platform Web Pages (Dec 23, 2025)

**Phase 7: School Research Platform - Web Pages Complete**

Built comprehensive school research pages integrated with the BMN Boston real estate site:

**Live URLs:**
- Browse: `https://bmnboston.com/schools/`
- District: `https://bmnboston.com/schools/{district-slug}/`
- School: `https://bmnboston.com/schools/{district-slug}/{school-slug}/`

**Key Features:**
- Virtual pages via WordPress rewrite rules (not CPTs)
- District browse page with filtering by grade/city/score
- District detail pages with schools list, map, listings, college outcomes
- Individual school pages with MCAS scores, demographics, sports
- SEO-optimized with Schema.org markup
- Breadcrumb navigation
- Google Maps integration for school locations

**Bug Fixes (Dec 23, 2025 Session):**

1. **Private Schools Affecting District Ratings**
   - Fixed 405 schools incorrectly labeled as `school_type: public`
   - Updated `calculate_district_rankings()` to exclude private schools
   - File: `includes/class-ranking-calculator.php`

2. **Minimum Data Requirement for Ranking**
   - Schools/districts now require minimum 3 data categories to be ranked
   - Prevents unfair advantage for schools with limited data
   - File: `includes/class-ranking-calculator.php`

3. **District Sort Order**
   - Changed browse page sort from `percentile_rank DESC` to `state_rank ASC`
   - State rank #1 now appears first, then #2, etc.
   - NULL values sorted last
   - File: `theme/inc/class-bmn-schools-helpers.php`

4. **Google Maps Initialization Error**
   - Removed `loading=async` from Google Maps URL (caused race condition)
   - Added robust check for `google.maps.Map !== 'function'`
   - Files: `theme/functions.php`, `theme/assets/js/schools.js`

5. **Breadcrumbs Visibility**
   - Removed `bne-section` class from district hero template
   - This class was overriding hero padding and hiding breadcrumbs
   - File: `theme/template-parts/schools/section-district-hero.php`

**Theme Files Created/Modified:**
```
wordpress/wp-content/themes/flavor-flavor-flavor/
├── page-schools-browse.php              # Browse template
├── page-school-district-detail.php      # District detail template
├── page-school-detail.php               # School detail template
├── inc/class-bmn-schools-helpers.php    # Data fetching functions
├── assets/css/schools.css               # All school page styles
├── assets/js/schools.js                 # Filters, autocomplete, maps
└── template-parts/schools/
    ├── section-browse-hero.php
    ├── section-districts-filters.php
    ├── section-districts-grid.php
    ├── section-district-hero.php
    ├── section-district-metrics.php
    ├── section-district-schools.php
    ├── section-district-map.php
    ├── section-district-listings.php
    ├── section-district-outcomes.php
    ├── section-district-safety.php
    ├── section-nearby-districts.php
    ├── section-district-faq.php
    ├── section-school-hero.php
    ├── section-school-metrics.php
    ├── section-school-mcas.php
    ├── section-school-demographics.php
    ├── section-school-features.php
    ├── section-school-sports.php
    └── section-school-map.php
```

**Plugin Files Modified:**
- `includes/class-school-pages.php` - Rewrite rules and routing
- `includes/class-ranking-calculator.php` - Private school exclusion, min data requirement

---

## Recent Changes (v0.6.26)

### Glossary Corrections (Dec 22, 2025)

Updated composite score and letter grade glossary entries to reflect actual calculation weights.

**Composite Score - Before:**
"MCAS scores (25%), graduation rate (15%), MassCore completion (15%), attendance (10%), AP performance (10%), MCAS growth (10%), per-pupil spending (10%), and student-teacher ratio (5%)"

**Composite Score - After:**
- **Middle/High:** MCAS (25%), student-teacher ratio (20%), graduation (12%), MassCore (10%), attendance (10%), MCAS growth (10%), AP (8%), spending (5%)
- **Elementary:** MCAS (35%), attendance (25%), student-teacher ratio (20%), MCAS growth (10%), spending (10%)

**Letter Grade - Before:**
"A+ (97-100), A (93-96), A- (90-92)..." (absolute score thresholds)

**Letter Grade - After:**
"A+ (top 10%), A (top 20%), A- (top 30%)..." (percentile-based)

---

## Recent Changes (v0.6.25)

### MIAA Sports Data Infrastructure (Dec 22, 2025)

Added high school sports participation data from Massachusetts Interscholastic Athletic Association (MIAA).

**Data Source:** MIAA Participation Survey Data (manual Excel/CSV upload)
- Download from: https://www.miaa.net/about-miaa/participation-survey-data
- Years available: 2024-2025 (current)
- Coverage: 347 high schools, 8,114 sport records

**New Database Table:** `wp_bmn_school_sports`
```sql
CREATE TABLE wp_bmn_school_sports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT UNSIGNED NOT NULL,
    year INT NOT NULL,
    sport VARCHAR(100) NOT NULL,
    gender ENUM('Boys', 'Girls', 'Coed') NOT NULL,
    participants INT DEFAULT NULL,
    UNIQUE KEY unique_sport (school_id, year, sport, gender)
);
```

**API Response (in `/property/schools` for high schools):**
```json
{
    "sports": {
        "sports_count": 20,
        "total_participants": 2762,
        "boys_participants": 1450,
        "girls_participants": 1312,
        "sports": [
            { "sport": "Baseball", "gender": "Boys", "participants": 45 },
            { "sport": "Basketball", "gender": "Boys", "participants": 42 }
        ]
    }
}
```

**Strong Athletics Highlight:**
Schools with 15+ sports programs receive a "Strong Athletics" highlight badge.

**Files Changed:**
- `includes/class-database-manager.php` - Added sports table creation
- `includes/class-bmn-schools.php` - Added 'sports' to get_table_names()
- `includes/class-rest-api.php` - Added get_school_sports() method
- `includes/class-ranking-calculator.php` - Added Strong Athletics highlight generation
- `admin/class-admin.php` - Added AJAX upload handler for sports data

---

## Recent Changes (v0.6.24)

### Discipline Percentile & Glossary Terms (Dec 22, 2025)

**New Glossary Terms (4 added):**
| Term | Definition |
|------|------------|
| Suspension | Temporary removal from school (in-school or out-of-school) |
| Expulsion | Permanent removal from school, typically for serious violations |
| Restorative Justice | An approach focusing on repairing harm rather than punishment |
| Discipline Rate | Percentage of students receiving disciplinary action |

**Discipline Percentile Display:**
Added percentile ranking to discipline data showing how a district compares statewide.

| Percentile | Label |
|------------|-------|
| 0-25% | Very Low (Safest) |
| 25-50% | Low |
| 50-75% | Average |
| 75-100% | Above Average |

**API Response Update:**
```json
{
    "discipline": {
        "discipline_rate": 3.7,
        "percentile": 28,
        "percentile_label": "Low"
    }
}
```

---

## Recent Changes (v0.6.23)

### District Discipline Data Import & Display (Dec 22, 2025)

Added district-level discipline data from MA DESE Student Safety and Discipline Reports (SSDR).

**Data Source:** DESE SSDR Reports (manual Excel/CSV upload)
- Download from: https://profiles.doe.mass.edu/statereport/ssdr.aspx
- Years available: 2024-2025 (current)
- Data is district-level (not school-level)

**Data Structure (stored in `districts.extra_data.discipline`):**
```php
$discipline = [
    'year' => 2025,
    'enrollment' => 49185,
    'students_disciplined' => 1705,
    'in_school_suspension_pct' => 0.3,
    'out_of_school_suspension_pct' => 3.2,
    'expulsion_pct' => 0.0,
    'removed_to_alternate_pct' => 0.0,
    'emergency_removal_pct' => 0.5,
    'school_based_arrest_pct' => 0.0,
    'law_enforcement_referral_pct' => 0.0,
    'discipline_rate' => 3.7,  // OSS + Expulsion + Emergency
];
```

**Admin Import Interface:**
- Location: BMN Schools > Import Data > SSDR Discipline
- Supports drag-and-drop Excel (.xlsx) or CSV file upload
- Matches districts by name (handles "Charter Public", "Regional" suffixes)
- Updates `districts.extra_data` JSON column

**API Response (in `/property/schools` and `/districts/{id}`):**
```json
{
    "district": {
        "id": 66,
        "name": "Boston School District",
        "discipline": {
            "year": 2025,
            "enrollment": 49185,
            "students_disciplined": 1705,
            "discipline_rate": 3.7,
            "out_of_school_suspension_pct": 3.2,
            "in_school_suspension_pct": 0.3,
            "expulsion_pct": 0,
            "emergency_removal_pct": 0.5
        }
    }
}
```

**Coverage:** 335 of 342 districts have discipline data (98%)

**Files Changed:**
- `admin/class-admin.php` - Added AJAX upload handler, file parsing, import logic
- `admin/views/import.php` - Added file upload UI with drag-and-drop
- `includes/class-rest-api.php` - Already exposes discipline in district responses
- `version.json`, `bmn-schools.php` - Version bump to 0.6.23

---

## Changes (v0.6.21)

### Elementary School Rating Improvements (Dec 22, 2025)

**Goal:** Reduce "limited data" warnings and add more data factors for elementary schools.

**Changes Made:**

1. **Added district-level per-pupil spending fallback**
   - Elementary schools now get spending scores from their district's `extra_data.expenditure_per_pupil_total`
   - 73% of elementary schools (648 of 883) now have spending data
   - Previously: 0% had school-level spending data

2. **Updated elementary weights** (now 5 factors instead of 4):
   | Factor | Old Weight | New Weight |
   |--------|------------|------------|
   | MCAS Proficiency | 40% | 35% |
   | Attendance | 25% | 25% |
   | Student-Teacher Ratio | 20% | 20% |
   | MCAS Growth | 15% | 10% |
   | Per-Pupil Spending | 0% | 10% |

3. **Updated data completeness thresholds**:
   - Comprehensive: 5 factors (was 4)
   - Good: 4 factors (was 3)
   - Limited: 1-3 factors

4. **Improved limited data messaging**:
   - "No MCAS data available (this school may be private or serve grades below 3)"
   - "Limited historical data for year-over-year comparison"
   - "Rating based on limited available metrics"

**Files Changed:**
- `includes/class-ranking-calculator.php` - District spending fallback, updated weights
- `includes/class-rest-api.php` - Updated thresholds and messaging (2 locations)
- `version.json`, `bmn-schools.php` - Version bump to 0.6.21

**API Response Update:**
```json
{
  "data_completeness": {
    "components_available": 5,
    "components_total": 5,  // Was 4 for elementary
    "confidence_level": "comprehensive",
    "components": ["mcas", "attendance", "growth", "spending", "ratio"],
    "limited_data_note": null
  }
}
```

---

## Changes (v0.6.20)

### College Enrollment Outcomes (Dec 22, 2025)

Added import and API exposure of college enrollment outcomes data showing where high school graduates go after graduation.

**Data Source:** E2C Hub dataset `vj54-j4q3`
- API: `https://educationtocareer.data.mass.gov/resource/vj54-j4q3.json`
- Years available: 2017-2021 (2021 is most recent)

**Data Structure:**
```php
$college_outcomes = [
    'year' => 2021,                    // Graduation year
    'grad_count' => 3417,              // Total graduates
    'total_postsecondary_pct' => 51.7, // % attending college
    'four_year_pct' => 38.1,           // % at 4-year college
    'two_year_pct' => 13.6,            // % at 2-year college
    'out_of_state_pct' => 6.9,         // % out of state
    'employed_pct' => 6.9,             // % employed
];
```

**Storage:** Stored in `districts.extra_data` JSON column under `college_outcomes` key.

**API Response:** Included in `/property/schools` and `/districts/{id}` responses:
```json
{
    "district": {
        "id": 66,
        "name": "Boston School District",
        "college_outcomes": {
            "year": 2021,
            "grad_count": 3417,
            "total_postsecondary_pct": 51.7,
            "four_year_pct": 38.1,
            "two_year_pct": 13.6,
            "out_of_state_pct": 6.9,
            "employed_pct": 6.9
        }
    }
}
```

**Admin Import:** Added `dese_college_outcomes` action to AJAX handler in `admin/class-admin.php`.

**Coverage:** 71 districts have outcomes data (32 from direct match + 39 from duplicate district resolution).

**Files Changed:**
- `admin/class-admin.php` - Added AJAX handler and stats for college_outcomes
- `includes/data-providers/class-dese-provider.php` - Already had `import_college_outcomes()` method
- `includes/class-rest-api.php` - Already exposes college_outcomes in district responses
- `version.json`, `bmn-schools.php` - Version bump to 0.6.20

---

## Changes (v0.6.17)

### Phase 6: Automatic School-District Mapping (Dec 21, 2025)

**New Feature: Automatic District Mapping**
- Added `map_schools_to_districts()` method to `class-database-manager.php`
- Uses 3-strategy approach to map schools to districts:
  1. **Exact match**: "City School District" (e.g., Boston → Boston School District)
  2. **Regional first position**: City in first part of regional name (e.g., Acton → Acton-Boxborough)
  3. **Regional second position**: City in second part of regional name (e.g., Boxborough → Acton-Boxborough)
- Integrated into `run_annual_sync()` as Step 7
- District coverage increased from 23 → 275 districts (80% of MA)

**Files Changed:**
- `includes/class-database-manager.php` - Added `map_schools_to_districts()` method
- `admin/class-admin.php` - Added district mapping step to annual sync

---

## Changes (v0.6.16)

### Phase 6: Expanded District Data Coverage (Dec 21, 2025)

**Bug Fixes:**
- Fixed district ranking calculation - admin AJAX handler now calls `calculate_district_rankings()` after school rankings (was missing before)

**Confidence-Based Scoring:**
- Low-confidence schools (limited data) now receive score penalties:
  - Comprehensive (7+ data points): No penalty
  - Good (5-6 data points): -5% penalty
  - Limited (1-4 data points): -10% penalty
- This prevents schools with limited data from outscoring well-documented schools

**Updated Ranking Weights (Parent-Focused):**
Student-teacher ratio increased from 5% to 20% (parents prioritize class size):
```php
private $weights = [
    'mcas_proficiency' => 0.25,   // 25%
    'ratio'            => 0.20,   // 20% (was 5%)
    'graduation_rate'  => 0.12,   // 12% (was 15%)
    'masscore'         => 0.10,   // 10% (was 15%)
    'attendance'       => 0.10,   // 10%
    'mcas_growth'      => 0.10,   // 10%
    'ap_performance'   => 0.08,   // 8% (was 10%)
    'per_pupil'        => 0.05,   // 5% (was 10%)
];
```

**Automated Annual Sync:**
- New WordPress cron job scheduled for September 1st each year
- Automatically imports: MCAS, demographics, graduation, attendance, AP, staffing
- Recalculates school and district rankings after import
- Sends admin email notification on completion/failure
- Hook: `bmn_schools_annual_sync`

**Files Changed:**
- `admin/class-admin.php` - Added `run_annual_sync()` method, fixed AJAX handler
- `includes/class-ranking-calculator.php` - Confidence penalty, updated weights
- `includes/class-bmn-schools.php` - Added 'annually' cron schedule filter
- `includes/class-activator.php` - Added `schedule_annual_sync()` method
- `includes/class-deactivator.php` - Added annual sync to cleanup hooks

---

## Changes (v0.6.15)

### Phase 6: Regional School Districts (Dec 21, 2025)

Many Massachusetts cities share schools with neighboring cities through tuition agreements or regional districts. Previously, searching for schools in these cities showed random nearby schools instead of the actual schools students attend.

**New Regional School Mapping:**
Added `get_regional_school_mapping()` method in `class-rest-api.php` with 50+ city mappings:

```php
$mappings = [
    'NAHANT' => [
        'elementary' => null,      // Local school
        'middle' => 'SWAMPSCOTT',  // Tuition agreement
        'high' => 'SWAMPSCOTT',
    ],
    'BOXBOROUGH' => [
        'elementary' => null,
        'middle' => 'ACTON',       // Acton-Boxborough Regional
        'high' => 'ACTON',
    ],
    // ... 50+ more cities
];
```

**Types of Arrangements:**
1. **Tuition Agreements:** Small towns pay neighboring districts (Nahant→Swampscott)
2. **Regional Districts:** Multiple towns share a district (Lincoln-Sudbury, King Philip)
3. **Vocational/Tech:** Students attend regional vocational schools

**API Response Changes:**
Schools from other cities now include additional fields:
```json
{
    "name": "Swampscott Middle School",
    "city": "SWAMPSCOTT",
    "is_regional": true,
    "regional_note": "Students from Nahant attend this school"
}
```

**School Level Detection Fix:**
Fixed `determine_display_level()` to properly categorize combined schools (e.g., PK-08):
- Schools with "Middle School" in name → categorized as middle
- Schools with "High School" in name → categorized as high
- Otherwise uses grade range logic

**Cities with Regional Mappings:**
| City | Middle School | High School |
|------|---------------|-------------|
| Nahant | Swampscott | Swampscott |
| Boxborough | Acton | Acton |
| Lincoln | Sudbury | Lincoln-Sudbury |
| Dover | Sherborn | Dover-Sherborn |
| Plainville | King Philip | King Philip |
| ... | (50+ total) | |

**Files Changed:**
- `includes/class-rest-api.php` - Added `get_regional_school_mapping()`, updated school queries
- `bmn-schools.php` - Version bump to 0.6.15
- `version.json` - Version bump

---

## Changes (v0.6.11)

### Phase 5: Elementary School Enhancements (Dec 21, 2025)

**Elementary-Specific Weighting:**
- Elementary schools now use different weight distribution:
  - MCAS Proficiency: 40% (vs 25% for middle/high)
  - Attendance: 25% (vs 10%)
  - Student-Teacher Ratio: 20% (vs 5%)
  - MCAS Growth: 15% (vs 10%)
  - Graduation, AP, MassCore, Spending: 0% (N/A for elementary)

**Limited Data Warning:**
- Elementary schools with < 3 data points show warning message
- "Rating based on limited data (test scores and attendance only)"
- Different confidence thresholds (4=comprehensive, 3=good for elementary)

### Phase 4: Educational Glossary (Dec 21, 2025)

**New Glossary Endpoint:** `/glossary/`
- 20 MA education terms with descriptions and parent tips
- Terms: MCAS, MassCore, Chapter 70, Charter School, Regional School, METCO, SPED, ELL, Composite Score, Letter Grade, Percentile Rank, AP, CTE, Innovation Pathway, Early College, Chronic Absence, Student-Teacher Ratio, Per-Pupil Spending, Accountability, School Committee
- Supports individual term lookup: `?term=mcas`
- Returns terms grouped by category

### Phase 3: School Highlights (Dec 21, 2025)

**Highlight Generation:** `generate_school_highlights()`
- Creates plain-English highlights for each school
- Types: Strong AP, Low Class Size, Diverse, CTE, Early College, Innovation Pathway, High Graduation, Good Attendance, High MassCore, Improving, Well Resourced
- Priority sorted, limited to top 4 per school
- Cached with 1-hour transient

**Programs Data:** `get_school_programs()`
- Returns: cte_available, cte_programs[], early_college, innovation_pathway, ap_courses_offered, ap_pass_rate, ap_participation_rate

### Phase 2: Benchmarks & District Rankings (Dec 20-21, 2025)

**State Benchmarks Table:** `bmn_state_benchmarks`
- Stores average composite scores by school level (Elementary, Middle, High, All)
- Used for "vs state average" comparisons

**District Rankings Table:** `bmn_district_rankings`
- Composite scores aggregated from school rankings
- Includes elementary_avg, middle_avg, high_avg breakdowns
- 23 districts currently ranked

**API Enhancements:**
- Schools include `benchmarks` object with `vs_state` and `vs_category` comparisons
- Format: "+12.3 above state avg" or "-5.2 below state avg"

### Phase 1: UX Quick Wins (Dec 20, 2025)

**Trend Direction:** "Improved 5 spots" / "Dropped 3 spots" (not "+5")
**Percentile Context:** "A- (Top 25%)" format
**Data Completeness:** Components available/total with confidence level
**Demographics:** Displayed in iOS (students, diversity %, free lunch %)

---

## Ranking Calculator

### Default Weights (Middle/High School) - v0.6.35

| Factor | Weight | Source | Notes |
|--------|--------|--------|-------|
| MCAS Proficiency | 40% | `bmn_school_test_scores` | Average proficient_or_above_pct |
| Graduation Rate | 12% | `bmn_school_features` (graduation) | 4-year rate |
| MCAS Growth | 10% | `bmn_school_test_scores` | Year-over-year improvement |
| AP Performance | 9% | `bmn_school_features` (ap_summary) | Pass rate or participation |
| MassCore Completion | 8% | `bmn_school_features` (masscore) | College-ready curriculum % |
| Attendance | 8% | `bmn_school_features` (attendance) | Inverse of chronic absence |
| Student-Teacher Ratio | 5% | `bmn_school_features` (staffing) | Lower is better |
| Per-Pupil Spending | 4% | `bmn_school_features` (expenditure) | Normalized to 0-100 |
| College Outcomes | 4% | `bmn_school_districts` (extra_data) | % attending college (district-level) |

### Elementary School Weights - v0.6.35

| Factor | Weight | Notes |
|--------|--------|-------|
| MCAS Proficiency | 45% | Primary metric for elementary |
| Attendance | 20% | Inverse of chronic absence |
| MCAS Growth | 15% | Year-over-year improvement |
| Per-Pupil Spending | 12% | Uses district fallback if no school data |
| Student-Teacher Ratio | 8% | Lower is better |
| Others | 0% | N/A for elementary (graduation, AP, MassCore, college outcomes) |

### Ranking Requirements

**Schools must have:**
- MCAS data (required - excludes daycares, special ed, alternative programs)
- At least 3 data components with non-zero weight

**Districts must have:**
- At least 3 ranked schools OR 500+ total enrollment
- At least 2 grade levels with data (e.g., elementary + high)
- Only public schools with MCAS data are included

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

### Data Completeness Thresholds

**Middle/High Schools (9 components):**
- Comprehensive: 7+ components (no penalty)
- Good: 5-6 components (-5% penalty)
- Limited: 3-4 components (-10% penalty)
- Insufficient: < 3 components (not ranked)

**Elementary Schools (5 components):**
- Comprehensive: 5 components (no penalty)
- Good: 4 components (-5% penalty)
- Limited: 3 components (-10% penalty)
- Insufficient: < 3 components (not ranked)

---

## REST API Endpoints

### Namespace: `bmn-schools/v1`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/schools` | GET | List schools with filters |
| `/schools/{id}` | GET | School detail with MCAS scores, ranking, highlights |
| `/schools/nearby` | GET | Schools near lat/lng |
| `/schools/map` | GET | Schools for map display (iOS) |
| `/schools/compare` | GET | Compare 2-5 schools |
| `/schools/top` | GET | Top schools by MCAS |
| `/schools/{id}/trends` | GET | MCAS trend analysis |
| `/property/schools` | GET | Schools grouped by level (iOS) - includes highlights, benchmarks |
| `/districts` | GET | List districts |
| `/districts/{id}` | GET | District detail with spending |
| `/districts/{id}/boundary` | GET | GeoJSON boundary |
| `/districts/for-point` | GET | Find district for coordinates |
| `/search/autocomplete` | GET | Search schools/districts |
| `/glossary/` | GET | Education term definitions (NEW) |
| `/health` | GET | Plugin health check |
| `/admin/geocode/status` | GET | Geocoding progress (admin) |
| `/admin/geocode/run` | POST | Run geocoding batch (admin) |

### Property Schools Response Structure

```json
{
  "success": true,
  "data": {
    "district": { "id": 42, "name": "Wellesley", "ranking": {...} },
    "schools": {
      "elementary": [{
        "id": 123,
        "name": "Bates Elementary",
        "grades": "K-5",
        "distance": 0.5,
        "ranking": {
          "composite_score": 85.2,
          "percentile_rank": 92,
          "state_rank": 67,
          "category_total": 843,
          "letter_grade": "A",
          "trend": {
            "direction": "up",
            "rank_change": 5,
            "rank_change_text": "Improved 5 spots from last year"
          },
          "data_completeness": {
            "components_available": 4,
            "components_total": 4,
            "confidence_level": "comprehensive",
            "limited_data_note": null
          },
          "benchmarks": {
            "state_average": 72.5,
            "vs_state": "+12.7 above state avg"
          }
        },
        "demographics": {
          "total_students": 450,
          "diversity": "Diverse",
          "pct_free_reduced_lunch": 12.5
        },
        "highlights": [
          {"type": "ratio", "text": "Low Student-Teacher Ratio", "detail": "11:1", "icon": "person.2.fill", "priority": 2},
          {"type": "diversity", "text": "Diverse Student Body", "icon": "person.3.fill", "priority": 3}
        ]
      }],
      "middle": [...],
      "high": [...]
    }
  }
}
```

### Glossary Endpoint

```bash
# Get all terms
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/"

# Get specific term
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/?term=mcas"
```

Response for single term:
```json
{
  "success": true,
  "data": {
    "term": "MCAS",
    "full_name": "Massachusetts Comprehensive Assessment System",
    "category": "testing",
    "description": "The statewide standardized test...",
    "parent_tip": "Look for schools where most students..."
  }
}
```

---

## Database Schema

### 13 Tables

| Table | Purpose | Records | Notes |
|-------|---------|---------|-------|
| `bmn_schools` | School directory | 2,636 | Public + private schools |
| `bmn_school_districts` | District info | 342 | Includes `boundary_geojson` and `extra_data` |
| `bmn_school_test_scores` | MCAS scores | 44,213 | 2017-2025, by grade/subject |
| `bmn_school_demographics` | Enrollment data | 5,460 | Race, gender, ELL, SPED, lunch |
| `bmn_school_features` | Programs | 21,787 | AP, graduation, attendance, staffing |
| `bmn_school_sports` | MIAA sports data | 8,114 | High school sports participation (NEW) |
| `bmn_school_data_sources` | Sync tracking | 19 | Last import timestamps |
| `bmn_schools_activity_log` | Debug logging | 83K+ | Errors and operations |
| `bmn_school_rankings` | Composite rankings | 4,930 | 2024 + 2025 with YoY trends |
| `bmn_state_benchmarks` | State averages | 4 | By school level |
| `bmn_district_rankings` | District scores | 275 | Aggregated from schools (80% of MA) |
| `bmn_school_attendance_zones` | School boundaries | 0 | Requires paid API (optional) |
| `bmn_school_locations` | Location mapping | 0 | Optional, not used |

---

## Key Files

| Purpose | File |
|---------|------|
| Main Plugin | `bmn-schools.php` |
| Singleton Class | `includes/class-bmn-schools.php` |
| Database Manager | `includes/class-database-manager.php` |
| REST API | `includes/class-rest-api.php` |
| Ranking Calculator | `includes/class-ranking-calculator.php` |
| Activity Logger | `includes/class-logger.php` |
| Cache Manager | `includes/class-cache-manager.php` |
| Geocoder | `includes/class-geocoder.php` |

### Ranking Calculator Methods

| Method | Purpose |
|--------|---------|
| `calculate_all_rankings()` | Calculate and store rankings for all schools |
| `calculate_school_score($id, $level)` | Calculate score for single school with level-specific weights |
| `get_school_ranking($id)` | Get stored ranking for a school |
| `get_school_highlights($id)` | Get plain-English highlights for a school |
| `generate_school_highlights($id)` | Generate highlights from feature data |
| `get_benchmark($metric, $level)` | Get state benchmark for comparison |
| `get_letter_grade_from_percentile($pct)` | Convert percentile to letter grade |

---

## iOS Integration

### Swift Models (in `ios/BMNBoston/Core/Models/School.swift`)

| Model | Purpose |
|-------|---------|
| `PropertySchoolsData` | Response from `/property/schools` |
| `NearbySchool` | School with ranking, demographics, highlights, sports |
| `NearbySchoolRanking` | Composite score, percentile, letter grade, trend, benchmarks |
| `RankingTrend` | Year-over-year rank change with direction |
| `DataCompleteness` | Components available, confidence level, limited data note |
| `RankingBenchmarks` | State/category averages with vs comparisons |
| `SchoolHighlight` | Type, text, detail, icon for highlight chips |
| `NearbySchoolDemographics` | Students, diversity, free lunch % |
| `SchoolSports` | Sports count, participants, individual sports list |
| `SchoolSport` | Individual sport with gender and participants |
| `DistrictDiscipline` | Discipline rates with percentile ranking |
| `CollegeOutcomes` | Where graduates go (4yr/2yr/employed) |
| `GlossaryTerm` | Term definitions with parent tips |
| `MapSchool` | Minimal data for map pins |
| `SchoolDistrict` | District info with ranking, discipline, outcomes |

### SchoolService Methods

| Method | Purpose |
|--------|---------|
| `fetchPropertySchools(lat, lng, radius, city)` | Get schools for property detail |
| `fetchMapSchools(bounds, level)` | Get schools for map display |
| `fetchSchoolDetail(id)` | Get full school detail |
| `fetchGlossaryTerm(term)` | Get single glossary term (NEW) |
| `fetchGlossary()` | Get all glossary terms (NEW) |

### NearbySchoolsSection Features

- Letter grade badges with color coding (A=green, B=blue, C=yellow, D=orange, F=red)
- Percentile context display ("A- (Top 25%)")
- Trend text ("Improved 5 spots from last year")
- Data completeness indicator with confidence level
- Limited data warning for elementary schools (orange)
- Benchmark comparison ("Above state average +12.3")
- Demographics row (students, diversity, free lunch)
- Sports row for high schools ("20 sports • 2,762 athletes")
- Strong Athletics highlight for schools with 15+ sports
- District discipline display with percentile ("3.7% • Low discipline rate")
- College outcomes display ("52% attend college")
- Horizontal scrolling highlight chips
- Glossary info links section ("Learn about these ratings")
- Glossary sheet with term explanations and parent tips

---

## Development Phases

- [x] Phase 1: Foundation (Core Plugin)
- [x] Phase 2: Massachusetts Data + History
- [x] Phase 3: District Boundaries (NCES EDGE)
- [x] Phase 4: Enhanced Features (Compare, Trends, Top Schools)
- [x] Phase 5: Platform Integration (iOS + Website)
- [x] Phase 6: School Rankings & UX Improvements
  - [x] Composite score calculation with 8 factors
  - [x] Year-over-year trend tracking
  - [x] Percentile-based letter grades
  - [x] State benchmarks and comparisons
  - [x] District-level rankings
  - [x] School highlights generation
  - [x] Education glossary endpoint
  - [x] Elementary-specific weighting
  - [x] Limited data warnings
  - [x] iOS display of all features
- [ ] Phase 7: Property Search Filters (Next)
- [ ] Phase 8: Optional Paid Enhancements (Deferred)

---

## Completed Features (Phase 6)

All Phase 6 features are now complete:
- ✅ Composite score calculation with 8 factors (updated weights Dec 2025)
- ✅ Year-over-year trend tracking
- ✅ Percentile-based letter grades
- ✅ State benchmarks and comparisons
- ✅ District-level rankings (275 districts, 80% of MA)
- ✅ School highlights generation (including Strong Athletics)
- ✅ Education glossary endpoint (24 terms)
- ✅ Elementary-specific weighting
- ✅ Limited data warnings
- ✅ College outcomes display
- ✅ District discipline data with percentile ranking
- ✅ MIAA sports data infrastructure (347 high schools)
- ✅ iOS display of all features
- ✅ Web display of all features

---

## Next Phase: Property Search Filters (Phase 7)

### Already Implemented (v6.29.0+)
School quality filters are already in the property search API:
- `school_grade` parameter (A, B, C)
- `near_top_elementary` / `near_top_high` parameters
- `school_district_id` filter
- Grid-based caching for performance

### Outstanding Work

**iOS Filter UI Enhancements:**
- School grade picker could be more prominent
- District picker dropdown (currently only in advanced mode)

**Web Filter UI:**
- District grade filter working
- Could add sports filter ("Near schools with strong athletics")

### Future Enhancements (Lower Priority)
- Side-by-side school comparison view
- Score component breakdown charts
- Historical trend visualization
- Attendance zone boundaries (requires paid API)

### Optional Paid APIs (Deferred)
- **SchoolDigger API** - $19.90/mo for third-party rankings
- **GreatSchools API** - $52.50/mo for 1-10 ratings
- **ATTOM API** - Enterprise pricing for attendance zones

---

## Testing API

```bash
# List schools
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools?per_page=10"

# School detail with ranking, highlights
curl "https://bmnboston.com/wp-json/bmn-schools/v1/schools/737"

# Schools for property (iOS endpoint) - full response with all new fields
curl "https://bmnboston.com/wp-json/bmn-schools/v1/property/schools?lat=42.30&lng=-71.26&radius=2"

# Glossary - all terms
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/"

# Glossary - single term
curl "https://bmnboston.com/wp-json/bmn-schools/v1/glossary/?term=mcas"

# Health check
curl "https://bmnboston.com/wp-json/bmn-schools/v1/health"
```

---

## Critical Rules

### 1. ALWAYS Update Version Numbers (ALL 3 LOCATIONS!)

**1. version.json:**
```json
{
    "version": "X.Y.Z",
    "db_version": "X.Y.Z",
    "last_updated": "YYYY-MM-DD"
}
```

**2. bmn-schools.php header:**
```php
* Version: X.Y.Z
```

**3. bmn-schools.php constant:**
```php
define('BMN_SCHOOLS_VERSION', 'X.Y.Z');
```

### 2. Cache Invalidation After Deployment

After deploying PHP files to production:
```bash
# Touch files to invalidate opcache
sshpass -p 'PASSWORD' ssh -p 57105 stevenovakcom@35.236.219.140 \
  "touch ~/public/wp-content/plugins/bmn-schools/includes/class-rest-api.php"
```

### 3. iOS Version Updates

Update `CURRENT_PROJECT_VERSION` in `project.pbxproj` (6 occurrences):
```
CURRENT_PROJECT_VERSION = N+1;
```
Use `replace_all` to update all at once.

---

## Common Mistakes to Avoid

1. **DON'T** use `state_or_province` - column is named `state`
2. **DON'T** forget to update all 3 version locations
3. **DON'T** make external API calls without rate limiting
4. **DON'T** skip cache invalidation after deployment (touch files or wait)
5. **DO** use level-specific weights for elementary schools
6. **DO** include `limited_data_note` for elementary schools with < 3 components
7. **DO** cache API responses (15-30 minutes)
8. **DO** use `state_school_id` (org_code) for DESE data matching

---

## Related Documentation

- Main project guide: `/CLAUDE.md`
- iOS app guide: `/ios/CLAUDE.md`
- MLD plugin guide: `/wordpress/wp-content/plugins/mls-listings-display/CLAUDE.md`
