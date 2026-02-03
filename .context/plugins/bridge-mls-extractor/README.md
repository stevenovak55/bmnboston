# Bridge MLS Extractor Plugin

MLS data extraction from Bridge Interactive API.

## Quick Info

| Setting | Value |
|---------|-------|
| Version | 4.0.25 |
| API Namespace | `/wp-json/bme/v1` (admin only) |
| Main File | `bridge-mls-extractor-pro.php` |

## Recent Changes

### v4.0.25 (2026-01-16)
- Fixed timezone handling violations (`time()` → `current_time('timestamp')`)
- Affected: `class-bme-cron-manager.php`, `class-bme-batch-manager.php`, `class-bme-extraction-engine.php`
- Fixes user-facing timestamps: cron stats, activity logs, extraction scheduling, log cleanup
- Addresses WordPress timezone (America/New_York) vs server UTC mismatch

## Purpose

- Extracts property data from Bridge Interactive API
- Populates `bme_listing_summary` and related tables
- Handles active/archive table separation
- Provides market analytics

## Key Files

| File | Purpose |
|------|---------|
| `class-bme-data-processor.php` | Data processing |
| `class-bme-extraction-engine.php` | API integration |
| `class-bme-database-manager.php` | Schema management |
| `class-bme-cache-manager.php` | Caching layer |

## Database Tables

Creates 18+ tables with `bme_` prefix:
- `bme_listing_summary` - Active listings (fast queries)
- `bme_listing_summary_archive` - Sold listings (fast queries)
- `bme_listings`, `bme_listings_archive` - Full data
- `bme_listing_details`, `bme_media`, etc.

## Usage

This plugin runs on a schedule to keep property data updated. It's primarily an internal tool - MLS Listings Display plugin queries its data.

## Troubleshooting

See [troubleshooting.md](troubleshooting.md) for common issues.
