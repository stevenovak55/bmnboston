# BMN Flip Analyzer - Development Log

## Current Version: 0.8.0

## Status: Phase 3.8 Complete (ARV & Financial Model Overhaul) - Pre-Phase 4 (iOS)

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
├── admin/                          # Admin dashboard (v0.4.0)
│   ├── class-flip-admin-dashboard.php  # Menu, AJAX handlers, data
│   └── views/
│       └── dashboard.php               # HTML template
└── assets/
    ├── css/
    │   └── flip-dashboard.css          # Dashboard styles
    └── js/
        └── flip-dashboard.js           # Chart.js, table, filters, export
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

### v0.9.0 (Planned)
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
