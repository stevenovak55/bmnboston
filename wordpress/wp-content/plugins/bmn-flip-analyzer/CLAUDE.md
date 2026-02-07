# BMN Flip Analyzer - Claude Code Reference

**Current Version:** 0.13.1
**Last Updated:** 2026-02-07

## Overview

Standalone WordPress plugin that identifies Single Family Residence flip candidates by scoring properties across financial viability (40%), property attributes (25%), location quality (25%), and market timing (10%). Uses a two-pass approach: data scoring first, then Claude Vision photo analysis on top candidates.

**v0.13.0 Enhancements (Saved Reports & Monitor System):**
- Auto-save every analysis run as a named report (prompted before running, default "Cities - Date")
- Saved Reports panel on dashboard: collapsible list of all reports with load/rename/rerun/delete
- Report context bar: blue header showing active report name + "Back to Latest" button
- Re-run reports with fresh MLS data using original criteria (replaces old results, preserves report identity)
- Monitor system: saved searches that auto-analyze only NEW listings matching criteria via wp_cron
- Tiered monitor notifications: DQ'd properties get dashboard badge only; viable properties get badge + email + auto photo analysis + auto PDF generation
- Cron integration: `bmn_flip_monitor_check` hook, twicedaily default, configurable per-monitor (daily/twice_daily/weekly)
- New DB tables: `wp_bmn_flip_reports` (report metadata), `wp_bmn_flip_monitor_seen` (incremental tracking)
- New DB column: `report_id` on `wp_bmn_flip_scores` (scopes results to specific reports)
- New JS module: `flip-reports.js` in `FlipDashboard.reports` namespace
- All AJAX handlers (run, refresh, PDF, force-analyze) now accept and propagate `report_id`
- `Flip_Analyzer::run()` accepts `report_id` and `listing_ids` for report-scoped and incremental runs
- `Flip_Analyzer::fetch_matching_listing_ids()` for monitor new-listing detection
- 25-report cap with soft delete for archived reports
- New file: `class-flip-monitor-runner.php` — cron-based incremental analysis with tiered notifications

**v0.12.0 Enhancements (Dashboard JS Modular Refactor + PDF Branding):**
- Split monolithic `flip-dashboard.js` (1,565 lines) into 10 focused modules
- Module files: `flip-core.js`, `flip-helpers.js`, `flip-stats-chart.js`, `flip-filters-table.js`, `flip-detail-row.js`, `flip-projections.js`, `flip-ajax.js`, `flip-analysis-filters.js`, `flip-cities.js`, `flip-init.js`
- Namespace pattern: `window.FlipDashboard` with sub-objects (`FD.helpers`, `FD.stats`, `FD.filters`, etc.)
- WordPress `wp_enqueue_script` dependency chain (no bundler needed)
- Bug fix: `applyFilters()` now delegates to `getFilteredResults()` (was duplicated filter+sort logic)
- Deleted old `flip-dashboard.js`
- **PDF report branding:** BMN Boston logo on cover page hero + every page footer
- **PDF branded footer:** company name, Steve Novak contact, website on every page
- **PDF Call to Action page:** agent card with circular headshot, CTA buttons, company info
- **PDF listing disclosure:** "Property Offered By" with listing agent name + brokerage (no contact info)
- Agent/brokerage data via JOIN: `bme_listings` → `bme_agents` + `bme_offices`

**v0.11.1 Fixes (Logic Audit — 11 loopholes):**
- ARV confidence discount: low/none confidence requires 25-50% higher profit/ROI to pass post-calc DQ
- Pre-DQ rehab estimate includes age condition + remarks multipliers (was base $/sqft only)
- Missing $/sqft score: penalized at 30 instead of neutral 50
- Contingency rate uses scope of work (base × remarks_mult), not age-discounted effective $/sqft
- Hold period uses scope-based rehab (age discount reduces cost, not work timeline)
- Market strength capped at balanced when < 3 sale-to-list data points available
- Distress detection: word-boundary matching, 120-char context window, 16 negation phrases
- Post-multiplier rehab floor: $2/sqft minimum after all multipliers applied
- Distressed comp downweighting: foreclosure/short-sale comps get 0.3x influence weight in ARV
- Expansion potential capped at 40 for condos/townhouses (cannot expand on shared lots)
- Ceiling type mixed flag tracks when fallback uses all property types

**v0.11.0 Enhancements (Renovation Potential Guard):**
- New construction auto-DQ: properties ≤5 years old disqualified unless distress signals (foreclosure, as-is, etc.)
- Property condition field from `bme_listing_details` used as supplementary DQ signal
- Inverted year-built scoring → "renovation need" score: older properties score higher (sweet spot 41-70 years)
- Age-based rehab condition multiplier: 0.10x for age ≤5, scales to 1.0x for age 21+
- Lowered rehab floor from $12/sqft to $5/sqft for realistic near-zero estimates on near-new properties
- Enhanced remarks: weighted keywords (strongest signals get 5 pts), cap increased ±15→±25, new keywords
- New DB column: `age_condition_multiplier`
- Dashboard: age condition display in rehab note, NEW badge on construction DQ reasons

**v0.10.0 Enhancements (Pre-Analysis Filters + Force Analyze + PDF v2):**
- Configurable pre-analysis filters: 17 filters applied at SQL level before analysis runs
- Filter schema stored as WP option `bmn_flip_analysis_filters` (JSON), persistent across sessions
- Filters: property sub type (dynamic multi-select), status (Active/Under Contract/Pending/Closed), price range, sqft range, year built range, DOM range, list date range, min beds, min baths, min lot acres, public sewer only (JOIN to bme_listing_details), has garage
- Closed status queries `bme_listing_summary_archive` via UNION
- Collapsible "Analysis Filters" panel on dashboard with save/reset
- CLI filter override flags: `--property-type`, `--status`, `--sewer-public-only`, `--min-dom`, `--max-dom`, `--list-date-from`, `--list-date-to`, `--year-min`, `--year-max`, `--min-price`, `--max-price`, `--min-sqft`, `--min-beds`, `--min-baths`
- Force Analyze button on DQ'd property rows: bypasses all disqualification checks, runs full pipeline
- `force_analyze_single()` public method on Flip_Analyzer
- PDF report v2: photo thumbnail strip, circular score gauge, stacked bar chart, sensitivity line chart, comp photo cards

**v0.8.0 Enhancements (ARV & Financial Model Overhaul):**
- ARV accuracy: sqft adjustment threshold 10%→5%, P90 neighborhood ceiling (was MAX), reno weight 2.0→1.3
- Smooth continuous rehab cost formula (replaces step-function with discontinuities)
- MA transfer tax (0.456% buy+sell deed excise tax)
- Lead paint flag ($8K allowance for pre-1978 properties)
- Scaled contingency by rehab scope (8% cosmetic → 20% major)
- Updated constants: hard money 10.5% (was 12%), commission 4.5% (was 5%)
- Actual property tax rates from MLS data (was flat 1.3%)
- Annualized ROI, breakeven ARV, adjusted MAO (incl. holding+financing)
- Deal risk grade (A-F) combining ARV confidence, margin, comp consistency, velocity
- Location scoring rebalance: removed tax rate (double-count), boosted price trend/ceiling
- Dashboard: sensitivity table, risk grade column, annualized ROI, lead paint badge
- DB migration: 6 new columns

**v0.7.0 Enhancements (Market-Adaptive Thresholds):**
- Market-adaptive thresholds: continuous formula `clamp(2.5 - 1.5 × avg_sale_to_list, 0.4, 1.2)` with tier guard rails
- Thresholds scale by market_strength: very_hot ($10-20K profit, 5-8% ROI) → cold ($28-35K, 16-22%)
- Pre-calc DQ uses market-adaptive price/ARV ratio (0.78 cold → 0.92 very_hot)
- Near-viable category: properties within 80% of adjusted thresholds (amber on dashboard)
- Low-confidence guard: ARV confidence low/none prevents threshold relaxation below balanced
- Dashboard: near-viable stat card, filter dropdown, amber row styling, threshold display in expanded rows
- DB migration: `near_viable` and `applied_thresholds_json` columns

**v0.6.0 Enhancements (ARV Accuracy & Financial Model Overhaul):**
- Bathroom filter on comps (±1.0 range) with graceful fallback when too few comps
- Appraisal-style comp adjustments: market-scaled values for beds, baths, sqft, garage, basement
- Time-decay comp weighting: exponential decay (half-weight at 6 months)
- Sale-to-list ratio: market strength signal (very_hot ≥1.04/hot ≥1.01/balanced ≥0.97/soft ≥0.93/cold)
- Multi-factor ARV confidence: comp count + avg distance + avg recency + price variance
- Dual financial model: cash purchase AND hard money (12% rate, 2 pts, 80% LTV)
- Remarks-based rehab multiplier (0.5x-1.5x): "new roof" reduces, "needs work" increases
- Dynamic hold period: rehab scope (1-6 mo) + area avg DOM + permit buffer
- 10% rehab contingency, realistic holding costs (tax 1.3% + insurance 0.5% + $350/mo utilities)
- Minimum profit ($25K) and ROI (15%) post-calculation disqualifiers
- Shared `calculate_financials()` method eliminates photo analyzer formula divergence
- Dashboard: dual profit scenarios, comp adjustments with hover details, market strength badges
- Projection calculator: $250/sqft MA addition cost, financing model
- CSV export: 10 new financial/market columns

**v0.5.0 Enhancements (Accuracy Overhaul):**
- Age-scaled rehab costs: $15-60/sqft based on property age (replaces flat $30)
- Distance + renovation weighted ARV: renovated comps get 2x influence
- Comp deduplication: removes pre-flip sales that pollute ARV
- Broken-down costs: purchase closing, sale commission, holding costs (replaces opaque 12%)
- Road type ARV discount: -15% busy road, -25% highway-adjacent
- ARV projection calculator: interactive scenarios with custom bed/bath/sqft inputs
- Dashboard enhancements: photo analysis button, DOM column, property View links, floor/mid/ceiling range

## Architecture

- **Standalone plugin** — separate from MLS Listings Display
- **Two-pass workflow** — Pass 1: data scoring (no API calls), Pass 2: photo analysis (top 50 only)
- **Configurable target cities** — stored in WP option `bmn_flip_target_cities`
- **Configurable analysis filters** — stored in WP option `bmn_flip_analysis_filters` (JSON)
- **Results table** — `wp_bmn_flip_scores` stores all analysis data, scoped by `report_id` (v0.13.0)
- **Saved reports** — every analysis run auto-saved as a named report in `wp_bmn_flip_reports`
- **Monitors** — saved search reports that auto-analyze new listings via `wp_cron` (twicedaily)
- **WP-CLI interface** — `wp flip analyze`, `wp flip results`, etc.

## Key Files

| File | Purpose |
|------|---------|
| `bmn-flip-analyzer.php` | Main plugin file, hooks, activation |
| `includes/class-flip-analyzer.php` | Orchestrator — runs the full pipeline |
| `includes/class-flip-arv-calculator.php` | ARV from sold comps + neighborhood ceiling |
| `includes/class-flip-financial-scorer.php` | Financial scoring (40% weight) |
| `includes/class-flip-property-scorer.php` | Lot size & expansion potential (25%) |
| `includes/class-flip-location-scorer.php` | Location + road type scoring (25%) |
| `includes/class-flip-road-analyzer.php` | OSM Overpass API for road classification |
| `includes/class-flip-market-scorer.php` | Market timing + remarks analysis (10%) |
| `includes/class-flip-cli.php` | WP-CLI commands |
| `includes/class-flip-database.php` | Table creation, queries, CRUD |
| `includes/class-flip-pdf-generator.php` | TCPDF-based investor-grade PDF report with branding |
| `includes/class-flip-rest-api.php` | REST API endpoints (6 endpoints) |
| `includes/class-flip-photo-analyzer.php` | Claude Vision photo analysis |
| `includes/class-flip-monitor-runner.php` | Cron-based incremental monitor analysis |
| `admin/class-flip-admin-dashboard.php` | Admin page, AJAX handlers, report management |
| `admin/views/dashboard.php` | Dashboard HTML template |
| `assets/js/flip-core.js` | Namespace + shared state (`window.FlipDashboard`) |
| `assets/js/flip-helpers.js` | Utility functions (formatCurrency, scoreClass, etc.) |
| `assets/js/flip-stats-chart.js` | Stats cards, Chart.js chart, city filter |
| `assets/js/flip-filters-table.js` | Client-side filters, table rendering, row toggle |
| `assets/js/flip-detail-row.js` | Expanded detail row builders (scores, financials, comps, photos) |
| `assets/js/flip-projections.js` | ARV projection calculator + live updates |
| `assets/js/flip-ajax.js` | AJAX operations (run analysis, PDF, force analyze, CSV export) |
| `assets/js/flip-analysis-filters.js` | Pre-analysis filters panel (save/reset) |
| `assets/js/flip-cities.js` | City tag management (add/remove/save) |
| `assets/js/flip-reports.js` | Saved reports panel, load/rerun/delete, monitor dialog |
| `assets/js/flip-init.js` | Initialization + event binding (loaded last) |
| `assets/css/flip-dashboard.css` | Dashboard styles |

## Database

**Table:** `wp_bmn_flip_scores`
- Scores: `total_score`, `financial_score`, `property_score`, `location_score`, `market_score`, `photo_score`
- Financials: `estimated_arv`, `arv_confidence`, `comp_count`, `estimated_rehab_cost`, `mao`, `estimated_profit`, `estimated_roi`
- Financing (v0.6.0): `financing_costs`, `holding_costs`, `rehab_contingency`, `hold_months`, `cash_profit`, `cash_roi`, `cash_on_cash_roi`, `rehab_multiplier`, `age_condition_multiplier` (v0.11.0)
- Market (v0.6.0): `market_strength`, `avg_sale_to_list`
- Neighborhood: `neighborhood_ceiling`, `ceiling_pct`, `ceiling_warning`, `road_type`
- Property snapshot: `list_price`, `address`, `city`, `bedrooms_total`, etc.
- JSON fields: `comp_details_json`, `remarks_signals_json`, `photo_analysis_json`
- Flags: `disqualified`, `disqualify_reason`, `near_viable` (v0.7.0), `lead_paint_flag` (v0.8.0)
- Risk analysis (v0.8.0): `annualized_roi`, `breakeven_arv`, `deal_risk_grade`, `transfer_tax_buy`, `transfer_tax_sell`
- Thresholds: `applied_thresholds_json` (v0.7.0)
- Report link: `report_id` (v0.13.0) — foreign key to `wp_bmn_flip_reports`

**Table:** `wp_bmn_flip_reports` (v0.13.0)
- Report metadata: `id`, `name`, `type` (manual/monitor), `status` (active/archived/deleted)
- Criteria snapshot: `cities_json`, `filters_json` — frozen at report creation
- Run tracking: `run_date`, `last_run_date`, `run_count`, `property_count`, `viable_count`
- Monitor config: `monitor_frequency` (daily/twice_daily/weekly), `monitor_last_check`, `monitor_last_new_count`, `notification_email`
- Audit: `created_at`, `updated_at`, `created_by`

**Table:** `wp_bmn_flip_monitor_seen` (v0.13.0)
- Tracks which listings each monitor has already processed
- Columns: `id`, `report_id`, `listing_id`, `first_seen_at`
- Unique key on `(report_id, listing_id)` for incremental-only analysis

**Source tables (from BME/MLD):**
- `bme_listing_summary` — active listings (filtered by analysis filters)
- `bme_listing_summary_archive` — sold comps for ARV + Closed status listings when selected
- `bme_listing_details` — public remarks + sewer data (JOIN when sewer filter active)
- `bme_listing_financial` — property taxes
- `bme_media` — property photos (Pass 2)

**WP Options:**
- `bmn_flip_target_cities` — JSON array of city names
- `bmn_flip_analysis_filters` — JSON object with 17 filter fields (see Filter Schema below)

## WP-CLI Commands

```bash
wp flip analyze [--limit=500] [--city=Reading,Melrose] [--property-type=Single Family Residence,Condominium]
    [--status=Active,Pending] [--sewer-public-only] [--min-dom=30] [--max-dom=180]
    [--list-date-from=2025-07-01] [--list-date-to=2025-08-01] [--year-min=1950] [--year-max=2000]
    [--min-price=200000] [--max-price=600000] [--min-sqft=800] [--min-beds=2] [--min-baths=1]
wp flip results [--top=20] [--min-score=60] [--city=Reading] [--sort=total_score] [--format=table|json|csv]
wp flip property <listing_id> [--verbose]
wp flip summary
wp flip config --list-cities | --add-city=Woburn | --remove-city=Burlington
wp flip clear [--older-than=30] [--all] [--yes]
wp flip analyze_photos [--top=50] [--min-score=40]  # Pass 2: Claude Vision photo analysis
```

**Note:** CLI flags override saved filters for that run only (don't persist).

## Scoring Weights

| Category | Weight | Sub-factors |
|----------|--------|-------------|
| Financial | 40% | Price/ARV ratio (37.5%), $/sqft vs neighborhood (25%), price reduction (25%), DOM motivation (12.5%) |
| Property | 25% | Lot size (35%), expansion potential (30%), existing sqft (20%), renovation need (15%) |
| Location | 25% | Road type (25%), ceiling support (25%), price trend (25%), comp density (15%), schools (10%) |
| Market | 10% | Listing DOM (40%), price reduction (30%), season (30%) + remarks bonus (±15) |

## Road Type Detection

Uses OpenStreetMap Overpass API to classify roads by highway tag:

| OSM Highway | Road Type | Score |
|-------------|-----------|-------|
| living_street | cul-de-sac | 100 |
| residential | quiet-residential | 85 |
| tertiary | moderate-traffic | 60 |
| secondary | busy-road | 25 |
| primary, trunk | highway-adjacent | 10 |

**Query method:** First tries street-name-based query for accuracy, falls back to coordinate-based nearest road.

## Auto-Disqualifiers

**Pre-calculation:**
- 0 comps within 1 mile
- **Recent construction (v0.11.0):** age ≤5 years auto-DQ unless distress keywords in remarks or property_condition indicates poor
- **Pristine condition (v0.11.0):** property_condition "New Construction"/"Excellent" on properties <15 years old
- `list_price > ARV * max_price_arv` (market-adaptive: 0.78 cold → 0.92 very_hot)
- Default rehab estimate > 35% of ARV
- `building_area_total < 600` sqft

**Post-calculation (v0.7.0 — market-adaptive):**
- Financed profit < min_profit (market-adaptive: $10K very_hot → $35K cold; base $25K)
- Financed ROI < min_roi (market-adaptive: 5% very_hot → 22% cold; base 15%)
- Thresholds computed via `get_adaptive_thresholds(market_strength, avg_sale_to_list, arv_confidence)`
- Low-confidence guard: ARV confidence low/none uses balanced bounds as floor

**Near-viable (v0.7.0):**
- Properties within 80% of adjusted min_profit AND min_roi thresholds
- Stored as `near_viable` flag, shown in amber on dashboard

## Important Patterns

### ARV Calculation (v0.6.0)
- Comps from `bme_listing_summary_archive` (sold SFR within 12 months)
- ±1 bedroom, ±1 bathroom, ±30% sqft, expanding radius: 0.5mi → 1.0mi → 2.0mi
- Bathroom filter with fallback: if <3 comps with bath filter, re-run without it
- **Appraisal-style adjustments** scale with city avg $/sqft:
  - Bedroom: ppsf×40, Full bath: ppsf×55, Half bath: ppsf×25
  - Sqft: ppsf×0.5/sqft (capped ±15%), Garage: ppsf×40, Basement: ppsf×28
  - Total adjustment capped at ±25% of comp price
- **Time-decay weighting**: `exp(-0.115 × months_ago)` (half-weight at 6 months)
- **Time-adjusted prices**: `adjusted_ppsf × (1 + monthly_rate × months_ago)`
- Combined weight: `reno_mult × time_weight / (distance + 0.1)²`
- Comps deduped by address (keeps most recent sale, removes pre-flip purchases)
- Road type discount: -15% busy road, -25% highway-adjacent
- **Sale-to-list ratio**: very_hot (≥1.04), hot (≥1.01), balanced (≥0.97), soft (≥0.93), cold (<0.93)
- **Multi-factor confidence**: comp count (0-40) + avg distance (0-30) + avg recency (0-20) + price variance CV (0-10)

### School Rating
- Uses internal REST API call to `/bmn-schools/v1/property/schools`
- Caches by rounded lat/lng (3 decimal places)

### Financial Calculations (v0.6.0)
```
Rehab = sqft × age-based $/sqft × remarks_multiplier (0.5x-1.5x)
Contingency = rehab × 8-20% (scaled by scope)
Total Rehab = rehab + contingency
Purchase Closing = list_price × 1.5%
Sale Costs = ARV × 5.5% (4.5% commission + 1% closing) + transfer tax
Hold Months = dynamic (1-8 months based on rehab scope + area avg DOM + permit buffer)
Holding Costs = (monthly_tax + monthly_insurance + $350 utilities) × hold_months
  Monthly Tax = list_price × 1.3% / 12
  Monthly Insurance = list_price × 0.5% / 12
MAO (Max Offer Price) = (ARV × 0.70) - Total Rehab

Cash Scenario:
  Cash Profit = ARV - list_price - total_rehab - purchase_closing - sale_costs - holding_costs
  Cash ROI = cash_profit / (list_price + total_rehab + purchase_closing + holding_costs) × 100

Hard Money Scenario (10.5% rate, 2 points, 80% LTV):
  Loan Amount = list_price × 80%
  Financing Costs = (loan × 2%) + (loan × 10.5% / 12 × hold_months)
  Financed Profit = cash_profit - financing_costs
  Cash Invested = (list_price × 20%) + total_rehab + purchase_closing
  Cash-on-Cash ROI = financed_profit / cash_invested × 100
```

**Shared method:** `Flip_Analyzer::calculate_financials()` used by both main pipeline and photo analyzer.

## Dependencies

- **WordPress** with `bme_listing_summary` tables (from Bridge MLS Extractor)
- **BMN Schools plugin** — for school rating lookups
- **Claude API key** — stored in `mld_claude_api_key` option (for Pass 2 photo analysis)

## Version Bumping

Update in `bmn-flip-analyzer.php`:
1. Plugin header `Version:` comment
2. `FLIP_VERSION` constant

## REST API Endpoints

**Namespace:** `bmn-flip/v1`

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/results` | GET | JWT | List scored properties (paginated) |
| `/results/{listing_id}` | GET | JWT | Single property details |
| `/results/{listing_id}/comps` | GET | JWT | Comparables used for ARV |
| `/summary` | GET | JWT | Per-city summary stats |
| `/config/cities` | GET | JWT | Get target cities |
| `/config/cities` | POST | Admin | Update target cities |

**Query Params for `/results`:**
- `per_page` (default: 20, max: 100)
- `page` (default: 1)
- `min_score` (default: 0)
- `city` (comma-separated)
- `sort` (total_score, estimated_profit, estimated_roi, cash_on_cash_roi, list_price, estimated_arv, annualized_roi, deal_risk_grade)
- `order` (ASC, DESC)
- `has_photos` (boolean)

## Photo Analysis

Uses Claude Vision API (`claude-sonnet-4-5-20250929`) to analyze up to 5 photos per property.

**Output includes:**
- `overall_condition` (1-10)
- `renovation_level` (cosmetic/moderate/major/gut)
- `estimated_cost_per_sqft` ($15-100)
- Condition scores for kitchen, bathroom, flooring, exterior, curb appeal
- Structural concerns and water damage detection
- `flip_photo_score` (0-100)

**Cost:** ~$0.03-0.05 per property (5 photos)

## Development Phases

| Phase | Status | Description |
|-------|--------|-------------|
| 1 - Backend Core | Complete | Plugin scaffold, ARV, scorers, CLI |
| 2 - Photo + API | Complete | Claude Vision, REST endpoints |
| 2.5 - Enhanced Location | Complete | Road type, ceiling check, expansion scoring |
| 3 - Web Dashboard | Complete | Admin page with Chart.js, filters, CSV export |
| 3.5 - ARV & Financial Overhaul | Complete | Comp adjustments, financing model, market signals |
| 3.7 - Market-Adaptive Thresholds | Complete | Adaptive DQ thresholds, near-viable category |
| 3.8 - ARV & Financial Overhaul | Complete | ARV fixes, financial model v2, risk analysis |
| 3.9 - PDF Report v2 | Complete | Photo strips, charts, comp cards, visual redesign |
| 4.0 - Analysis Filters + Force Analyze | Complete | 17 pre-analysis filters, force DQ bypass, CLI flags |
| 4.1 - Renovation Potential Guard | Complete | New construction DQ, inverted year scoring, age rehab multiplier, enhanced remarks |
| 4.2 - Dashboard JS Refactor | Complete | Split 1,565-line monolith into 10 focused modules |
| 4.3 - Saved Reports & Monitors | Complete | Auto-save reports, load/rerun/delete, monitor cron system |
| 5 - iOS | Pending | SwiftUI views, ViewModel, API |
| 6 - Polish | Pending | Testing, weight tuning |

See `DEVELOPMENT.md` for detailed progress tracking.

## Admin Dashboard (v0.10.0)

Access via **Flip Analyzer** in the WordPress admin sidebar menu.

**Features:**
- Summary stat cards (total, viable, avg score, avg ROI, near-viable, disqualified)
- Chart.js grouped bar chart: viable vs disqualified per city
- Target cities management with add/remove tags
- **Analysis Filters panel** (collapsible): 17 pre-analysis filters with save/reset
- Client-side filters: city dropdown, min score slider, sort, show viable/all/near-viable/DQ
- Results table with expandable rows showing:
  - Score breakdown with weighted bars
  - Dual financial model: Cash Purchase vs Hard Money (10.5%, 2 pts, 80% LTV)
  - Rehab with contingency, remarks multiplier, dynamic hold period
  - Market strength badge with sale-to-list ratio
  - Comps table with adjusted prices and adjustment breakdown on hover
  - ARV projections with $250/sqft MA addition cost and financing model
  - Photo analysis results and remarks signals
  - **Force Full Analysis** button on DQ'd rows (bypasses disqualification)
- "Run Analysis" button — triggers Pass 1 via AJAX (1-3 min), uses saved filters
- "Run Photo Analysis" button — triggers Pass 2 via AJAX (1-5 min)
- "Export CSV" — downloads currently filtered results with all financial columns

**AJAX Actions:**
- `flip_run_analysis` — runs `Flip_Analyzer::run()` with saved filters + report_name, auto-saves as report
- `flip_run_photo_analysis` — runs `Flip_Photo_Analyzer::analyze_top_candidates()` with 10-min timeout
- `flip_refresh_data` — reloads dashboard data, accepts optional `report_id`
- `flip_force_analyze` — runs `Flip_Analyzer::force_analyze_single()`, accepts `report_id`
- `flip_save_filters` — saves analysis filters via `Flip_Database::set_analysis_filters()`
- `flip_get_reports` — returns list of all saved reports (v0.13.0)
- `flip_load_report` — loads a specific report's full dashboard data (v0.13.0)
- `flip_rename_report` — renames a saved report (v0.13.0)
- `flip_rerun_report` — re-runs a report with original criteria, replaces old results (v0.13.0)
- `flip_delete_report` — soft-deletes a report (v0.13.0)
- `flip_create_monitor` — creates a monitor from current cities + filters (v0.13.0)

## Analysis Filter Schema (v0.10.0)

Stored as WP option `bmn_flip_analysis_filters` (JSON). Defaults: SFR + Active only.

```php
[
    'property_sub_types' => ['Single Family Residence'],  // dynamic multi-select from DB
    'statuses'           => ['Active'],                    // Active, Active Under Contract, Pending, Closed
    'sewer_public_only'  => false,                         // requires JOIN to bme_listing_details
    'min_dom'            => null,                          // int|null
    'max_dom'            => null,                          // int|null
    'list_date_from'     => null,                          // 'YYYY-MM-DD'|null
    'list_date_to'       => null,                          // 'YYYY-MM-DD'|null
    'year_built_min'     => null,                          // int|null
    'year_built_max'     => null,                          // int|null
    'min_price'          => null,                          // float|null
    'max_price'          => null,                          // float|null
    'min_sqft'           => null,                          // int|null
    'max_sqft'           => null,                          // int|null
    'min_lot_acres'      => null,                          // float|null
    'min_beds'           => null,                          // int|null
    'min_baths'          => null,                          // float|null
    'has_garage'         => false,                         // bool
]
```

**Important implementation notes:**
- Sewer data is in `bme_listing_details` (JSON array: `["Public Sewer"]`), requires INNER JOIN + `LIKE '%%Public Sewer%%'`
- Closed listings are in `bme_listing_summary_archive`, not `bme_listing_summary`. When statuses include 'Closed', a separate query runs against the archive table and results are merged.
- Available property_sub_types are queried dynamically from `bme_listing_summary` via `Flip_Database::get_available_property_sub_types()`
- CLI flags override saved filters for that run only (don't persist)

## Deployment

```bash
# Deploy includes files
SSHPASS='cFDIB2uPBj5LydX' sshpass -e scp -o StrictHostKeyChecking=no -P 57105 \
  includes/*.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/bmn-flip-analyzer/includes/

# Deploy main plugin file
SSHPASS='cFDIB2uPBj5LydX' sshpass -e scp -o StrictHostKeyChecking=no -P 57105 \
  bmn-flip-analyzer.php stevenovakcom@35.236.219.140:~/public/wp-content/plugins/bmn-flip-analyzer/

# Deploy CSS/JS (fix permissions after!)
SSHPASS='cFDIB2uPBj5LydX' sshpass -e scp -o StrictHostKeyChecking=no -P 57105 \
  assets/css/*.css assets/js/*.js stevenovakcom@35.236.219.140:~/public/wp-content/plugins/bmn-flip-analyzer/assets/
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "chmod 644 ~/public/wp-content/plugins/bmn-flip-analyzer/assets/css/*.css ~/public/wp-content/plugins/bmn-flip-analyzer/assets/js/*.js"
```

## Verification Commands

```bash
# Verify version on production
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "cd ~/public && wp eval \"echo FLIP_VERSION;\""

# Run analysis for a city
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "cd ~/public && wp flip analyze --city=Reading"

# Check results
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "cd ~/public && wp flip results --city=Reading --format=table"

# Deep-dive a specific property
SSHPASS='cFDIB2uPBj5LydX' sshpass -e ssh -o StrictHostKeyChecking=no -p 57105 stevenovakcom@35.236.219.140 \
  "cd ~/public && wp flip property <listing_id> --verbose"
```

**Note:** `wp eval` requires `global $wpdb;` — it's not in scope by default.

## Design Principles

- **Scope vs cost:** Age discount reduces *cost* (what you pay), not *scope* (what work needs doing). Contingency rates and hold periods use scope (`base * remarks_mult`), not age-discounted cost (`base * age_mult * remarks_mult`).
- **Multiplier stacking floors:** When multipliers stack (age x remarks x base), always apply `max()` floor to prevent unrealistic sub-$1/sqft values. Current floor: `$2/sqft`.
- **Comp type matching:** SFR comps must not be used for condos/townhouses. `COMPATIBLE_TYPES` in `class-flip-arv-calculator.php` handles fallback grouping.
- **ARV confidence safety margin:** Low/none confidence requires 25-50% higher profit/ROI to pass DQ — unreliable estimates need wider margin.

## Known Issues (v0.3.1)

1. ~~**Overpass API rate limiting**~~ - Fixed in v0.3.1: retry with exponential backoff (1s, 2s, 4s) for 429/408/503/504
2. ~~**ARV above ceiling**~~ - Fixed in v0.3.1: auto-disqualifies when ARV > 120% of neighborhood ceiling
