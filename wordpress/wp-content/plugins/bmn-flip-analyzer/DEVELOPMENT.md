# BMN Flip Analyzer - Development Log

## Current Version: 0.3.1

## Status: Phase 2.5 Complete - Ready for Phase 3 (Web Dashboard)

---

## Milestones

| # | Milestone | Status | Notes |
|---|-----------|--------|-------|
| 1 | Plugin scaffold + DB table | Complete | Main file, class-flip-database.php |
| 2 | ARV calculator | Complete | Haversine distance, 0.5/1.0mi expansion |
| 3 | Scoring modules (4) | Complete | Financial, Property, Location, Market |
| 4 | Orchestrator + CLI | Complete | Full pipeline, 7 WP-CLI commands |
| 5 | Photo analyzer | Complete | Claude Vision API integration |
| 6 | REST API endpoints | Complete | 6 endpoints for web + iOS |
| 7 | Web admin dashboard | Pending | Phase 3 - Chart.js + PHP template |
| 8 | iOS data model + API | Pending | Phase 4 - SwiftUI ViewModel |
| 9 | iOS UI views | Pending | Phase 4 - FlipAnalyzerSheet |
| 10 | Testing + tuning | Pending | Phase 5 - Score validation |

---

## Session Log

### Session 1 - 2026-02-05

**What was done:**
- Created plugin scaffold (`bmn-flip-analyzer.php`)
- Implemented `class-flip-database.php` with table schema, CRUD, target city management
- Implemented `class-flip-arv-calculator.php` with Haversine distance, comp matching, confidence levels
- Implemented all 4 scoring modules:
  - `class-flip-financial-scorer.php` (40% weight)
  - `class-flip-property-scorer.php` (25% weight)
  - `class-flip-location-scorer.php` (25% weight) with school API integration
  - `class-flip-market-scorer.php` (10% weight) with remarks keyword analysis
- Implemented `class-flip-analyzer.php` orchestrator with full pipeline
- Implemented `class-flip-cli.php` with 7 commands
- Created `CLAUDE.md` and `DEVELOPMENT.md`

**Issues encountered:**
- None yet (first implementation session)

**Next steps:**
1. ~~Activate plugin on local/staging WordPress~~ ✓
2. ~~Run `wp flip analyze --limit=10` to test pipeline~~ ✓
3. ~~Verify comp queries return expected results~~ ✓
4. ~~Check school API integration works~~ ✓
5. ~~Begin Phase 2 (photo analyzer + REST API)~~ ✓

### Session 2 - 2026-02-05

**What was done:**
- Fixed bugs from Session 1 testing:
  - Changed `annual_property_taxes` to `tax_annual_amount` in location scorer
  - Changed `bme_listing_details` to `bme_listings` for public_remarks in market scorer
  - Added MIN_LIST_PRICE ($100K) to filter out lease/land anomalies
  - Fixed `wpdb->prepare` escaping issue (`%%` for LIKE clauses)
  - Added null-safe return in ARV calculator
- Enhanced ARV calculator to prioritize renovated/new construction comps:
  - Added RENOVATED_KEYWORDS constant
  - Added SQL CASE for renovation priority scoring
  - Added LEFT JOIN to bme_listings_archive for public_remarks
- Created `class-flip-rest-api.php` with 6 endpoints:
  - GET /results - List scored properties (paginated)
  - GET /results/{listing_id} - Single property details
  - GET /results/{listing_id}/comps - Comparables for ARV
  - GET /summary - Per-city summary stats
  - GET /config/cities - Get target cities
  - POST /config/cities - Update target cities (admin only)
- Created `class-flip-photo-analyzer.php`:
  - Claude Vision API integration
  - Structured JSON analysis prompt
  - Photo score calculation
  - Rehab cost refinement from visual analysis
  - Batch processing for top candidates
- Updated `class-flip-cli.php`:
  - Implemented `analyze-photos` command with API call confirmation
- Version bump to 0.2.0

**Issues encountered:**
- `wpdb->prepare()` converts `%` to hash placeholders, needed `%%` for LIKE
- `get_results()` can return null, needed null-safe return

**Next steps:**
1. Deploy Phase 2 to server and test REST API
2. Test photo analysis on a few properties
3. Begin Phase 3 (Web admin dashboard)

### Session 3 - 2026-02-05

**What was done:**
- Tested photo analysis with 10 photos per property (increased from 5)
- Implemented three major algorithm improvements based on user feedback:
  1. **Road type detection via OpenStreetMap Overpass API** (`class-flip-road-analyzer.php`)
     - Queries OSM for highway type classification
     - Maps OSM tags (residential, secondary, primary, etc.) to scoring categories
     - Supports both coordinate-based and street-name-based queries
  2. **Neighborhood ceiling check** (`class-flip-arv-calculator.php`)
     - Queries max sale price within 0.5mi/12mo
     - Calculates ARV as % of ceiling
     - Flags properties where ARV > 100% of ceiling
  3. **Revised property scoring focused on expansion potential** (`class-flip-property-scorer.php`)
     - Removed bed/bath penalties (they can always be added)
     - Added lot size scoring (35% weight)
     - Added expansion potential scoring (30% weight) based on lot-to-house ratio
- Updated location scorer to include road_type scoring (25%) and ceiling_support (20%)
- Added road_type, neighborhood_ceiling, ceiling_pct, ceiling_warning to database schema
- Version bump to 0.3.0

**Issues encountered:**
- Photo URLs blocked by robots.txt (CloudFront CDN) - fixed with base64 encoding
- OSM Overpass API query syntax: `"i"` (quoted) should be `i` (unquoted) for case-insensitive regex
- OSM returning wrong street (Beaumont instead of Upham) when using coordinate-only query - fixed by adding street-name-based query

**Key Results:**
| Property | Road Type | Location Score | Notes |
|----------|-----------|----------------|-------|
| 515 Upham St, Melrose | Busy Road (secondary) | 46.25 | Correctly penalized |
| 3 West Hollow, Andover | Quiet Residential | 53.25 | ARV 150% of ceiling (warning) |
| 42 Lantern Ln, Burlington | Quiet Residential | 74.25 | Best expansion potential |

**Next steps:**
1. ~~Consider adding auto-disqualifier for ARV > 120% of neighborhood ceiling~~ ✓ (Session 4)
2. ~~Add rate limiting/retry logic for Overpass API (saw 429 errors)~~ ✓ (Session 4)
3. ~~Test photo analysis on top candidates~~ ✓ (Session 4)
4. Continue to Phase 3 (Web admin dashboard)

### Session 4 - 2026-02-05

**What was done:**
- **Overpass API retry logic** (`class-flip-road-analyzer.php`):
  - Extracted shared `make_overpass_request()` method with retry logic
  - Exponential backoff: 1s, 2s, 4s delays between retries
  - Handles 429 (rate limit), 408 (timeout), 503/504 (service unavailable)
  - Max 3 retries, non-retryable errors (400, 404) fail immediately
  - Both `query_osm_by_name()` and `query_osm()` now use shared method
- **Ceiling auto-disqualifier** (`class-flip-analyzer.php`):
  - Added check in `check_disqualifiers()`: ARV > 120% of neighborhood ceiling
  - Stores ceiling data (neighborhood_ceiling, ceiling_pct, ceiling_warning) in disqualified records
  - Two properties caught: 16 Court St, N. Andover (135%) and 3 West Hollow, Andover (151%)
- **Photo analysis testing** on 4 viable candidates:
  - MLS# 73432608 (515 Upham St, Melrose): Score 70.88→78.08, rehab $132K→$88K (cosmetic), ROI 9.56%→13.16%
  - MLS# 73453561 (42 Lantern Ln, Burlington): Photo score 72, $35/sqft moderate rehab
  - MLS# 73456716 (Lot 7 Weeping Willow, Andover): Photo score 5 (new construction lot?)
  - MLS# 73460195 (105 Central St, Andover): Photo score 25, $15/sqft cosmetic
- Version bump to 0.3.1

**Key observations:**
- Retry logic caught a 504 error during analysis and recovered successfully
- Photo analysis correctly identified 515 Upham St road as "Quiet Residential" (OSM had said "Busy Road" via secondary highway tag)
- Photo analysis significantly refined rehab estimates downward — most properties only need cosmetic work
- Only 4 of 69 properties survived all disqualifiers (65 disqualified)
- Low comp count (1 comp for top candidate) is the main risk factor

**Next steps:**
1. Phase 3: Web admin dashboard with Chart.js, expandable rows, CSV export
2. Consider expanding target cities or loosening disqualifier thresholds
3. Investigate low comp counts — may need to expand comp search radius or time window

---

## Known Bugs

| # | Description | Severity | Status | Found |
|---|-------------|----------|--------|-------|
| (none yet) | | | | |

---

## Architecture Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
| Standalone plugin vs MLD module | Separation of concerns, independent activation | 2026-02-05 |
| Two-pass photo analysis | Cost control — only analyze top 50 candidates (~$1.50-2.50/run) | 2026-02-05 |
| Reuse `mld_claude_api_key` | Avoid duplicate API key management | 2026-02-05 |
| Default rehab $30/sqft | Industry standard baseline, refined by photo analysis | 2026-02-05 |
| School lookup via REST API | Reuses BMN Schools integration, adds caching | 2026-02-05 |
| Remarks keyword analysis | Free signal (no API call), adds ±15 points | 2026-02-05 |
| OSM Overpass API for road type | Free, accurate road classification; avoids Google Maps API costs | 2026-02-05 |
| Expansion potential over bed/bath | Beds/baths can be added; lot size is fixed — focus on potential | 2026-02-05 |
| Neighborhood ceiling check | ARV must be realistic for the area; flags over-optimistic projections | 2026-02-05 |

---

## Scoring Weight Tuning Log

| Date | Change | Reason | Result |
|------|--------|--------|--------|
| 2026-02-05 | Initial weights set per plan | Based on research: financial most important (40%), property/location equal (25% each), market timing least (10%) | Baseline |

---

## Performance Benchmarks

| Metric | Target | Actual | Date |
|--------|--------|--------|------|
| Pass 1 (data scoring, 200 properties) | <60s | TBD | |
| Pass 2 (photos, 50 properties) | <10min | TBD | |
| REST API response (results list) | <500ms | TBD | |
| iOS sheet load time | <2s | TBD | |

---

## Target Cities (Current)

1. Reading
2. Melrose
3. Stoneham
4. Burlington
5. Andover
6. North Andover
7. Wakefield

Manage via: `wp flip config --list-cities` / `--add-city=` / `--remove-city=`

---

## File Structure

```
bmn-flip-analyzer/
├── bmn-flip-analyzer.php           # Main plugin file
├── CLAUDE.md                       # AI assistant reference
├── DEVELOPMENT.md                  # This file
├── includes/
│   ├── class-flip-analyzer.php     # Orchestrator
│   ├── class-flip-arv-calculator.php
│   ├── class-flip-financial-scorer.php
│   ├── class-flip-property-scorer.php
│   ├── class-flip-location-scorer.php
│   ├── class-flip-market-scorer.php
│   ├── class-flip-cli.php          # WP-CLI commands
│   ├── class-flip-database.php
│   ├── class-flip-photo-analyzer.php  # Claude Vision API
│   ├── class-flip-rest-api.php        # REST API endpoints
│   └── class-flip-road-analyzer.php   # OSM road type detection
├── admin/                          # (future) Admin dashboard
│   ├── class-flip-admin-dashboard.php
│   └── views/
│       └── dashboard.php
└── assets/                         # (future) CSS/JS
    ├── css/
    │   └── flip-dashboard.css
    └── js/
        └── flip-dashboard.js
```

---

## Version History

### v0.1.0 (Complete)
- Plugin scaffold with DB table creation
- ARV calculator from sold comps
- Financial, Property, Location, Market scoring modules
- Orchestrator with full analysis pipeline
- WP-CLI commands (analyze, results, property, summary, config, clear)
- Remarks keyword analysis for flip signals
- CLAUDE.md and DEVELOPMENT.md for documentation

### v0.2.0 (Complete)
- Claude Vision photo analyzer with structured JSON output
- REST API endpoints for web + iOS (6 endpoints)
- Photo-refined rehab cost estimates
- Renovation-prioritized comp selection
- Min price filter ($100K) to exclude anomalies

### v0.3.0 (Complete)
- Road type detection via OpenStreetMap Overpass API
- Neighborhood ceiling check (ARV vs max sale in area)
- Revised property scoring: lot size & expansion potential
- Removed bed/bath penalties (they can always be added)
- Street-name-based OSM queries for accurate road classification

### v0.3.1 (Complete)
- Overpass API retry logic with exponential backoff (429/503/504 handling)
- Auto-disqualify properties where ARV > 120% of neighborhood ceiling
- Store ceiling data in disqualified property records
- Photo analysis tested on top 4 candidates

### v0.4.0 (Planned)
- Web admin dashboard with Chart.js

### v0.5.0 (Planned)
- iOS SwiftUI integration

### v1.0.0 (Planned)
- Fully tested, tuned, production-ready

---

## Testing Checklist

### Phase 1 (Backend Core)
- [ ] Plugin activates without errors
- [ ] `wp_bmn_flip_scores` table created correctly
- [ ] `wp flip config --list-cities` shows 7 default cities
- [ ] `wp flip analyze --limit=10` completes successfully
- [ ] Results stored in database with all scores
- [ ] `wp flip results` displays formatted table
- [ ] `wp flip property <id> --verbose` shows comp details
- [ ] `wp flip summary` shows per-city breakdown
- [ ] Disqualified properties stored with reason
- [ ] ARV confidence levels set correctly (high/medium/low)
- [ ] School rating lookup works via BMN Schools API
- [ ] Remarks keywords detected and adjustment applied

### Phase 2 (Photo + API)
- [ ] Photo analyzer sends images to Claude Vision API
- [ ] Rehab cost estimate refined from photo analysis
- [ ] REST endpoints return correct JSON format
- [ ] JWT authentication works for protected endpoints

### Phase 3 (Web Dashboard)
- [ ] Admin page accessible under Tools menu
- [ ] Chart.js renders city breakdown chart
- [ ] Property rows expand to show details
- [ ] Run Analysis button triggers background job
- [ ] Export CSV downloads valid file

### Phase 4 (iOS)
- [ ] FlipAnalyzerSheet opens from PropertyDetailView
- [ ] API call fetches data and populates UI
- [ ] Score bars render correctly
- [ ] Comps list displays properly
- [ ] Button hidden for non-SFR properties
