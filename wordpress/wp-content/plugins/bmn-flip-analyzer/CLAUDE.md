# BMN Flip Analyzer - Claude Code Reference

**Current Version:** 0.3.0
**Last Updated:** 2026-02-05

## Overview

Standalone WordPress plugin that identifies Single Family Residence flip candidates by scoring properties across financial viability (40%), property attributes (25%), location quality (25%), and market timing (10%). Uses a two-pass approach: data scoring first, then Claude Vision photo analysis on top candidates.

**v0.3.0 Enhancements:**
- Road type detection via OpenStreetMap Overpass API (cul-de-sac, busy-road, etc.)
- Neighborhood ceiling check (compares ARV to max sale in area)
- Revised property scoring: focuses on lot size & expansion potential
- Removed bed/bath penalties (they can always be added)

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
wp flip analyze-photos [--top=50] [--min-score=40]  # Not yet implemented
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
- Comps from `bme_listing_summary_archive` (sold SFR within 6 months)
- ±1 bedroom, ±20% sqft, within 0.5mi (expands to 1.0mi if needed)
- ARV = median comp $/sqft × subject sqft
- Confidence: 5+ = high, 3-4 = medium, 1-2 = low, 0 = disqualify

### School Rating
- Uses internal REST API call to `/bmn-schools/v1/property/schools`
- Caches by rounded lat/lng (3 decimal places)

### Financial Calculations
```
Rehab = sqft × $30/sqft (default, refined by photo analysis)
MAO = (ARV × 0.70) - Rehab
Profit = ARV - list_price - rehab - (ARV × 0.12)
ROI = Profit / (list_price + rehab) × 100
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
| 2.5 - Enhanced Location | In Progress | Road type, ceiling check, expansion scoring |
| 3 - Web Dashboard | Pending | Admin page with Chart.js |
| 4 - iOS | Pending | SwiftUI views, ViewModel, API |
| 5 - Polish | Pending | Testing, weight tuning |

See `DEVELOPMENT.md` for detailed progress tracking.

## Known Issues (v0.3.0)

1. **Overpass API rate limiting** - Occasional 429 errors during bulk analysis; need retry logic
2. **ARV above ceiling** - Properties with ARV > 100% of neighborhood ceiling show warning but aren't auto-disqualified (may want to add this)
