<?php
/**
 * Plugin Name: BMN Flip Analyzer
 * Description: Identifies Single Family Residence flip candidates by scoring properties on financial viability, attributes, location, market timing, and photo analysis.
 * Version: 0.7.0
 * Author: BMN Boston
 * Requires PHP: 8.0
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

define('FLIP_VERSION', '0.7.1');
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
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-analyzer.php';
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-photo-analyzer.php';

// Load WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once FLIP_PLUGIN_PATH . 'includes/class-flip-cli.php';
}

// Load REST API
require_once FLIP_PLUGIN_PATH . 'includes/class-flip-rest-api.php';

// Load admin dashboard
if (is_admin()) {
    require_once FLIP_PLUGIN_PATH . 'admin/class-flip-admin-dashboard.php';
    Flip_Admin_Dashboard::init();
}

// Register REST API routes
add_action('rest_api_init', function () {
    Flip_REST_API::register_routes();
});

// Activation hook
register_activation_hook(__FILE__, function () {
    Flip_Database::create_tables();
    Flip_Database::set_default_cities();
    Flip_Database::migrate_v070();
});

// Version-check migration for file-only updates (no deactivate/reactivate)
add_action('plugins_loaded', function () {
    $db_version = get_option('bmn_flip_db_version', '0');
    if (version_compare($db_version, '0.7.0', '<')) {
        Flip_Database::migrate_v070();
        update_option('bmn_flip_db_version', '0.7.0');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Tables are preserved on deactivation for data safety
});
