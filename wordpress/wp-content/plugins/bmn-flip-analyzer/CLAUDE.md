# BMN Flip Analyzer - Claude Code Reference

**Current Version:** 0.6.1
**Last Updated:** 2026-02-05

## Overview

Standalone WordPress plugin that identifies Single Family Residence flip candidates by scoring properties across financial viability (40%), property attributes (25%), location quality (25%), and market timing (10%). Uses a two-pass approach: data scoring first, then Claude Vision photo analysis on top candidates.

**v0.6.0 Enhancements (ARV Accuracy & Financial Model Overhaul):**
- Bathroom filter on comps (±1.0 range) with graceful fallback when too few comps
- Appraisal-style comp adjustments: market-scaled values for beds, baths, sqft, garage, basement
- Time-decay comp weighting: exponential decay (half-weight at 6 months)
- Sale-to-list ratio: market strength signal (very_hot/hot/balanced/soft/cold)
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
- **Results table** — `wp_bmn_flip_scores` stores all analysis data
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
| `includes/class-flip-rest-api.php` | REST API endpoints (6 endpoints) |
| `includes/class-flip-photo-analyzer.php` | Claude Vision photo analysis |
| `admin/class-flip-admin-dashboard.php` | Admin page, AJAX handlers |
| `admin/views/dashboard.php` | Dashboard HTML template |
| `assets/js/flip-dashboard.js` | Chart.js, table, filters, CSV export |
| `assets/css/flip-dashboard.css` | Dashboard styles |

## Database

**Table:** `wp_bmn_flip_scores`
- Scores: `total_score`, `financial_score`, `property_score`, `location_score`, `market_score`, `photo_score`
- Financials: `estimated_arv`, `arv_confidence`, `comp_count`, `estimated_rehab_cost`, `mao`, `estimated_profit`, `estimated_roi`
- Financing (v0.6.0): `financing_costs`, `holding_costs`, `rehab_contingency`, `hold_months`, `cash_profit`, `cash_roi`, `cash_on_cash_roi`, `rehab_multiplier`
- Market (v0.6.0): `market_strength`, `avg_sale_to_list`
- Neighborhood: `neighborhood_ceiling`, `ceiling_pct`, `ceiling_warning`, `road_type`
- Property snapshot: `list_price`, `address`, `city`, `bedrooms_total`, etc.
- JSON fields: `comp_details_json`, `remarks_signals_json`, `photo_analysis_json`
- Flags: `disqualified`, `disqualify_reason`

**Source tables (from BME/MLD):**
- `bme_listing_summary` — active SFR listings
- `bme_listing_summary_archive` — sold comps for ARV
- `bme_listing_details` — public remarks
- `bme_listing_financial` — property taxes
- `bme_media` — property photos (Pass 2)

## WP-CLI Commands

```bash
wp flip analyze [--limit=500] [--city=Reading,Melrose]
wp flip results [--top=20] [--min-score=60] [--city=Reading] [--sort=total_score] [--format=table|json|csv]
wp flip property <listing_id> [--verbose]
wp flip summary
wp flip config --list-cities | --add-city=Woburn | --remove-city=Burlington
wp flip clear [--older-than=30] [--all] [--yes]
wp flip analyze_photos [--top=50] [--min-score=40]  # Pass 2: Claude Vision photo analysis
```

## Scoring Weights

| Category | Weight | Sub-factors |
|----------|--------|-------------|
| Financial | 40% | Price/ARV ratio (37.5%), $/sqft vs neighborhood (25%), price reduction (25%), DOM motivation (12.5%) |
| Property | 25% | Lot size (35%), expansion potential (30%), existing sqft (20%), year/systems (15%) |
| Location | 25% | Road type (25%), ceiling support (20%), schools (20%), price trend (15%), comp density (10%), tax rate (10%) |
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
- `list_price > ARV * 0.85`
- Default rehab estimate > 35% of ARV
- `building_area_total < 600` sqft

**Post-calculation (v0.6.0):**
- Financed profit < $25,000
- Financed ROI < 15%

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
- **Sale-to-list ratio**: very_hot (≥1.05), hot (≥1.01), balanced (≥0.97), soft (≥0.93), cold (<0.93)
- **Multi-factor confidence**: comp count (0-40) + avg distance (0-30) + avg recency (0-20) + price variance CV (0-10)

### School Rating
- Uses internal REST API call to `/bmn-schools/v1/property/schools`
- Caches by rounded lat/lng (3 decimal places)

### Financial Calculations (v0.6.0)
```
Rehab = sqft × age-based $/sqft × remarks_multiplier (0.5x-1.5x)
Contingency = rehab × 10%
Total Rehab = rehab + contingency
Purchase Closing = list_price × 1.5%
Sale Costs = ARV × 6% (5% commission + 1% closing)
Hold Months = dynamic (1-8 months based on rehab scope + area avg DOM + permit buffer)
Holding Costs = (monthly_tax + monthly_insurance + $350 utilities) × hold_months
  Monthly Tax = list_price × 1.3% / 12
  Monthly Insurance = list_price × 0.5% / 12
MAO (Max Offer Price) = (ARV × 0.70) - Total Rehab

Cash Scenario:
  Cash Profit = ARV - list_price - total_rehab - purchase_closing - sale_costs - holding_costs
  Cash ROI = cash_profit / (list_price + total_rehab + purchase_closing + holding_costs) × 100

Hard Money Scenario (12% rate, 2 points, 80% LTV):
  Loan Amount = list_price × 80%
  Financing Costs = (loan × 2%) + (loan × 12% / 12 × hold_months)
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
- `sort` (total_score, estimated_profit, estimated_roi, cash_on_cash_roi, list_price, estimated_arv)
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
| 4 - iOS | Pending | SwiftUI views, ViewModel, API |
| 5 - Polish | Pending | Testing, weight tuning |

See `DEVELOPMENT.md` for detailed progress tracking.

## Admin Dashboard (v0.6.0)

Access via **Flip Analyzer** in the WordPress admin sidebar menu.

**Features:**
- Summary stat cards (total, viable, avg score, avg ROI, disqualified)
- Chart.js grouped bar chart: viable vs disqualified per city
- Client-side filters: city dropdown, min score slider, sort, show viable/all/DQ
- Results table with expandable rows showing:
  - Score breakdown with weighted bars
  - Dual financial model: Cash Purchase vs Hard Money (12%, 2 pts, 80% LTV)
  - Rehab with contingency, remarks multiplier, dynamic hold period
  - Market strength badge with sale-to-list ratio
  - Comps table with adjusted prices and adjustment breakdown on hover
  - ARV projections with $250/sqft MA addition cost and financing model
  - Photo analysis results and remarks signals
- "Run Analysis" button — triggers Pass 1 via AJAX (1-3 min)
- "Run Photo Analysis" button — triggers Pass 2 via AJAX (1-5 min)
- "Export CSV" — downloads currently filtered results with all financial columns

**AJAX Actions:**
- `flip_run_analysis` — runs `Flip_Analyzer::run()` with 5-min timeout
- `flip_run_photo_analysis` — runs `Flip_Photo_Analyzer::analyze_top_candidates()` with 10-min timeout
- `flip_refresh_data` — reloads dashboard data without re-running analysis

## Known Issues (v0.3.1)

1. ~~**Overpass API rate limiting**~~ - Fixed in v0.3.1: retry with exponential backoff (1s, 2s, 4s) for 429/408/503/504
2. ~~**ARV above ceiling**~~ - Fixed in v0.3.1: auto-disqualifies when ARV > 120% of neighborhood ceiling
