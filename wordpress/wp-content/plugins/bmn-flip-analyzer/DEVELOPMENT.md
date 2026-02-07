# BMN Flip Analyzer - Development Log

## Current Version: 0.13.3

## Status: Phase 4.3 Complete (Saved Reports & Monitor System) - Pre-Phase 5 (iOS)

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
| 7 | Web admin dashboard | Complete | Phase 3 - Chart.js + PHP template |
| 7.5 | ARV & Financial overhaul | Complete | Phase 3.5 - Comp adjustments, financing model |
| 7.7 | Market-adaptive thresholds | Complete | Phase 3.7 - Adaptive DQ, near-viable |
| 7.8 | ARV & Financial Model Overhaul | Complete | Phase 3.8 - ARV fixes, financial model v2, risk analysis |
| 7.9 | Pre-Analysis Filters + Force Analyze | Complete | Phase 4.0 - 17 filters, force DQ bypass, CLI flags, PDF v2 |
| 8.0 | Renovation Potential Guard | Complete | Phase 4.1 - New construction DQ, inverted scoring, age rehab multiplier |
| 8.5 | Dashboard JS Modular Refactor | Complete | Phase 4.2 - Split 1,565-line monolith into 10 focused modules |
| 9.0 | Saved Reports & Monitor System | Complete | Phase 4.3 - Auto-save reports, load/rerun/delete, cron monitors |
| 9.5 | iOS data model + API | Pending | Phase 5 - SwiftUI ViewModel |
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

### Session 5 - 2026-02-05

**What was done:**
- **Phase 3: Web Admin Dashboard** — complete implementation:
  - `admin/class-flip-admin-dashboard.php` — admin menu page, AJAX handlers, data formatting
  - `admin/views/dashboard.php` — HTML template with stat cards, chart canvas, filters, table
  - `assets/js/flip-dashboard.js` — Chart.js grouped bar chart, expandable table rows, client-side filters, run analysis AJAX, CSV export
  - `assets/css/flip-dashboard.css` — card-based layout, score badges, road type badges, detail grid, responsive
- Dashboard features:
  1. Summary stat cards (total analyzed, viable, avg score, avg ROI, disqualified)
  2. Chart.js city breakdown (viable vs disqualified per city, with avg score tooltip)
  3. Client-side filters: city dropdown, min score slider (0-100), sort by (score/profit/ROI/price/ARV), show (viable/all/DQ)
  4. Results table with expandable rows showing:
     - Score breakdown with colored horizontal bars (financial/property/location/market/photo)
     - Financial summary (ARV, confidence, rehab, MAO, profit, ROI)
     - Property details (beds/baths, sqft, year, lot, road type, ceiling)
     - Comparable sales table (address, price, $/sqft, distance, sold date)
     - Photo analysis details (condition, renovation level, area scores, concerns)
     - Remarks signals (positive/negative keyword badges)
  5. "Run Analysis" button with AJAX + progress modal (5-min timeout)
  6. "Export CSV" button (client-side generation from filtered results)
- Updated main plugin file to load admin class on admin pages
- Version bump to 0.4.0

**Architecture decisions:**
- Used AJAX with nonce auth instead of REST API (admin cookie auth, avoids JWT requirement)
- All data loaded on initial page render via `wp_localize_script` (no AJAX for initial display)
- Client-side filtering (fast for ~200 properties, avoids unnecessary server roundtrips)
- Photo analysis remains CLI-only (cost control)

**Next steps:**
1. Deploy to production and test dashboard
2. Begin Phase 4 (iOS SwiftUI integration)
3. Consider adding photo analysis trigger button (with cost warning)

### Session 6 - 2026-02-05

**What was done (v0.4.1 → v0.5.0):**
- **Dashboard refinements** (v0.4.1):
  - Fixed road type misclassification: added HIGHWAY_SIGNIFICANCE ranking to OSM parser
  - Filtered out rental properties (`property_type = 'Residential'`)
  - Added road type ARV discount (-15% busy, -25% highway)
  - Added "Run Photo Analysis" button with cost estimate dialog
  - Added floor/mid/ceiling valuation range in financial section
  - Added DOM column and "View" property link to results table

- **Accuracy Overhaul** (v0.5.0):
  1. Age-scaled rehab costs replacing flat $30/sqft ($15-60/sqft based on property age)
  2. Comp address deduplication (removes pre-flip sales like 42 Hancock Rd $706K→$883K)
  3. Renovation-weighted ARV (renovated comps get 2x distance weight)
  4. Broken-down holding/closing costs (purchase 1.5%, sale 6%, holding 0.8%/mo × 6)

- **ARV improvements**:
  - Widened comp criteria: ±30% sqft, 12-month lookback, 10-comp limit
  - Expanding radius: 0.5mi → 1.0mi → 2.0mi with distance weighting
  - Fixed `$count` undefined bug in `calculate()` method
  - ARV projection calculator with preset scenarios + custom editable row
  - Confidence-based valuation range (replaces broken min/max ppsf approach)

**Impact on 25 Juniper Ave (example):**
| Metric | Before | After |
|--------|--------|-------|
| Rehab | $71K ($30/sqft) | $142K ($60/sqft, 1957 house) |
| Comps | 10 (w/ duplicate) | 9 (deduped) |
| Profit | -$37K | -$109K |
| ROI | -3.9% | -10.1% |

**Next steps:**
1. Phase 4: iOS SwiftUI integration
2. Run photo analysis on viable candidates to refine rehab estimates further
3. Consider tuning scoring weights based on real deal outcomes

### Session 7 - 2026-02-05

**What was done (v0.6.0 — ARV Accuracy & Financial Model Overhaul):**

- **Database schema migration** — 10 new columns:
  - `financing_costs`, `holding_costs`, `rehab_contingency`, `hold_months`
  - `cash_profit`, `cash_roi`, `cash_on_cash_roi`
  - `market_strength`, `avg_sale_to_list`, `rehab_multiplier`
  - Added `cash_on_cash_roi` to allowed sort fields

- **ARV calculator overhaul** (`class-flip-arv-calculator.php`):
  1. Bathroom filter on comps (±1.0 range) with fallback if <3 comps
  2. Appraisal-style comp adjustments scaled with city avg $/sqft:
     - Bedroom: ppsf×40, Full bath: ppsf×55, Half bath: ppsf×25
     - Sqft: ppsf×0.5/sqft (capped ±15%), Garage: ppsf×40, Basement: ppsf×28
     - Total adjustment capped at ±25% of comp price
  3. Time-decay weighting: `exp(-0.115 × months_ago)` (half-weight at 6 months)
  4. Time-adjusted comp prices using measured price trend
  5. Sale-to-list ratio: very_hot/hot/balanced/soft/cold classification
  6. Multi-factor confidence score: count (0-40) + distance (0-30) + recency (0-20) + variance (0-10)
  7. SQL now queries `garage_spaces`, `has_basement`, `list_price` from comps
  8. Fixed `date('Y')` → `wp_date('Y')` timezone pitfall

- **Financial model overhaul** (`class-flip-analyzer.php`):
  1. Shared `calculate_financials()` static method (used by main pipeline + photo analyzer)
  2. Dual scenarios: cash purchase AND hard money (12% rate, 2 pts, 80% LTV)
  3. Remarks-based rehab multiplier (14 cost reducers, 13 cost increasers, clamped 0.5x-1.5x)
  4. Dynamic hold period: rehab scope (1-6 mo) + area avg DOM/30 + permit buffer
  5. 10% rehab contingency
  6. Realistic holding costs: property tax (1.3%/yr) + insurance (0.5%/yr) + utilities ($350/mo)
  7. Post-calculation disqualifiers: min $25K profit, min 15% ROI (uses financed numbers)
  8. Extracted `build_result_data()` method

- **Photo analyzer fix** (`class-flip-photo-analyzer.php`):
  - Replaced stale `$arv × 0.12` formula with shared `Flip_Analyzer::calculate_financials()`
  - Now updates all new financial columns

- **Property scorer fix** (`class-flip-property-scorer.php`):
  - Fixed `date('Y')` → `wp_date('Y')` timezone pitfall

- **Dashboard admin** (`class-flip-admin-dashboard.php`):
  - Updated `format_result()` with 10 new fields for JSON output

- **Dashboard JS** (`assets/js/flip-dashboard.js`):
  1. Dual profit scenarios in financial section (Cash Purchase vs Hard Money)
  2. Market strength badge with sale-to-list ratio
  3. Rehab with contingency breakdown and remarks multiplier
  4. Comps table with adjusted prices and adjustment breakdown on hover
  5. Projection calculator: $250/sqft MA addition cost (was $80)
  6. Projection calculator: updated to match new financial model with financing
  7. CSV export: 10 new columns (financing, holding, cash profit, market strength, etc.)
  8. Added `marketStrengthBadge()` helper function

- Version bump to 0.6.0

**Timezone pitfalls fixed:**
- `class-flip-arv-calculator.php`: `date('Y')` → `wp_date('Y')` for new construction year
- `class-flip-property-scorer.php`: `date('Y')` → `wp_date('Y')` for age calculation

**Next steps:**
1. Deploy to production and run analysis to validate new financial model
2. Compare ARV before/after for known properties (verify comp adjustments)
3. Phase 4: iOS SwiftUI integration

### Session 8 - 2026-02-06

**What was done (v0.7.0 — Market-Adaptive Thresholds):**

**Problem:** All 61 properties across 7 MA cities were disqualified because fixed thresholds (15% ROI, $25K profit) don't account for market heat. In "very hot" markets where homes sell 5-8% above asking, flip margins are naturally compressed — but deals like 4 Waite Ave (8.3% ROI, $43K profit) are still viable.

- **Database migration** (`class-flip-database.php`):
  - Added `near_viable TINYINT(1)` and `applied_thresholds_json TEXT` columns
  - `migrate_v070()` method with ALTER TABLE for existing installs
  - Updated `get_summary()` to count near_viable properties
  - Version-check migration via `plugins_loaded` hook (no deactivate/reactivate needed)

- **Core adaptive thresholds** (`class-flip-analyzer.php`):
  - `MARKET_THRESHOLD_BOUNDS` constant: tier guard rails (very_hot through cold)
  - `MARKET_MAX_PRICE_ARV_RATIO` constant: pre-calc DQ ratios (0.78-0.92)
  - `get_adaptive_thresholds()` public static method:
    - Continuous formula: `clamp(2.5 - 1.5 × avg_sale_to_list, 0.4, 1.2)`
    - Clamps by tier bounds to prevent dangerous extremes
    - Low-confidence guard: don't relax below balanced when ARV confidence is low/none
  - Updated `check_disqualifiers()`: market-adaptive price/ARV ratio
  - Updated `check_post_calc_disqualifiers()`: accepts `$thresholds` array
  - Updated `run()` pipeline: computes thresholds, detects near-viable (80% of limits), stores JSON
  - Updated `store_disqualified()`: includes near_viable and applied_thresholds_json

- **Dashboard admin** (`class-flip-admin-dashboard.php`):
  - Added `near_viable` and `applied_thresholds` to `format_result()`

- **Dashboard HTML** (`admin/views/dashboard.php`):
  - Added Near-Viable stat card (amber border)
  - Added "Near-Viable" option to Show filter dropdown

- **Dashboard JS** (`assets/js/flip-dashboard.js`):
  - `renderStats()`: populates near-viable stat card
  - `applyFilters()` / `getFilteredResults()`: near_viable filter option
  - `buildRow()`: near-viable rows get amber styling and "NV" badge
  - `buildFinancialSection()`: "Thresholds Applied" section showing market-adjusted values
  - `exportCSV()`: added Near-Viable column

- **Dashboard CSS** (`assets/css/flip-dashboard.css`):
  - `.flip-stat-card.flip-stat-near`: amber border
  - `.flip-row-near-viable`: amber background, 0.85 opacity
  - `.flip-score-near`: amber score badge

- Version bump to 0.7.0

**Threshold table:**

| avg_sale_to_list | market_strength | Min Profit | Min ROI | Max Price/ARV |
|---|---|---|---|---|
| 1.08 | very_hot | $15K | 8% | 92% |
| 1.02 | hot | $18K | 10% | 90% |
| 1.00 | balanced | $25K | 15% | 85% |
| 0.95 | soft | $27K | 16% | 82% |
| 0.90 | cold | $30K | 18% | 78% |

**4 Waite Ave validation:** avg_sale_to_list ~1.07 → multiplier 0.895 → clamped to very_hot ceiling → 8% ROI threshold → 8.3% **PASSES**

**Next steps:**
1. Deploy to production and run analysis to validate adaptive thresholds
2. Verify 4 Waite Ave now shows as viable
3. Check near-viable count and dashboard display
4. Phase 4: iOS SwiftUI integration

### Session 9 - 2026-02-06

**What was done (v0.8.0 — ARV & Financial Model Overhaul):**

- **Database migration** (`class-flip-database.php`):
  - 6 new columns: `annualized_roi`, `breakeven_arv`, `deal_risk_grade`, `lead_paint_flag`, `transfer_tax_buy`, `transfer_tax_sell`
  - `migrate_v080()` method with version-check trigger
  - Added `annualized_roi` and `deal_risk_grade` to allowed sort fields

- **ARV accuracy fixes** (`class-flip-arv-calculator.php`):
  1. Sqft adjustment threshold lowered: 10% → 5% (catches 7.5% diffs worth ~$30K)
  2. Renovation comp weight reduced: 2.0/1.5 → 1.3/1.15 (prevents luxury finishes inflating ARV)
  3. Neighborhood ceiling: MAX → P90 (90th percentile, min 3 sales required)

- **Smooth rehab cost** (`class-flip-analyzer.php`):
  - Replaced step-function `get_rehab_per_sqft()` with `clamp(10 + age × 0.7, 12, 65)`
  - Eliminates 100% jumps at age boundaries (e.g., age 10=$15 → age 11=$30)
  - Return type changed from `int` to `float`

- **Financial model updates** (`class-flip-analyzer.php`):
  1. MA transfer tax: `MA_TRANSFER_TAX_RATE = 0.00456` on both buy and sell sides
  2. Lead paint: $8K flat for pre-1978 (skips if remarks mention "lead paint")
  3. Scaled contingency: `get_contingency_rate()` method — 8% cosmetic → 20% major/gut
  4. Hard money rate: 12% → 10.5% (2025-2026 market average)
  5. Commission: 5% → 4.5% (post-NAR settlement)
  6. Actual tax rates from `bme_listing_financial.tax_annual_amount` (was flat 1.3%)
  7. Adjusted MAO: includes holding + financing costs
  8. Annualized ROI: `(1 + roi)^(12/months) - 1` for comparing different hold periods
  9. Breakeven ARV: minimum ARV for $0 financed profit

- **Deal risk grade** (`class-flip-analyzer.php`):
  - `calculate_deal_risk_grade()` method with 5 weighted factors:
    - ARV confidence (35%), margin cushion (25%), comp consistency (20%), market velocity (10%), comp count (10%)
  - Score → grade: ≥80=A, ≥65=B, ≥50=C, ≥35=D, <35=F

- **Location scoring rebalance** (`class-flip-location-scorer.php`):
  - Removed tax_rate factor (double-counts with holding costs)
  - New weights: road_type 25%, ceiling_support 25%, price_trend 25%, comp_density 15%, school_rating 10%

- **Dashboard updates**:
  - New table columns: Deal Risk Grade (A-F badge), Annualized ROI
  - Lead paint "Pb" badge next to address for pre-1978 properties
  - Sensitivity table: Base Case / Conservative (-10% ARV, +20% rehab) / Worst Case (-15%, +30%)
  - Breakeven ARV with margin % in financial section
  - Transfer tax as separate line items in cost breakdown
  - Adjusted MAO alongside classic 70% rule
  - Updated labels: "10.5%" rate, "4.5% comm + 1%"
  - `calcProjectionCosts()` synced with new constants
  - CSV export: 6 new columns (risk grade, annualized ROI, breakeven, transfer tax, lead paint)

- **REST API** (`class-flip-rest-api.php`):
  - Added 6 new fields to `format_result()`
  - Updated sort enum with `annualized_roi`, `deal_risk_grade`

- Version bump to 0.8.0

### Session 10 - 2026-02-06

**What was done (v0.9.0 → v0.10.0 — Pre-Analysis Filters + Force Analyze + PDF v2):**

*(Session log retroactively added — see CLAUDE.md for full feature list)*

- 17 configurable pre-analysis filters (property type, status, price/sqft/DOM/year ranges, sewer, garage, beds/baths)
- Filters stored as WP option `bmn_flip_analysis_filters`, persistent across sessions
- Closed status queries `bme_listing_summary_archive` via UNION
- Collapsible "Analysis Filters" panel on dashboard with save/reset
- CLI filter override flags (don't persist)
- Force Analyze button on DQ'd rows — bypasses all DQ checks, runs full pipeline
- `force_analyze_single()` public method on Flip_Analyzer
- PDF report v2: photo thumbnail strip, circular score gauge, stacked bar chart, sensitivity line chart, comp photo cards
- Version bump to 0.10.0

### Session 11 - 2026-02-06

**What was done (v0.11.0 — Renovation Potential Guard):**

**Problem:** Two condos in Reading passed with high scores despite having zero renovation potential:
- **48 Village St** — Built 2024, brand new construction. Nothing to renovate.
- **30 Taylor** — Built 2015. Very little value-add unless distressed.

Five root causes identified: (1) year-built scoring was backwards (newer = higher), (2) no new construction DQ existed, (3) rehab formula created phantom profit via $12/sqft minimum on new homes, (4) remarks penalty was negligible (~1.5pts), (5) no renovation need assessment.

- **Database migration** (`class-flip-database.php`):
  - New column: `age_condition_multiplier DECIMAL(4,2) DEFAULT 1.00`
  - `migrate_v0110()` method with version-check trigger

- **Pipeline reorder + new construction DQ** (`class-flip-analyzer.php`):
  - Moved `Flip_Market_Scorer::get_remarks()` call before `check_disqualifiers()` (was after)
  - Added `get_property_condition()` query to `bme_listing_details` (previously unused field)
  - New construction auto-DQ: age ≤5 years unless distress keywords or poor condition
  - Pristine condition DQ: "New Construction"/"Excellent" condition on properties <15 years old
  - 5 new helper methods: `get_property_condition()`, `has_distress_signals()` (17 keywords), `condition_indicates_distress()`, `condition_indicates_pristine()`, `get_age_condition_multiplier()`
  - Updated `check_disqualifiers()` signature to accept `$remarks` and `$property_condition`

- **Age-based rehab condition multiplier** (`class-flip-analyzer.php`):
  - New multiplier in `calculate_financials()`: 0.10x (age ≤5), 0.30x (≤10), 0.50x (≤15), 0.75x (≤20), 1.0x (21+)
  - Stacks with existing remarks multiplier: `effective = base × age_mult × remarks_mult`
  - Lowered rehab floor: $12/sqft → $5/sqft for realistic near-zero on new properties
  - Added `age_condition_multiplier` to `build_result_data()` and `store_disqualified()`

- **Inverted property scorer** (`class-flip-property-scorer.php`):
  - Renamed `score_year_built_systems()` → `score_renovation_need()`
  - Inverted curve: age ≤5 → 5pts, 41-70 → 95pts (sweet spot), 100+ → 70pts
  - Factor key renamed `year_built` → `renovation_need`

- **Enhanced market scorer** (`class-flip-market-scorer.php`):
  - Converted flat keyword arrays to weighted arrays (2-5 pts per keyword)
  - Cap increased: ±15 → ±25
  - New positive keywords: "original condition", "dated kitchen/bath", "contractor special", "tear down"
  - New negative keywords: "just built", "newly built", "recently completed", "like new", "new build"

- **Dashboard + CLI** (`flip-dashboard.js`, `flip-dashboard.css`, `class-flip-cli.php`):
  - Age condition multiplier display in rehab note section
  - "NEW" badge (red) on DQ reason for new construction DQs
  - Age condition multiplier in CSV export
  - CLI `wp flip property` shows age condition discount

- **Admin dashboard** (`class-flip-admin-dashboard.php`):
  - Added `age_condition_multiplier` to `format_result()`

- Version bump to 0.11.0

**Expected results:**
| Property | Before | After |
|----------|--------|-------|
| 48 Village St (2024) | Passed with high score | Auto-DQ'd: "Recent construction (2024)" |
| 30 Taylor (2015) | Passed with high score | Renovation need 100→35, rehab ~50% lower |
| Typical 1970s target | Score ~70 | Score slightly higher (renovation need 70→95) |

**Files modified (9):**
1. `includes/class-flip-analyzer.php` — pipeline reorder, DQ, age multiplier, helpers, lower floor
2. `includes/class-flip-property-scorer.php` — inverted renovation need curve
3. `includes/class-flip-market-scorer.php` — weighted keywords, new terms, higher cap
4. `includes/class-flip-database.php` — migration for age_condition_multiplier column
5. `admin/class-flip-admin-dashboard.php` — format_result() update
6. `assets/js/flip-dashboard.js` — age condition display, NEW badge, CSV
7. `assets/css/flip-dashboard.css` — NEW badge and age-mult styling
8. `includes/class-flip-cli.php` — age condition in property command
9. `bmn-flip-analyzer.php` — version bump + migration registration

**Next steps:**
1. Deploy to production and run `wp flip analyze --city=Reading`
2. Verify 48 Village St is DQ'd, 30 Taylor has reduced scores
3. Verify typical 1970s properties still score well
4. Phase 5: iOS SwiftUI integration

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
| Age-scaled rehab costs | $15-60/sqft based on property age; flat $30 was unrealistic for older homes | 2026-02-05 |
| Broken-down costs | Purchase closing + sale costs + holding replaces opaque 12% of ARV | 2026-02-05 |
| Renovation-weighted ARV | Renovated comps get 2x weight — flip ARV should reflect post-reno values | 2026-02-05 |
| Comp address dedup | Same property sold twice (pre/post flip) should only count the post-flip sale | 2026-02-05 |
| School lookup via REST API | Reuses BMN Schools integration, adds caching | 2026-02-05 |
| Remarks keyword analysis | Free signal (no API call), adds ±15 points | 2026-02-05 |
| OSM Overpass API for road type | Free, accurate road classification; avoids Google Maps API costs | 2026-02-05 |
| Expansion potential over bed/bath | Beds/baths can be added; lot size is fixed — focus on potential | 2026-02-05 |
| Neighborhood ceiling check | ARV must be realistic for the area; flags over-optimistic projections | 2026-02-05 |
| Market-scaled comp adjustments | Adjustment values scale with local $/sqft — same formula works in $200 vs $500 markets | 2026-02-05 |
| Dual financial model | Show both cash and financed scenarios; score/DQ uses financed (more conservative) | 2026-02-05 |
| Shared calculate_financials() | Single source of truth prevents photo analyzer from diverging from main pipeline | 2026-02-05 |
| Post-calc disqualifiers | $25K min profit, 15% min ROI — prevents "technically profitable" bad deals | 2026-02-05 |
| Market-adaptive thresholds | Continuous formula + tier guard rails: avoids cliff effects while preventing dangerously low thresholds | 2026-02-06 |
| Near-viable category | 80% of adjusted thresholds — gives visibility into borderline properties without lowering pass bar | 2026-02-06 |
| Low-confidence guard | Don't relax thresholds when ARV confidence is low/none — unreliable data shouldn't loosen DQ gates | 2026-02-06 |
| Dynamic hold period | Rehab scope + area DOM — cosmetic flips take 3mo, gut renos take 8+ | 2026-02-05 |
| Bathroom filter with fallback | Prevents 3bed/1bath from pulling 3bed/3bath comps, but relaxes if too few comps | 2026-02-05 |
| $250/sqft addition cost | Massachusetts additions cost $200-400/sqft including permits; $80 was unrealistic | 2026-02-05 |
| Smooth rehab cost formula | Continuous `10 + age × 0.7` eliminates 100% jumps at step boundaries | 2026-02-06 |
| P90 neighborhood ceiling | MAX vulnerable to outlier McMansions; P90 filters top 10% of sales | 2026-02-06 |
| Reduced reno comp weight | 2.0x over-weighted luxury finishes; 1.3x gives mild emphasis without inflating ARV | 2026-02-06 |
| MA transfer tax | $4.56/$1000 deed excise tax missing from all prior versions | 2026-02-06 |
| Lead paint $8K flat | MA requires lead-safe renovation for pre-1978; $8K covers testing + remediation | 2026-02-06 |
| Scaled contingency | 8-20% by scope: cosmetic rehabs need less buffer than gut renovations | 2026-02-06 |
| Annualized ROI | Comparing 15% over 4 months vs 15% over 12 months requires annualization | 2026-02-06 |
| Deal risk grade | Composite A-F grade gives instant deal quality signal for dashboard scanning | 2026-02-06 |
| Remove tax rate from location | Already captured in holding costs; double-counting inflated location penalty | 2026-02-06 |
| New construction auto-DQ | Properties ≤5 years old have nothing to renovate; distress keyword escape hatch prevents false positives | 2026-02-06 |
| Inverted renovation need scoring | Flip targets need renovation — older properties score higher (sweet spot 41-70 years) | 2026-02-06 |
| Age-based rehab multiplier | 0.10x for age ≤5 to 1.0x for 21+; prevents phantom profit on near-new properties | 2026-02-06 |
| Lowered rehab floor $12→$5 | $12 minimum created $18K fake rehab on brand-new homes; $5 floor + 0.10x multiplier = realistic $750 | 2026-02-06 |
| Weighted remarks keywords | Strongest signals ("new construction", "as-is") deserve more impact than weak signals ("updated") | 2026-02-06 |
| Property condition from bme_listing_details | Free supplementary signal; catches "New Construction"/"Excellent" even when remarks are vague | 2026-02-06 |
| Pipeline reorder (remarks before DQ) | New construction DQ needs remarks for distress keyword escape hatch | 2026-02-06 |
| Report-scoped results via report_id | Foreign key on scores table avoids copying data; single table, indexed column | 2026-02-06 |
| Criteria snapshot in reports | Cities + filters frozen at creation; re-run uses original criteria, not current settings | 2026-02-06 |
| Monitor incremental via seen table | Track processed listing_ids per monitor; only analyze new ones on each cron run | 2026-02-06 |
| Soft delete for reports | Status='deleted' preserves scores data; hard delete would orphan wp_bmn_flip_scores rows | 2026-02-06 |
| 25-report cap | Prevents unbounded growth; soft-deleted reports don't count toward cap | 2026-02-06 |
| Transient-based concurrency locks | Prevents parallel re-runs of same report; auto-expires after 5 min if process crashes | 2026-02-06 |
| Cron in both activation + plugins_loaded | File-only updates (SCP) skip activation hook; migration block ensures cron is scheduled | 2026-02-06 |
| Latest manual report for dashboard | Global "latest" view must prefer manual reports over monitor-created ones; prevents cron runs from hijacking dashboard | 2026-02-07 |
| Orphan score scoping via report_id IS NULL | Summary stats for non-report context must exclude report-scoped scores; WHERE clause ensures clean separation | 2026-02-07 |
| Deferred mark_listings_seen | Mark new listings as seen AFTER analysis, not before; prevents data loss if analysis crashes mid-run | 2026-02-07 |
| Periodic cleanup on monitor cron | Piggyback cleanup of deleted report scores/seen data on existing twicedaily cron; avoids separate schedule | 2026-02-07 |
| ARV confidence discount on DQ thresholds | Low/none confidence means profit estimate is unreliable; require 25-50% more to pass | 2026-02-06 |
| Pre-DQ rehab with full multipliers | Base-only estimate missed age+remarks adjustments; new construction got inflated rehab in DQ check | 2026-02-06 |
| Scope-based contingency rate | Contingency should reflect work complexity (base × remarks), not age-discounted cost | 2026-02-06 |
| Scope-based hold period | Age discount reduces cost, not timeline; a property needing work takes the same time regardless of age discount | 2026-02-06 |
| Market strength data-limited guard | <3 sale-to-list data points is insufficient to claim hot/very_hot; default to balanced | 2026-02-06 |
| Distressed comp downweighting | Foreclosure/short-sale comps sell below market; 0.3x weight prevents ARV depression | 2026-02-06 |
| Expansion potential capped for condos | Condos/townhouses share lot space; raw lot-to-building ratio is misleading | 2026-02-06 |
| Post-multiplier rehab floor $2/sqft | Multiplier stacking can produce sub-$1/sqft rehab which is unrealistic | 2026-02-06 |
| Word-boundary distress detection | "fixer" shouldn't match "prefixed"; expanded context window 80→120 chars | 2026-02-06 |

### Session 12 - 2026-02-06

**What was done (v0.11.1 — Logic Audit Fixes):**

**Problem:** Comprehensive code audit identified 11 logical loopholes across all scoring/calculation files.

**Root causes and fixes:**
1. **ARV confidence not used in profit DQ** — low/none confidence now requires 25-50% higher profit/ROI to pass post-calc DQ
2. **Pre-DQ rehab ignored multipliers** — rehab estimate in `check_disqualifiers()` now applies age_condition_mult × remarks_mult
3. **Missing $/sqft got neutral 50** — changed to penalty score 30 (missing data shouldn't equal average)
4. **Ceiling type fallback untracked** — added `ceiling_type_mixed` flag when using all-types ceiling fallback
5. **Contingency rate used age-discounted $/sqft** — now uses `scope_per_sqft = base × remarks_mult` (scope, not cost)
6. **Hold period used raw base $/sqft** — now uses scope_per_sqft (age discount reduces cost, not work timeline)
7. **Market strength defaulted silently** — <3 STL data points caps market at balanced (can't claim hot)
8. **Distress detection brittle** — word-boundary matching, 120-char context, 16 negation phrases
9. **Sub-$1/sqft rehab possible** — added `max(2.0, ...)` floor after all multipliers
10. **No arm's-length comp validation** — foreclosure/short-sale comps get 0.3x weight via `is_distressed` flag
11. **Expansion potential ignored property type** — condos/townhouses capped at 40 score

**Files modified:** `class-flip-analyzer.php`, `class-flip-arv-calculator.php`, `class-flip-financial-scorer.php`, `class-flip-property-scorer.php`, `bmn-flip-analyzer.php`

**Verification:**
- Reading: 18 properties, 17 DQ'd, 1 passed (30 Taylor)
- Billerica: 23 properties, all DQ'd
- 48 Village St still correctly DQ'd (new construction)
- 41 Boston Rd shows `[low ARV confidence]` in DQ reason (fix #1)
- 216 Rangeway uses condo comps (ARV $577K, not SFR $1M+)
- Market strength properly constrained with limited data (fix #7)

### Session 13 - 2026-02-07

**What was done (v0.12.0 — Dashboard JS Modular Refactor):**

**Problem:** `flip-dashboard.js` was 1,565 lines handling 12+ responsibilities (charts, tables, filters, detail rows, projections, AJAX, cities, CSV export, etc.) in a single IIFE. Hard to navigate, maintain, and extend. Additionally, a latent bug: `applyFilters()` and `getFilteredResults()` duplicated identical filter+sort logic — if they diverge, expanded detail rows show the wrong property.

- **10 new module files** (`assets/js/`):
  - `flip-core.js` (20 lines) — namespace `window.FlipDashboard` + shared state
  - `flip-helpers.js` (91 lines) — 6 utility functions
  - `flip-stats-chart.js` (112 lines) — stats cards, Chart.js chart, city filter
  - `flip-filters-table.js` (151 lines) — filters, table rendering, row toggle
  - `flip-detail-row.js` (419 lines) — all expanded detail row builders
  - `flip-projections.js` (152 lines) — ARV projection calculator + live updates
  - `flip-ajax.js` (321 lines) — all AJAX operations + CSV export
  - `flip-analysis-filters.js` (147 lines) — pre-analysis filters panel
  - `flip-cities.js` (86 lines) — city tag management
  - `flip-init.js` (75 lines) — init + event binding (loaded last)

- **Namespace pattern:** `window.FlipDashboard` with sub-objects (`.helpers`, `.stats`, `.filters`, `.detail`, `.projections`, `.ajax`, `.analysisFilters`, `.cities`). Each module wraps in `(function (FD, $) { ... })(window.FlipDashboard, jQuery);`

- **Bug fix:** `applyFilters()` now delegates to `getFilteredResults()`:
  ```js
  FD.filters.applyFilters = function () {
      FD.filters.renderTable(FD.filters.getFilteredResults());
  };
  ```

- **PHP changes** (`admin/class-flip-admin-dashboard.php`):
  - `enqueue_assets()` rewritten: single script → 10-file dependency chain
  - `wp_localize_script` target: `'flip-dashboard'` → `'flip-init'`

- **Deleted:** `assets/js/flip-dashboard.js`

- Version bump to 0.12.0

**Architecture decision:** Used namespace object pattern with `wp_enqueue_script` dependency chains (no bundler needed). Compatible with WordPress asset pipeline, supports cache-busting via version parameter.

**Verification (production):**
- HTTP 200 on admin page, no JS console errors
- All 10 JS files syntax-validated via `node --check`
- Version `0.12.0` confirmed on production
- CLI commands (`wp flip summary`, `wp flip results`) working
- No new PHP errors in error log
- File integrity: local and production line counts match exactly

**Files modified (14):**
1. `assets/js/flip-core.js` — **New**
2. `assets/js/flip-helpers.js` — **New**
3. `assets/js/flip-stats-chart.js` — **New**
4. `assets/js/flip-filters-table.js` — **New**
5. `assets/js/flip-detail-row.js` — **New**
6. `assets/js/flip-projections.js` — **New**
7. `assets/js/flip-ajax.js` — **New**
8. `assets/js/flip-analysis-filters.js` — **New**
9. `assets/js/flip-cities.js` — **New**
10. `assets/js/flip-init.js` — **New**
11. `assets/js/flip-dashboard.js` — **Deleted**
12. `admin/class-flip-admin-dashboard.php` — Rewritten `enqueue_assets()`
13. `bmn-flip-analyzer.php` — Version bump + changelog comment

**Next steps:**
1. Phase 5: iOS SwiftUI integration
2. Consider further refactoring: `class-flip-pdf-generator.php` (1,907 lines)
3. Consider further refactoring: `class-flip-analyzer.php` (1,335 lines)

### Session 14 - 2026-02-07

**What was done (v0.12.0 — PDF Report Branding + Agent CTA):**

**Goal:** Add BMN Boston branding and Steve Novak agent call-to-action to the PDF analysis reports.

- **Cover page branding** (`class-flip-pdf-generator.php`):
  - BMN Boston logo in hero overlay (top-right, 92% opacity)
  - Subtitle changed: "Generated [date]" → "Prepared by Steve Novak | [date]"

- **Branded footer on every page**:
  - Small BMN logo on left side of footer
  - Text: "BMN Boston Real Estate | Steve Novak (617) 955-2224 | bmnboston.com | Page X/Y"
  - Logo downloaded once in `initialize_pdf()`, stored on TCPDF instance, cleaned up after save

- **New Call to Action page** (final page of report):
  - BMN Boston logo centered at top
  - "Interested in This Property?" headline
  - Personalized message from Steve Novak
  - Agent card with circular headshot photo, name, title, phone, email, license
  - Two CTA buttons: "Call (617) 955-2224" and "Email Steve"
  - Company info: BMN Boston Real Estate, 20 Park Plaza, Boston, MA 02118
  - "Member of Douglas Elliman" affiliation

- **"Property Offered By" disclosure**:
  - Shows listing agent name and brokerage office (no contact info)
  - New `fetch_listing_agent_info()` method queries `bme_listings` → `bme_agents` + `bme_offices`
  - Example: "James Pham | RE/MAX Partners"

- **Branding properties** added to `Flip_PDF_Generator`:
  - `$logo_url`, `$agent_photo_url`, `$agent_name`, `$agent_title`, `$agent_phone`, `$agent_email`, `$agent_license`, `$company_name`, `$company_phone`, `$company_address`, `$company_url`

- **Legal disclaimer** at bottom of CTA page

**Branding assets used:**
- Logo: `https://bmnboston.com/wp-content/uploads/2025/12/BMN-Logo-Croped.png`
- Agent photo: `https://bmnboston.com/wp-content/uploads/2025/12/Steve-Novak-600x600-1.jpg`

**Verification:**
- PDF generated successfully on production (listing 73473168 — 64 Norfolk Street)
- Logo renders on cover + all page footers
- Agent card with circular headshot renders correctly
- "Property Offered By: James Pham | RE/MAX Partners" disclosure present
- File size: 9.4MB (includes property photos + agent photo + logos)

**Files modified (1):**
1. `includes/class-flip-pdf-generator.php` — branding properties, footer logo, cover logo, CTA page, listing disclosure

**Next steps:**
1. Phase 5: iOS SwiftUI integration
2. Consider further refactoring: `class-flip-pdf-generator.php` (now 2,118 lines)
3. Consider further refactoring: `class-flip-analyzer.php` (1,335 lines)

### Session 15 - 2026-02-06

**What was done (v0.13.0 — Saved Reports & Monitor System):**

**Goal:** Analysis runs currently overwrite the dashboard view. Add saved reports so users can keep multiple analyses, and monitors that auto-analyze new listings on a schedule.

**Phase 1: Database Foundation**
- New table: `wp_bmn_flip_reports` — stores report metadata (name, type, criteria snapshots, run stats, monitor config)
- New table: `wp_bmn_flip_monitor_seen` — tracks which listings each monitor has already processed (incremental-only analysis)
- Migration: `report_id` column added to `wp_bmn_flip_scores` with index (scopes results to reports)
- Report CRUD: `create_report()`, `get_report()`, `get_reports()`, `update_report()`, `delete_report()` (soft delete)
- Report queries: `get_results_by_report()`, `get_summary_by_report()` — same aggregation as legacy methods but filtered by `report_id`
- Monitor tracking: `get_seen_listing_ids()`, `mark_listings_seen()`, `get_unseen_listing_ids()`
- 25-report cap enforcement via `count_reports()` + `MAX_REPORTS` constant
- `upsert_result()` updated: when `report_id` is present, DELETE scoped to that report_id; when NULL, scoped to `report_id IS NULL`
- `clear_old_results()` updated: adds `AND report_id IS NULL` to protect saved report data

**Phase 2: Report-Aware Analyzer**
- `Flip_Analyzer::run()` accepts `report_id` and `listing_ids` options
- When `listing_ids` provided, uses new `fetch_properties_by_ids()` instead of `fetch_properties()` (for incremental monitor runs)
- New `fetch_matching_listing_ids()` public static method — applies all 17 filters to return matching listing IDs (used by monitors)
- `force_analyze_single()` accepts optional `$report_id`
- `build_result_data()` and `store_disqualified()` propagate `report_id`

**Phase 3: AJAX Handlers + Dashboard UI**
- 6 new AJAX actions: `flip_get_reports`, `flip_load_report`, `flip_rename_report`, `flip_rerun_report`, `flip_delete_report`, `flip_create_monitor`
- Modified `ajax_run_analysis()`: prompts for report name, auto-creates report, stamps all scores with `report_id`
- Modified `ajax_force_analyze()`, `ajax_generate_pdf()`: accept `report_id` from POST
- `get_dashboard_data()` accepts optional `$report_id` — queries by `report_id` or falls back to `MAX(run_date)`
- Deleted report guard: if queried report has status='deleted', falls through to global latest data
- Report cap check before `create_report()`
- Concurrency lock via transients in `ajax_rerun_report()` (prevents parallel re-runs)
- `wp_localize_script` now includes `reports` list and `activeReportId` in `flipData`

**Phase 4: JavaScript**
- New file: `assets/js/flip-reports.js` (~397 lines) — saved reports panel, load/rerun/delete, monitor dialog, name prompt
  - `FD.reports.init()` — renders initial reports list, binds collapsible panel + all events
  - `FD.reports.renderList()` — populates `#flip-reports-list` with report cards (name, counts, date, type icon)
  - `FD.reports.loadReport()` — AJAX `flip_load_report`, replaces `FD.data`, re-renders everything
  - `FD.reports.rerunReport()` — confirm + AJAX `flip_rerun_report`, updates on success
  - `FD.reports.deleteReport()` — confirm + AJAX `flip_delete_report`, removes from list
  - `FD.reports.showReportHeader()` / `hideReportHeader()` — context bar with report name + "Back to Latest"
  - `FD.reports.promptName()` — inline name input (pre-filled "Cities - Date") before running analysis
  - `FD.reports.showMonitorDialog()` — monitor creation dialog with name + frequency + email
- `flip-core.js`: added `activeReportId: null` and `reports: {}` to namespace
- `flip-ajax.js`: `runAnalysis()` calls `FD.reports.promptName()` first; `generatePDF()`, `forceAnalyze()`, `refreshData()` send `report_id`
- `flip-init.js`: calls `FD.reports.init()` in initialization chain
- Enqueue: `flip-reports.js` registered in dependency chain; `flip-stats-chart` depends on `flip-reports`

**Phase 5: Dashboard HTML + CSS**
- Report context bar (hidden by default, shown when viewing a saved report)
- Saved Reports panel (collapsible card between action buttons and summary stats)
- Report name prompt (inline, appears when clicking Run Analysis)
- Monitor creation dialog (name, frequency, email, description)
- ~308 lines of CSS for report panel, badges, context bar, monitor styling

**Phase 6: Monitor System**
- New file: `includes/class-flip-monitor-runner.php` (~249 lines)
  - `run_all_due()` — cron entry point, iterates active monitors, checks if due
  - `run_incremental()` — fetches all matching listing_ids, subtracts seen, analyzes only new
  - Tiered notifications: DQ'd → dashboard badge only; viable → photo analysis + PDF + email
  - `send_viable_notification()` — HTML email with property table, PDF links, styled headers
  - Concurrency lock via transients (prevents parallel runs of same monitor)
- Cron: `bmn_flip_monitor_check` hook, twicedaily schedule
- Registered in both activation hook AND `plugins_loaded` migration (for file-only updates)
- Deactivation hook clears the cron schedule

**Bug fixes applied (2 rounds, 20 fixes total from prior sessions + 2 additional):**
- Report cap enforcement, concurrency locks, deleted report guard
- Report-scoped force analyze and PDF handlers
- `flip-stats-chart` dependency on `flip-reports` in enqueue chain
- Cities pass-through from report's `cities_json` in monitor runner
- NULL/non-NULL `report_id` branch in `upsert_result()`
- `clear_old_results()` protects saved report data
- CSV export: `photo_score != null` (was `!== null`, crashed on `undefined`)
- Cron scheduling in `plugins_loaded` migration block (file-only updates wouldn't schedule)

**Deployment & testing:**
- All 15 files deployed to production via SCP
- File permissions fixed (chmod 644 for CSS/JS)
- DB migration verified: reports table, monitor_seen table, report_id column all exist
- Cron verified: `bmn_flip_monitor_check` scheduled
- 11 automated tests passed on production:
  1. Dashboard data loads (84 results)
  2. Reports list (0 reports, cap=25)
  3. Report CRUD (create/read/rename/soft-delete)
  4. Deleted report fallthrough
  5. Monitor creation + seen tracking
  6. Monitor runner class loaded
  7. `fetch_matching_listing_ids` (18 IDs with all filters)
  8. `upsert_result` scoping
  9. Concurrency locks
  10. E2E: full analysis with auto-save (5 analyzed, report_id stamped)
  11. Monitor notification chain: DQ-only (no email), email delivery, photo analysis
- PDF generation returns null (TCPDF not installed — pre-existing, not v0.13.0)

**Files modified (13) + new (2):**
1. `includes/class-flip-database.php` — +370 lines: tables, migration, CRUD, report queries, monitor tracking
2. `includes/class-flip-analyzer.php` — +143 lines: report_id, listing_ids, fetch_matching_listing_ids
3. `admin/class-flip-admin-dashboard.php` — +394 lines: 6 new AJAX handlers, report-aware existing handlers
4. `includes/class-flip-monitor-runner.php` — **New** (~249 lines): cron runner, incremental analysis, notifications
5. `assets/js/flip-reports.js` — **New** (~397 lines): saved reports panel, load/rerun/delete, monitor dialog
6. `assets/js/flip-ajax.js` — +51 lines: report name prompt, report_id propagation
7. `assets/js/flip-core.js` — +6 lines: activeReportId, reports namespace
8. `assets/js/flip-init.js` — +3 lines: FD.reports.init() call
9. `admin/views/dashboard.php` — +59 lines: reports panel HTML, context bar, monitor dialog
10. `assets/css/flip-dashboard.css` — +308 lines: report panel, badges, context bar styles
11. `bmn-flip-analyzer.php` — +41 lines: require, activation hook, migration, cron registration
12. `includes/class-flip-pdf-generator.php` — +6 lines: accept optional report_id
13. `CLAUDE.md` (plugin) — updated with v0.13.0 docs
14. `.context/VERSIONS.md` — version bump
15. `CLAUDE.md` (project root) — version reference

**Total: ~1,386 insertions, 54 deletions across 15 files**

**Next steps:**
1. Install TCPDF/Composer on production for PDF generation in monitor notifications
2. Phase 5: iOS SwiftUI integration
3. Consider further refactoring: `class-flip-admin-dashboard.php` (now ~789 lines)

### Session 16 - 2026-02-07

**What was done (v0.13.1 — Reports & Monitor System Audit Fixes):**

**TCPDF Investigation:**
- Investigated TCPDF "not installed" issue from Session 15 — discovered it was a misdiagnosis
- TCPDF 6.10.1 already installed in MLS Listings Display vendor directory (since Nov 21)
- Successfully generated a 4.9MB PDF on production via WP-CLI
- Original test failure was because the listing had no analysis data, not a missing TCPDF

**Reports & Monitor System Audit:**
- Conducted comprehensive audit of saved reports and monitor system (v0.13.0)
- Identified 9 bugs across 5 files, ranging from HIGH to TRIVIAL severity

**Bug fixes (9 total):**

1. **[HIGH] Global "latest" view contaminated by monitor runs** — `get_dashboard_data(null)` used `MAX(run_date)` which could pick up monitor-created reports. Fixed: added `get_latest_manual_report()` method that queries for most recent `type='manual'` report. Dashboard now prefers manual reports for default view.

2. **[HIGH] `get_summary()` included report-scoped data** — Summary stats for the global "latest" view included scores from all reports. Fixed: added `WHERE report_id IS NULL` to scope to orphan scores only.

3. **[MEDIUM] `viable_count` inconsistency** — Counted all non-DQ properties as viable instead of using score >= 60 threshold. Fixed in both `ajax_run_analysis()` and `ajax_rerun_report()`: `WHERE total_score >= 60`.

4. **[MEDIUM] `fetch_matching_listing_ids()` skipped archive table** — When filters included Closed status, only queried active table. Fixed: added UNION query to `bme_listing_summary_archive` for Closed status.

5. **[MEDIUM] `fetch_properties_by_ids()` didn't check archive** — Properties that had since moved to archive table were silently missing. Fixed: added fallback query to archive table for any IDs not found in active table.

6. **[MEDIUM] Monitor marked listings seen BEFORE analysis** — If analysis failed or crashed, listings were already marked as seen and would never be re-analyzed. Fixed: deferred `mark_listings_seen()` for new IDs until AFTER analysis completes.

7. **[LOW] Empty reports from 0-result runs persisted** — Running analysis with 0 matching results created empty report records. Fixed: auto-delete report if 0 results after run.

8. **[LOW] Re-run with 0 results preserved stale data** — Re-running a report that now matches 0 properties kept old scores. Fixed: removed `if (!empty($result['analyzed']))` guard so old scores are always deleted on re-run.

9. **[LOW] Deleted report scores/seen data never cleaned up** — Soft-deleted reports accumulated orphaned data in scores and monitor_seen tables. Fixed: replaced dead code methods with `cleanup_deleted_report_scores()` and `cleanup_deleted_monitor_seen()`, triggered on monitor cron.

**Additional improvements:**
- Increased concurrency lock timeout from 10 to 15 minutes (both analyzer and monitor)
- Removed dead code: `stamp_scores_with_report()` and `delete_scores_for_report()`

**Files modified (5):**
1. `includes/class-flip-database.php` — `get_latest_manual_report()`, fixed `get_summary()`, cleanup methods
2. `admin/class-flip-admin-dashboard.php` — Latest manual report preference, viable_count fix, empty report cleanup, re-run fix, lock timeout
3. `includes/class-flip-analyzer.php` — Archive table UNION for Closed status, archive fallback for property lookup
4. `includes/class-flip-monitor-runner.php` — Deferred mark_listings_seen, lock timeout
5. `bmn-flip-analyzer.php` — Version bump, periodic cleanup hook on cron

**Deployment & verification:**
- All 5 files deployed via SCP
- Version verified: 0.13.1
- `get_latest_manual_report()`: correctly returns Burlington/Woburn report (ID 9)
- `get_summary()`: scoped to orphan scores (0 rows, correct — all scores belong to reports)
- Cleanup: purged 2 orphaned scores from deleted reports
- E2E test: created Stoneham report → dashboard shows it → deleted it → dashboard falls back to Wakefield
- Production state: 3 active reports (Burlington #8, Burlington/Woburn #9, Wakefield #10), 0 monitors

**Commit:** 9246486 pushed to main

**Next steps:**
1. Phase 5: iOS SwiftUI integration
2. Test monitor system end-to-end (create monitor, wait for cron, verify notifications)
3. Consider further refactoring: `class-flip-admin-dashboard.php` (now ~820 lines)
4. Consider further refactoring: `class-flip-analyzer.php` (1,335 lines)

### Session 17 - 2026-02-07

**What was done (v0.13.2 — Monitor E2E Test + Photo Analysis Fix):**

**Goal:** End-to-end test of the monitor system: create monitor → trigger cron → verify incremental analysis + notification pipeline.

**E2E Test — DQ-only path (Wakefield):**
1. Created monitor #12 for Wakefield (16 matching listings)
2. Marked all 16 as seen, then removed 3 to simulate "new arrivals"
3. Triggered `wp cron event run bmn_flip_monitor_check`
4. All 3 analyzed, all DQ'd (price too close to ARV, new construction)
5. Verified: no email sent, no photo analysis, no PDF — correct DQ-only behavior
6. Seen table restored to 16, report metadata updated (run_count=1, property_count=3)

**E2E Test — Viable path (Melrose):**
1. Created monitor #13 for Melrose with 515 Upham Street (73432608) as unseen
2. Cron analyzed it but it got DQ'd fresh (ARV exceeds 120% of ceiling — comp data changed)
3. Set score to viable manually to test the notification pipeline
4. Tested PDF generation: PASS (3.5MB PDF with branding)
5. Tested email notification: PASS (HTML email with property table + PDF download link)
6. Tested `process_viable()` via Reflection: **Found photo analysis bug**

**Bug found: Monitor photo analysis results silently discarded:**
- `process_viable()` called `Flip_Photo_Analyzer::analyze($lid)` which returned analysis results
- But the return value was never used — results weren't saved to the database
- Additionally, `analyze()` has no `report_id` parameter — even if saved, it would update wrong row

**Fix (2 files):**

1. **`class-flip-photo-analyzer.php`** — Added `analyze_and_update(int $listing_id, ?int $report_id = null)`:
   - Calls `analyze()` to get Claude Vision results
   - Finds the DB row scoped to the specified `report_id`
   - Calls `update_result_with_photo_analysis()` to save results
   - Returns analysis result with `updated` flag

2. **`class-flip-monitor-runner.php`** — Updated `process_viable()`:
   - Changed from `Flip_Photo_Analyzer::analyze($lid)` (discarded result)
   - To `Flip_Photo_Analyzer::analyze_and_update($lid, $report_id)` (saves to correct row)
   - Added error logging for failed photo analysis

**Verification after fix:**
- `analyze_and_update(73432608, 13)`: photo_score=72, level=cosmetic, $35/sqft
- DB row updated: photo_score=72.00, total_score=82.70 (75.5 + 7.2 photo bonus)
- Full pipeline test (14.3s): photo PASS + PDF PASS + email SENT

**Files modified (3):**
1. `includes/class-flip-photo-analyzer.php` — Added `analyze_and_update()` public method
2. `includes/class-flip-monitor-runner.php` — Fixed `process_viable()` to save photo results
3. `bmn-flip-analyzer.php` — Version bump to 0.13.2

**Test cleanup:**
- Deleted test monitors #12, #13 (soft-deleted + scores/seen data purged)
- Deleted 3 test PDFs
- Production restored to clean state: 3 active manual reports, 0 monitors

**Commit:** aa516c1 pushed to main

**Next steps:**
1. Phase 5: iOS SwiftUI integration
2. Consider further refactoring: `class-flip-admin-dashboard.php` (~820 lines)
3. Consider further refactoring: `class-flip-analyzer.php` (~1,335 lines)

### Session 18 - 2026-02-07

**What was done (v0.13.3 — Monitor Polish):**

**Goal:** Fix three known issues from v0.13.2 E2E testing: report-scoped photo analysis, PDF accumulation, and email PDF error handling.

**Fix 1: Report-scoped photo analysis for dashboard**
- `analyze_top_candidates()` now accepts optional `$report_id` parameter
- When `$report_id` is provided: queries `get_results_by_report()`, filters to non-DQ'd candidates with `total_score >= min_score`, uses `analyze_and_update()` for correct row targeting
- When null (CLI backward compat): keeps existing `get_results()` behavior
- `ajax_run_photo_analysis()` now passes `$report_id` from AJAX to the analyzer

**Fix 2: Monitor PDF cleanup mechanism**
- Added `Flip_PDF_Generator::cleanup_old_pdfs(int $days = 30)` static method
- Uses `glob()` on `flip-report-*.pdf` pattern, deletes files older than cutoff via `filemtime()`
- Hooked into existing `bmn_flip_monitor_check` cron callback (same pattern as DB cleanup)
- Logs deletion count when files are cleaned up

**Fix 3: Email PDF error handling**
- `process_viable()` now tracks `$pdf_failures` array alongside `$pdf_urls`
- `send_viable_notification()` accepts `$pdf_failures` parameter
- Failed PDFs show "N/A" (gray text) instead of blank cells in email table
- Footer note added when any PDFs failed: "PDF reports could not be generated for X properties"

**Files modified (5):**
1. `includes/class-flip-photo-analyzer.php` — report-scoped `analyze_top_candidates()`
2. `admin/class-flip-admin-dashboard.php` — pass `report_id` to photo analyzer
3. `includes/class-flip-pdf-generator.php` — add `cleanup_old_pdfs()` static method
4. `includes/class-flip-monitor-runner.php` — PDF failure tracking + email improvement
5. `bmn-flip-analyzer.php` — version bump + PDF cleanup in cron hook

**Deployment & verification:**
- All 5 files deployed via SCP, version verified: 0.13.3
- Method signatures confirmed via Reflection on production
- Cron scheduled with PDF cleanup included

**E2E tests (both passed):**
1. **Photo analysis report scoping:** `analyze_top_candidates(50, 40, 10)` → found 1 candidate in Report #10 (25 Juniper Ave), photo_score=72 saved to `report_id=10` row, total_score=58.08. Correctly scoped to report, not global latest.
2. **PDF cleanup:** 24 test PDFs (94.1 MB) deleted by `cleanup_old_pdfs(0)`. Directory clean. Production cron uses 30-day retention.

**Commit:** 19cadc6 pushed to main

**Next steps:**
1. Phase 5: iOS SwiftUI integration
2. Consider further refactoring: `class-flip-admin-dashboard.php` (~820 lines)
3. Consider further refactoring: `class-flip-analyzer.php` (~1,335 lines)

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
│   ├── class-flip-monitor-runner.php   # Cron-based monitor system (v0.13.0)
│   ├── class-flip-photo-analyzer.php  # Claude Vision API
│   ├── class-flip-rest-api.php        # REST API endpoints
│   └── class-flip-road-analyzer.php   # OSM road type detection
├── admin/                          # Admin dashboard (v0.4.0)
│   ├── class-flip-admin-dashboard.php  # Menu, AJAX handlers, data
│   └── views/
│       └── dashboard.php               # HTML template
└── assets/
    ├── css/
    │   └── flip-dashboard.css              # Dashboard styles
    └── js/                                 # Modular dashboard JS (v0.12.0)
        ├── flip-core.js                    # Namespace + shared state
        ├── flip-helpers.js                 # Utility functions
        ├── flip-stats-chart.js             # Stats cards + Chart.js
        ├── flip-filters-table.js           # Client-side filters + table
        ├── flip-detail-row.js              # Expanded detail row builders
        ├── flip-projections.js             # ARV projection calculator
        ├── flip-ajax.js                    # AJAX operations + CSV export
        ├── flip-analysis-filters.js        # Pre-analysis filters panel
        ├── flip-cities.js                  # City tag management
        ├── flip-reports.js                 # Saved reports + monitors (v0.13.0)
        └── flip-init.js                    # Init + event binding (loaded last)
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

### v0.4.0 (Complete)
- Web admin dashboard under Flip Analyzer menu
- Chart.js grouped bar chart (viable vs disqualified per city)
- Results table with expandable detail rows (scores, financials, comps, photos)
- Run Analysis button with AJAX progress modal
- Export CSV with all scored property data
- Client-side filters: city, min score, sort, show mode

### v0.4.1 (Complete)
- Dashboard: "Run Photo Analysis" button with cost estimate confirmation
- Dashboard: Days on Market (DOM) column
- Dashboard: "View" link to property detail page per property
- Floor/Mid/Ceiling valuation range in expanded detail rows
- Road type ARV discount: -15% busy road, -25% highway-adjacent
- Fixed road type misclassification (highway significance ranking in OSM parser)
- Filtered rental properties (`property_type = 'Residential'` filter)
- Added `days_on_market` column to DB schema

### v0.5.0 (Complete)
- **Accuracy Overhaul** — 4 major improvements to financial calculations:
  1. Age-scaled rehab costs: $15/sqft (0-10yr), $30 (11-25yr), $45 (26-50yr), $60 (50+yr)
  2. Comp deduplication by address (removes pre-flip sales polluting ARV)
  3. Renovation-weighted ARV: renovated comps get 2x weight, new construction 1.5x
  4. Broken-down costs: purchase closing (1.5%), sale commission (5%), seller closing (1%), holding costs (0.8%/mo × 6 months)
- Wider comp criteria: ±30% sqft, 12-month lookback, expanding radius 0.5→1.0→2.0mi
- Distance-weighted comp average (1/distance² formula)
- ARV projection calculator with 3 presets + custom editable row
- Confidence-based valuation range: ±10% (high), ±15% (medium), ±20% (low)
- "Max Offer Price" replaces "MAO" label for clarity

### v0.6.0 (Complete)
- **ARV Accuracy & Financial Model Overhaul** — 13 major improvements:
  1. Bathroom filter on comps (±1.0 range) with graceful fallback
  2. Appraisal-style comp adjustments (market-scaled: beds, baths, sqft, garage, basement)
  3. Time-decay comp weighting (half-weight at 6 months via exponential decay)
  4. Sale-to-list ratio and market strength signal (very_hot to cold)
  5. Multi-factor ARV confidence score (count + distance + recency + variance)
  6. Dual financial model: cash purchase AND hard money (12%, 2 pts, 80% LTV)
  7. Remarks-based rehab multiplier (0.5x-1.5x from keyword analysis)
  8. Dynamic hold period (rehab scope + area DOM + permit buffer)
  9. 10% rehab contingency + realistic holding costs (tax + insurance + utilities)
  10. Minimum profit ($25K) and ROI (15%) post-calculation disqualifiers
  11. Shared `calculate_financials()` method (fixes photo analyzer formula divergence)
  12. Dashboard updates: dual scenarios, comp adjustments, market badges, $250/sqft additions
  13. DB migration: 10 new columns for financing/holding/market data
- Fixed `date('Y')` → `wp_date('Y')` timezone pitfall in ARV calculator and property scorer

### v0.7.0 (Complete)
- **Market-Adaptive Thresholds** — fixes all 61 properties being DQ'd in hot MA markets:
  1. Continuous formula `clamp(2.5 - 1.5 × avg_sale_to_list, 0.4, 1.2)` with tier guard rails
  2. Tier bounds: very_hot ($10-20K, 5-8%), hot ($15-25K, 8-12%), balanced ($25K, 15%), soft ($25-30K, 15-18%), cold ($28-35K, 16-22%)
  3. Market-adaptive price/ARV ratio for pre-calc DQ (0.78 cold → 0.92 very_hot)
  4. Near-viable category: properties within 80% of adjusted thresholds (amber on dashboard)
  5. Low-confidence guard: don't relax below balanced when ARV confidence is low/none
  6. Dashboard: near-viable stat card, filter option, amber row styling, threshold display
  7. DB migration: `near_viable` and `applied_thresholds_json` columns
- `get_adaptive_thresholds()` public static method on Flip_Analyzer

### v0.8.0 (Complete)
- **ARV & Financial Model Overhaul** — comprehensive accuracy and risk analysis improvements:
  1. ARV accuracy: sqft threshold 10%→5%, P90 ceiling (was MAX), reno weight 2.0→1.3
  2. Smooth continuous rehab cost formula (eliminates step-function discontinuities)
  3. MA transfer tax (0.456% buy+sell), lead paint flag ($8K for pre-1978)
  4. Scaled contingency by rehab scope (8% cosmetic → 20% major)
  5. Updated constants: hard money 10.5% (was 12%), commission 4.5% (was 5%)
  6. Actual property tax rates from MLS data (was flat 1.3%)
  7. Annualized ROI, breakeven ARV, adjusted MAO (incl. holding+financing)
  8. Deal risk grade (A-F) combining ARV confidence, margin, comp consistency, velocity
  9. Location scoring rebalance: removed tax rate (double-count), boosted price trend/ceiling
  10. Dashboard: sensitivity table, risk grade column, annualized ROI, lead paint badge
  11. REST API: 6 new fields, updated sort options
  12. DB migration: 6 new columns

### v0.10.0 (Complete)
- **Pre-Analysis Filters + Force Analyze + PDF v2**:
  1. 17 configurable pre-analysis filters stored as WP option
  2. Collapsible "Analysis Filters" panel on dashboard with save/reset
  3. CLI filter override flags (14 flags, don't persist)
  4. Force Full Analysis button on DQ'd rows (bypasses all DQ checks)
  5. PDF report v2: photo thumbnails, circular gauge, charts, comp cards

### v0.11.0 (Complete)
- **Renovation Potential Guard**:
  1. New construction auto-DQ: properties ≤5 years old disqualified unless distress signals
  2. Inverted year-built scoring → "renovation need" score (sweet spot 41-70 years)
  3. Age-based rehab condition multiplier: 0.10x (≤5yr) to 1.0x (21+yr)
  4. Enhanced remarks: weighted keywords (2-5 pts), cap ±25, new condition terms
  5. Property condition from bme_listing_details as supplementary DQ signal

### v0.11.1 (Complete)
- **Logic Audit Fixes** — 11 loopholes patched across all scoring/calculation files
  - ARV confidence discount, pre-DQ rehab multipliers, scope-based contingency/hold period
  - Market strength data guard, distress detection hardening, post-multiplier rehab floor
  - Distressed comp downweighting, expansion potential cap for condos

### v0.12.0 (Complete)
- **Dashboard JS Modular Refactor** — split `flip-dashboard.js` (1,565 lines) into 10 focused modules:
  1. `flip-core.js` — namespace `window.FlipDashboard` + shared state
  2. `flip-helpers.js` — 6 utility functions (formatCurrency, scoreClass, etc.)
  3. `flip-stats-chart.js` — stats cards, Chart.js chart, city filter
  4. `flip-filters-table.js` — client-side filters, table rendering, row toggle
  5. `flip-detail-row.js` — expanded detail row builders (scores, financials, comps, photos)
  6. `flip-projections.js` — ARV projection calculator + live updates
  7. `flip-ajax.js` — AJAX operations (run analysis, PDF, force analyze, CSV export)
  8. `flip-analysis-filters.js` — pre-analysis filters panel (save/reset)
  9. `flip-cities.js` — city tag management (add/remove/save)
  10. `flip-init.js` — initialization + event binding (loaded last)
- Bug fix: `applyFilters()` now delegates to `getFilteredResults()` (was duplicated filter+sort logic)
- PHP: `enqueue_assets()` rewritten with 10-file dependency chain, `wp_localize_script` target updated
- Deleted old `flip-dashboard.js`
- **PDF Report Branding:**
  - BMN Boston logo on cover page hero overlay + every page footer
  - Branded footer with company name, agent contact, website
  - Call to Action final page with agent card, headshot, CTA buttons, company info
  - "Property Offered By" disclosure with listing agent name + brokerage
  - Legal disclaimer

### v0.13.0 (Complete)
- **Saved Reports & Monitor System** — auto-save every analysis run as a named report:
  1. Report name prompt before each analysis run (pre-filled "Cities - Date")
  2. Saved Reports panel: collapsible card with load/rename/rerun/delete actions
  3. Report context bar: blue header showing active report name + "Back to Latest"
  4. Re-run reports with fresh MLS data using original criteria (replaces old results)
  5. Monitor system: saved searches that auto-analyze only NEW listings via wp_cron
  6. Tiered notifications: DQ'd → dashboard badge; viable → photo + PDF + email
  7. New DB tables: `wp_bmn_flip_reports`, `wp_bmn_flip_monitor_seen`
  8. New DB column: `report_id` on `wp_bmn_flip_scores`
  9. New JS module: `flip-reports.js` (FlipDashboard.reports namespace)
  10. New PHP class: `Flip_Monitor_Runner` with cron integration
  11. All AJAX handlers (run, refresh, PDF, force-analyze) accept `report_id`
  12. 25-report cap with soft delete, concurrency locks, incremental analysis

### v0.13.1 (Complete)
- **Reports & Monitor System Audit Fixes** — 9 bugs fixed across 5 files:
  1. Global "latest" view now prefers latest manual report (monitor runs no longer contaminate)
  2. `get_summary()` scoped to orphan scores only (excludes report-scoped data)
  3. `viable_count` uses `total_score >= 60` threshold (was counting all non-DQ as viable)
  4. `fetch_matching_listing_ids()` and `fetch_properties_by_ids()` now query archive table for Closed status
  5. Monitor marks new listings as "seen" AFTER analysis (was before, causing data loss on failure)
  6. Empty reports (0 results) auto-deleted instead of persisting as clutter
  7. Re-run with 0 results now clears stale data (was preserving old scores)
  8. Concurrency lock increased from 10 to 15 minutes
  9. Periodic cleanup of scores/seen data for deleted reports (runs on monitor cron)
- Removed dead code: `stamp_scores_with_report()`, `delete_scores_for_report()`

### v0.13.2 (Complete)
- **Monitor Photo Analysis Fix + E2E Test** — fixed photo analysis results being silently discarded:
  1. `process_viable()` now saves photo analysis results to the correct report-scoped DB row
  2. New `Flip_Photo_Analyzer::analyze_and_update()` public method with `report_id` support
  3. Full E2E test verified: monitor creation → cron trigger → incremental analysis → tiered notifications
  4. DQ-only path verified: no email/photo/PDF when all properties disqualified
  5. Viable path verified: photo analysis (Claude Vision) + PDF generation + email notification

### v0.13.3 (Complete)
- **Monitor Polish** — three known issues from v0.13.2 E2E testing:
  1. Dashboard "Run Photo Analysis" now scopes to active report (was querying global latest run)
  2. `analyze_top_candidates()` accepts optional `report_id`, uses `analyze_and_update()` for correct row targeting
  3. PDF cleanup mechanism: `cleanup_old_pdfs(30)` deletes reports older than 30 days via cron
  4. Monitor email shows "N/A" for failed PDFs instead of blank cells
  5. Monitor email includes footer note when PDF generation fails

### v0.14.0 (Planned)
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
