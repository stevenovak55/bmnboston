# BMN Flip Analyzer - Claude Code Reference

**Current Version:** 0.5.0
**Last Updated:** 2026-02-05

## Overview

Standalone WordPress plugin that identifies Single Family Residence flip candidates by scoring properties across financial viability (40%), property attributes (25%), location quality (25%), and market timing (10%). Uses a two-pass approach: data scoring first, then Claude Vision photo analysis on top candidates.

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

- 0 comps within 1 mile
- `list_price > ARV * 0.85`
- Default rehab estimate > 35% of ARV
- `building_area_total < 600` sqft

## Important Patterns

### ARV Calculation
- Comps from `bme_listing_summary_archive` (sold SFR within 12 months)
- ±1 bedroom, ±30% sqft, expanding radius: 0.5mi → 1.0mi → 2.0mi
- Distance-weighted + renovation-weighted average $/sqft × subject sqft
  - Distance weight: 1/(distance+0.1)² — closer comps dominate
  - Renovation multiplier: renovated=2x, new construction=1.5x, unknown=1x
- Comps deduped by address (keeps most recent sale, removes pre-flip purchases)
- Road type discount: -15% busy road, -25% highway-adjacent
- Confidence: 5+ = high, 3-4 = medium, 1-2 = low, 0 = disqualify

### School Rating
- Uses internal REST API call to `/bmn-schools/v1/property/schools`
- Caches by rounded lat/lng (3 decimal places)

### Financial Calculations
```
Rehab = sqft × age-based $/sqft (0-10yr: $15, 11-25yr: $30, 26-50yr: $45, 50+yr: $60)
Purchase Closing = list_price × 1.5%
Sale Costs = ARV × 6% (5% commission + 1% closing)
Holding Costs = (list_price + rehab) × 0.8%/month × 6 months
MAO (Max Offer Price) = (ARV × 0.70) - Rehab
Profit = ARV - list_price - rehab - purchase_closing - sale_costs - holding_costs
ROI = Profit / (list_price + rehab + purchase_closing + holding_costs) × 100
```

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
- `sort` (total_score, estimated_profit, estimated_roi, list_price, estimated_arv)
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
| 4 - iOS | Pending | SwiftUI views, ViewModel, API |
| 5 - Polish | Pending | Testing, weight tuning |

See `DEVELOPMENT.md` for detailed progress tracking.

## Admin Dashboard (v0.4.0)

Access via **Flip Analyzer** in the WordPress admin sidebar menu.

**Features:**
- Summary stat cards (total, viable, avg score, avg ROI, disqualified)
- Chart.js grouped bar chart: viable vs disqualified per city
- Client-side filters: city dropdown, min score slider, sort, show viable/all/DQ
- Results table with expandable rows showing score breakdown, financials, comps, photo analysis
- "Run Analysis" button — triggers Pass 1 via AJAX (1-3 min)
- "Export CSV" — downloads currently filtered results

**AJAX Actions:**
- `flip_run_analysis` — runs `Flip_Analyzer::run()` with 5-min timeout
- `flip_refresh_data` — reloads dashboard data without re-running analysis

**Note:** Photo analysis remains CLI-only (`wp flip analyze_photos`) due to API costs.

## Known Issues (v0.3.1)

1. ~~**Overpass API rate limiting**~~ - Fixed in v0.3.1: retry with exponential backoff (1s, 2s, 4s) for 429/408/503/504
2. ~~**ARV above ceiling**~~ - Fixed in v0.3.1: auto-disqualifies when ARV > 120% of neighborhood ceiling
