<?php
/**
 * Plugin Name: BMN Flip Analyzer
 * Description: Identifies residential investment property candidates by scoring properties on financial viability, attributes, location, market timing, and photo analysis. Supports SFR, multifamily, and Residential Income properties.
 * Version: 0.17.0
 * Author: BMN Boston
 * Requires PHP: 8.0
 *
 * Version 0.17.0 - Multifamily (Residential Income) Support
 * - Property type filter expanded: Residential + Residential Income
 * - Dynamic property_type WHERE clause based on selected sub-types
 * - ARV comp matching for multifamily (grouped by unit count)
 * - Multifamily data enrichment: unit count + MLS gross income passed to rental calculator
 * - Expansion potential capped for multifamily (same as condos)
 * - Sub-types dropdown includes all Residential Income variants
 *
 * Version 0.16.0 - Multi-Exit Strategy Analysis
 * - Rental Hold analysis: NOI, cap rate, cash-on-cash return, DSCR, GRM, tax benefits
 * - BRRRR analysis: refinance modeling, cash-left-in-deal, post-refi cash flow
 * - Strategy recommendation engine: scores Flip vs Rental vs BRRRR (0-100)
 * - Three-tier rent estimation: user override → city $/sqft lookup → value-based fallback
 * - Multi-year projections: 1/5/10/20 year equity + cash flow with appreciation + rent growth
 * - New DB column: rental_analysis_json on wp_bmn_flip_scores
 * - New WP option: bmn_flip_rental_defaults (vacancy, mgmt, appreciation, refi terms)
 * - New class: Flip_Rental_Calculator (includes/class-flip-rental-calculator.php)
 * - Rental/BRRRR calculated for ALL properties (viable + DQ'd) — a bad flip may be a great rental
 *
 * Version 0.15.0 - Email Enhancements + Scoring Weight Tuning
 * - Polished monitor emails: responsive HTML wrapper, MLD unified footer/branding (with fallback)
 * - Digest emails: periodic summary of all monitor activity (daily/weekly, configurable)
 * - Notification levels: per-monitor choice of viable_only, viable_and_near, or all
 * - Scoring weight tuning UI: admin panel to adjust all category/sub-factor weights + thresholds
 * - Dynamic weight reading: all 5 scorers read from WP option, fall back to hardcoded defaults
 * - New WP options: bmn_flip_scoring_weights, bmn_flip_digest_settings
 * - New DB column: notification_level on wp_bmn_flip_reports
 * - New JS module: flip-scoring-weights.js in FlipDashboard namespace
 *
 * Version 0.14.0 - Code Refactoring (6 Extracted Classes)
 * - Extract: Flip_Property_Fetcher from Flip_Analyzer (eliminates 254-line filter duplication)
 * - Extract: Flip_Disqualifier from Flip_Analyzer (DQ checks, distress detection, condition logic)
 * - Extract: Flip_PDF_Components from Flip_PDF_Generator (rendering, formatting, colors)
 * - Extract: Flip_PDF_Charts from Flip_PDF_Generator (gauge, bar chart, sensitivity chart)
 * - Extract: Flip_PDF_Images from Flip_PDF_Generator (photo download, strip, comp cards)
 * - Extract: Flip_Report_AJAX from Flip_Admin_Dashboard (report/monitor AJAX handlers)
 * - No functional changes — pure refactoring for maintainability
 *
 * Version 0.13.3 - Monitor Polish
 * - Fix: Dashboard "Run Photo Analysis" now scopes to active report (was querying global latest run)
 * - Fix: analyze_top_candidates() accepts report_id, uses analyze_and_update() for correct row targeting
 * - Add: PDF cleanup mechanism — deletes reports older than 30 days via cron
 * - Fix: Monitor email shows "N/A" for failed PDFs instead of blank cells
 * - Fix: Monitor email includes footer note when PDF generation fails
 *
 * Version 0.13.2 - Monitor Photo Analysis Fix
 * - Fix: Monitor process_viable() now saves photo analysis results (was discarding return value)
 * - Fix: Photo analysis in monitor context updates the correct report-scoped DB row
 * - Add: Flip_Photo_Analyzer::analyze_and_update() public method with report_id support
 *
 * Version 0.13.1 - Reports & Monitor System Audit Fixes
 * - Fix: Global "latest" view now prefers latest manual report (monitor runs no longer contaminate)
 * - Fix: get_summary() scoped to orphan scores only (excludes report-scoped data)
 * - Fix: viable_count uses score >= 60 threshold (was counting all non-DQ as viable)
 * - Fix: fetch_matching_listing_ids() and fetch_properties_by_ids() now query archive table for Closed status
 * - Fix: Monitor marks new listings as "seen" AFTER analysis (was before, causing data loss on failure)
 * - Fix: Empty reports (0 results) auto-deleted instead of persisting as clutter
 * - Fix: Re-run with 0 results now clears stale data (was preserving old scores)
 * - Fix: Concurrency lock increased from 10 to 15 minutes
 * - Add: Periodic cleanup of scores/seen data for deleted reports (runs on monitor cron)
 * - Remove: Dead code (stamp_scores_with_report, delete_scores_for_report)
 *
 * Version 0.13.0 - Saved Reports & Monitor System
 * - Auto-save every analysis run as a named report (prompted before running)
 * - Saved Reports panel on dashboard: load, rename, re-run, delete reports
 * - Report context bar shows active report name with "Back to Latest" navigation
 * - Re-run reports with fresh MLS data using original criteria (replaces old results)
 * - Monitor system: saved searches that auto-analyze only NEW listings matching criteria
 * - Tiered monitor notifications: DQ'd = dashboard badge, viable = badge + email + photo + PDF
 * - wp_cron integration: twicedaily check, configurable per-monitor (daily/twice_daily/weekly)
 * - New DB tables: wp_bmn_flip_reports, wp_bmn_flip_monitor_seen
 * - New DB column: report_id on wp_bmn_flip_scores (scopes results to reports)
 * - New JS module: flip-reports.js in FlipDashboard namespace
 * - Report-aware AJAX: run, refresh, PDF, force-analyze all accept report_id
 * - 25-report cap with soft delete for archived reports
 *
 * Version 0.12.0 - Dashboard JS Modular Refactor
 * - Split monolithic flip-dashboard.js (1,565 lines) into 10 focused modules
 * - Namespace pattern: window.FlipDashboard with wp_enqueue_script dependency chain
 * - Modules: core, helpers, stats-chart, filters-table, detail-row, projections, ajax,
 *   analysis-filters, cities, init
 * - Bug fix: applyFilters() now delegates to getFilteredResults() (was duplicated logic)
 * - PHP enqueue_assets() rewritten for 10-file dependency chain
 * - Deleted old flip-dashboard.js
 *
 * Version 0.11.1 - Logic Audit Fixes (11 loopholes)
 * - ARV confidence discount: low/none confidence requires 25-50% higher profit/ROI to pass DQ
 * - Pre-DQ rehab estimate now includes age+remarks multipliers (was base only)
 * - Missing $/sqft properly penalized (score 30, not neutral 50)
 * - Contingency rate based on scope of work, not age-discounted cost
 * - Hold period uses scope-based rehab (age discount affects cost, not timeline)
 * - Market strength capped at balanced when <3 sale-to-list data points
 * - Distress keyword detection: word boundaries, 120-char context, 16 negation phrases
 * - Post-multiplier rehab floor: $2/sqft minimum after all multipliers
 * - Distressed comp downweighting: foreclosure/short-sale comps get 0.3x weight in ARV
 * - Expansion potential capped at 40 for condos/townhouses (can't expand)
 * - Ceiling type fallback tracking (mixed-type ceiling flag)
 *
 * Version 0.11.0 - Renovation Potential Guard
 * - New construction auto-DQ: properties ≤5 years old disqualified unless distress signals detected
 * - Property condition field from bme_listing_details used as supplementary DQ signal
 * - Inverted year-built scoring: older properties score higher (renovation need vs systems condition)
 * - Age-based rehab condition multiplier: near-new properties get realistic near-zero rehab estimates
 * - Lowered rehab floor from $12/sqft to $5/sqft for realistic near-new estimates
 * - Enhanced remarks keywords: weighted values, increased cap ±25, new condition-specific terms
 * - New DB column: age_condition_multiplier
 * - Dashboard: shows age condition multiplier, NEW badge on construction DQ
 *
 * Version 0.10.0 - Pre-Analysis Filters + Force Analyze + PDF v2
 * - 17 configurable pre-analysis filters stored as WP option
 * - Collapsible Analysis Filters panel on dashboard with save/reset
 * - CLI: 14 filter override flags (override saved filters for that run only)
 * - Force Full Analysis button on DQ'd property rows (bypasses all DQ checks)
 * - PDF report v2: photo thumbnails, circular gauge, charts, comp cards
 *
 * Version 0.9.1 - Sleeker PDF with Photos & Charts
 * - Photo thumbnail strip on cover page (5 photos from bme_media)
 * - Circular score gauge replaces plain text total score
 * - Horizontal stacked bar chart comparing Cash vs Hard Money costs
 * - Sensitivity line chart (profit across 85%-110% ARV scenarios)
 * - Comparable photo cards with adjustment badges (replaces plain table)
 * - Blue background band on section headers
 * - Card shadows for depth effect
 * - Larger font hierarchy (18pt values, 12pt score bars)
 *
 * Version 0.9.0 - Investor-Grade PDF Report Redesign
 * - Full visual rewrite of PDF report generator
 * - Hero image with clipping (prevents overflow)
 * - Rounded-corner metric card grids on cover & property pages
 * - Professional styled tables with blue headers and zebra rows
 * - Score bars with rounded ends in bordered cards
 * - Risk grade hero card with coloured circle + explanation
 * - Side-by-side investment scenario cards with accent bars
 * - MAO callout box with dual values
 * - Range bar for ARV floor/mid/ceiling
 * - Pill badges for MLS remarks signals
 * - Financial analysis on dedicated page (was crammed with scores)
 * - Callout boxes for breakeven ARV and structural concerns
 * - Better typography hierarchy and more white space throughout
 *
 * Version 0.8.0 - ARV & Financial Model Overhaul
 * - ARV accuracy: sqft adjustment threshold 10%→5%, P90 neighborhood ceiling, reno weight 2.0→1.3
 * - Smooth continuous rehab cost formula (eliminates step-function discontinuities)
 * - MA transfer tax (0.456% buy+sell), lead paint flag ($8K for pre-1978)
 * - Scaled contingency by rehab scope (8% cosmetic → 20% major)
 * - Updated constants: hard money 10.5% (was 12%), commission 4.5% (was 5%)
 * - Actual property tax rates from MLS data (was flat 1.3%)
 * - Annualized ROI, breakeven ARV, adjusted MAO (incl. holding+financing)
 * - Deal risk grade (A-F) combining ARV confidence, margin, comp consistency, velocity
 * - Location scoring rebalance: removed tax rate (double-count), boosted price trend/ceiling
 * - Dashboard: sensitivity table, risk grade column, annualized ROI, lead paint badge
 * - DB migration: 6 new columns
 *
 * Version 0.7.0 - Market-Adaptive Thresholds
 * - Continuous formula thresholds based on avg_sale_to_list with tier guard rails
 * - Thresholds scale by market_strength: very_hot → cold (profit $10-35K, ROI 5-22%)
 * - Pre-calc DQ uses market-adaptive price/ARV ratio (0.78-0.92)
 * - Near-viable category for properties within 80% of adjusted thresholds
 * - Low-confidence guard: don't relax below balanced when ARV confidence is low
 * - Dashboard: near-viable stat card, filter option, amber row styling, threshold display
 * - DB migration: near_viable and applied_thresholds_json columns
 *
 * Version 0.6.0 - ARV Accuracy & Financial Model Overhaul
 * - Bathroom filter on comps with graceful fallback
 * - Appraisal-style comp adjustments (market-scaled: beds, baths, sqft, garage, basement)
 * - Time-decay comp weighting (half-weight at 6 months)
 * - Sale-to-list ratio and market strength signal
 * - Multi-factor ARV confidence score (count + distance + recency + variance)
 * - Dual financial model: cash purchase AND hard money financing (12%, 2 pts, 80% LTV)
 * - Remarks-based rehab multiplier (0.5x-1.5x from "new roof", "needs work", etc.)
 * - Dynamic hold period based on rehab scope + area avg DOM
 * - 10% rehab contingency, realistic holding costs (tax + insurance + utilities)
 * - Minimum profit ($25K) and ROI (15%) disqualifiers
 * - Shared calculate_financials() method (fixes photo analyzer formula divergence)
 * - Dashboard: dual profit scenarios, comp adjustments table, market strength badges
 * - Projection calculator: $250/sqft MA addition cost, financing model
 * - CSV export: 10 new financial columns
 * - DB migration: 10 new columns for financing/holding/market data
 *
 * Version 0.4.0 - Web Admin Dashboard
 * - Admin dashboard page with Chart.js city breakdown chart
 * - Results table with expandable rows showing score/financial/comp details
 * - Run Analysis button with AJAX progress modal
 * - Export CSV with all scored property data
 * - Client-side filters: city, min score, sort, show viable/all/disqualified
 *
 * Version 0.3.1 - Robustness & Quality Improvements
 * - Overpass API retry logic with exponential backoff for 429/503 errors
 * - Auto-disqualify properties where ARV > 120% of neighborhood ceiling
 * - Store ceiling data in disqualified property records
 *
 * Version 0.3.0 - Enhanced Location & Expansion Analysis
 * - Road type detection via OSM Overpass API (cul-de-sac, busy-road, etc.)
 * - Neighborhood ceiling check (compares ARV to max sale in area)
 * - Revised property scoring: focuses on lot size & expansion potential
 * - Removed bed/bath penalties (they can always be added)
 *
 * Version 0.2.0 - Photo Analysis + REST API
 * - Claude Vision photo analysis with base64 encoding
 * - 6 REST API endpoints for web/iOS consumption
 *
 * Version 0.1.0 - Initial release
 * - Plugin scaffold with DB table creation
 * - ARV calculator from sold comps
 * - Financial, Property, Location, Market scoring modules
 * - WP-CLI commands (analyze, results, property, summary, config, clear)
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FLIP_VERSION', '0.17.0');
define('FLIP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLIP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load core classes
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-database.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-arv-calculator.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-financial-scorer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-property-scorer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-location-scorer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-road-analyzer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-market-scorer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-property-fetcher.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-disqualifier.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-rental-calculator.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-analyzer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-photo-analyzer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-monitor-runner.php';

// Load WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once FLIP_PLUGIN_PATH . 'includes/class-flip-cli.php';
}

// Load REST API
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-rest-api.php';

// Load admin dashboard + report AJAX handlers
if (is_admin()) {
    require_once FLIP_PLUGIN_PATH . 'admin/class-flip-admin-dashboard.php';
    require_once FLIP_PLUGIN_PATH . 'admin/class-flip-report-ajax.php';
    Flip_Admin_Dashboard::init();
    Flip_Report_AJAX::init();
}

// Register REST API routes
add_action('rest_api_init', function () {
    Flip_REST_API::register_routes();
});

// Monitor cron hook
add_action('bmn_flip_monitor_check', ['Flip_Monitor_Runner', 'run_all_due']);

// Digest email — runs after all monitors complete (priority 20)
add_action('bmn_flip_monitor_check', ['Flip_Monitor_Runner', 'maybe_send_digest'], 20);

// Periodic cleanup: purge scores/seen data for deleted reports, old orphan scores, old PDFs
add_action('bmn_flip_monitor_check', function () {
    Flip_Database::cleanup_deleted_report_scores();
    Flip_Database::cleanup_deleted_monitor_seen();
    Flip_Database::clear_old_results(30);

    // Clean up old PDF files (requires lazy-loading the class)
    require_once FLIP_PLUGIN_PATH . 'includes/class-flip-pdf-generator.php';
    Flip_PDF_Generator::cleanup_old_pdfs(30);
});

// Activation hook
register_activation_hook(__FILE__, function () {
    Flip_Database::create_tables();
    Flip_Database::create_reports_table();
    Flip_Database::create_monitor_seen_table();
    Flip_Database::set_default_cities();
    Flip_Database::migrate_v070();
    Flip_Database::migrate_v080();
    Flip_Database::migrate_v0110();
    Flip_Database::migrate_v0130();
    Flip_Database::migrate_v0150();
    Flip_Database::migrate_v0160();

    // Schedule monitor cron if not already scheduled
    if (!wp_next_scheduled('bmn_flip_monitor_check')) {
        wp_schedule_event(time(), 'twicedaily', 'bmn_flip_monitor_check');
    }
});

// Version-check migration for file-only updates (no deactivate/reactivate)
add_action('plugins_loaded', function () {
    $db_version = get_option('bmn_flip_db_version', '0');
    if (version_compare($db_version, '0.7.0', '<')) {
        Flip_Database::migrate_v070();
        update_option('bmn_flip_db_version', '0.7.0');
    }
    if (version_compare($db_version, '0.8.0', '<')) {
        Flip_Database::migrate_v080();
        update_option('bmn_flip_db_version', '0.8.0');
    }
    if (version_compare($db_version, '0.11.0', '<')) {
        Flip_Database::migrate_v0110();
        update_option('bmn_flip_db_version', '0.11.0');
    }
    if (version_compare($db_version, '0.13.0', '<')) {
        Flip_Database::create_reports_table();
        Flip_Database::create_monitor_seen_table();
        Flip_Database::migrate_v0130();
        // Schedule monitor cron (may be a file-only update without activation hook)
        if (!wp_next_scheduled('bmn_flip_monitor_check')) {
            wp_schedule_event(time(), 'twicedaily', 'bmn_flip_monitor_check');
        }
        update_option('bmn_flip_db_version', '0.13.0');
    }
    if (version_compare($db_version, '0.15.0', '<')) {
        Flip_Database::migrate_v0150();
        update_option('bmn_flip_db_version', '0.15.0');
    }
    if (version_compare($db_version, '0.16.0', '<')) {
        Flip_Database::migrate_v0160();
        update_option('bmn_flip_db_version', '0.16.0');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Tables are preserved on deactivation for data safety
    wp_clear_scheduled_hook('bmn_flip_monitor_check');
});
