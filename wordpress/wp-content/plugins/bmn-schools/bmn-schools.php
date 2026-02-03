<?php
/**
 * Plugin Name: BMN Schools
 * Plugin URI: https://bmnboston.com/
 * Description: Comprehensive Massachusetts school information database for real estate. Provides school data, rankings, test scores, and district boundaries for property listings.
 * Version: 0.6.39
 * Author: BMN Boston
 * Author URI: https://bmnboston.com/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: bmn-schools
 * Domain Path: /languages
 *
 * @package BMN_Schools
 * @version 0.6.39
 *
 * CHANGELOG:
 * Version 0.6.39 - FIX SCHOOL DISTRICT PAGES 404 ERROR (Jan 21, 2026)
 * - BMN_School_Pages was only instantiated on front-end requests (!is_admin())
 * - Rewrite rules need to register on ALL requests including admin
 * - When flush_rewrite_rules() was called in admin, school rules weren't included
 * - Result: district detail URLs (/schools/{slug}/) returned 404
 * - Fix: Removed !is_admin() condition so rewrite rules always register
 *
 * Version 0.6.38 - Performance: Batch query optimization (Jan 14, 2026)
 * - Optimized get_schools_for_property() to reduce ~330 queries to ~10
 *
 * Version 0.6.37 - FIX YEAR ROLLOVER BUG IN RANKING CALCULATOR (Jan 6, 2026)
 * - Added get_latest_data_year() helper method to query MAX(year) from rankings table
 * - Updated constructor and all static methods to use helper instead of date('Y')
 * - Prevents bug where Jan 1 queries fail because data is from previous year
 * - Affects: get_school_ranking(), get_top_schools(), get_schools_by_min_score(),
 *   get_benchmark(), get_district_ranking(), generate_school_highlights()
 *
 * Version 0.6.36 - SECURITY: TRANSACTIONS, LOCKS, AND TARGETED CACHE (Dec 27, 2025)
 * - Added database transaction to ranking calculator for data integrity
 * - Added lock to prevent concurrent ranking calculations (race condition fix)
 * - Changed cache invalidation from full flush to targeted deletion
 * - New methods: acquire_lock(), release_lock(), invalidate_cache_type()
 * - Lock auto-releases after 10 minutes (handles crashed processes)
 *
 * Version 0.6.35 - BOOST AP, REDUCE RATIO (Dec 23, 2025)
 * - Increased AP performance weight from 6% to 9%
 * - Reduced ratio weight from 8% to 5%
 * - Elementary ratio reduced from 10% to 8%, spending increased to 12%
 * - Impact: Wakefield -1.1pts, Belmont +0.8pts, Winchester +0.6pts
 * - Better rewards academically strong districts with good AP programs
 *
 * Version 0.6.34 - REWEIGHT TO ALIGN WITH NICHE (Dec 23, 2025)
 * - Increased MCAS weight from 30% to 40% (Niche uses 50% for academics)
 * - Reduced ratio weight from 10% to 8%
 * - Added college outcomes as new factor (4%) using district-level data
 * - Elementary MCAS weight increased to 45%
 * - This should better align Wakefield (#104 on Niche) vs Reading (#57)
 *
 * Version 0.6.33 - FIX STAFFING YEAR MISMATCH (Dec 23, 2025)
 * - Fixed bug where older staffing data was used with newer enrollment
 * - Ratio calculation now matches staffing and enrollment by year
 * - Example: Chenery showed 27.6 ratio score (using 2024 staff + 2025 enrollment)
 * - Now correctly shows ~75+ ratio score (using 2025 staff + 2025 enrollment)
 * - This was causing MCAS-strong schools like Belmont to rank too low
 *
 * Version 0.6.32 - REQUIRE MCAS FOR RANKINGS (Dec 23, 2025)
 * - Schools must have MCAS data to be ranked
 * - Excludes daycares, special ed schools, alternative programs from rankings
 * - District rankings only use schools with MCAS data
 * - Fixes: Wakefield ranked too high (#38 vs Niche #104), Belmont too low (#25 vs Niche #6)
 * - ~391 schools without MCAS will no longer inflate district scores
 *
 * Version 0.6.31 - STALE RANKING CLEANUP (Dec 23, 2025)
 * - Districts that no longer meet requirements now have their rankings DELETED
 * - Previously skipped districts retained stale high scores
 * - This ensures rankings only show qualifying districts
 *
 * Version 0.6.30 - RANKING ALGORITHM ALIGNMENT (Dec 23, 2025)
 * - Adjusted weights: ratio 20%→10%, mcas_proficiency 25%→30%, graduation 12%→15%
 * - Added aggressive enrollment-based reliability factor (up to 25% penalty for small districts)
 * - District rankings now use enrollment-weighted averaging
 * - Stricter district requirements: 3+ schools OR 500+ students (was just 2 schools)
 * - Better alignment with Niche rankings (Winchester, Belmont, Hopkinton should rank higher)
 *
 * Version 0.6.19 - API RATE LIMITING (Dec 22, 2025)
 * - Added rate limiting to expensive REST API endpoints (60 requests/min per IP)
 * - Does NOT block bots - Googlebot can crawl normally
 * - Root cause fix was in MLD v6.30.12 (internal REST dispatch)
 *
 * Version 0.6.6 - YEAR-SPECIFIC RANKINGS (Dec 20, 2025)
 * - Updated ranking calculator to use year-specific data
 * - MCAS, graduation, attendance now filtered by ranking year
 * - Imported historical graduation (2023-2024) and attendance (2023-2025) data
 * - Rankings now show real year-over-year trends (progression/regression)
 *
 * Version 0.6.5 - CATEGORY TOTAL FIX (Dec 20, 2025)
 * - Fixed get_category_total() to filter by current year only
 * - Previously counted across all years (2023+2024+2025 = 2529 instead of 843)
 * - Category totals now display correctly (e.g., "#1 of 843" not "#1 of 2529")
 *
 * Version 0.6.1 - PERCENTILE-BASED GRADING (Dec 20, 2025)
 * - Added state_rank column to rankings table (1 = best school in state)
 * - Added percentile-based letter grades (A+ = top 10%, A = 80-89%, etc.)
 * - Updated API to return state_rank and percentile-based letter_grade
 * - iOS app displays state rank and corrected grades
 *
 * Version 0.6.0 - ENHANCED DATA & RANKINGS (Dec 20, 2025)
 * - Added MassCore completion import (E2C Hub dataset a9ye-ac8e)
 * - Added school-level expenditure import (E2C Hub dataset i5up-aez6)
 * - Added pathways/programs import (CTE, Innovation, Early College - dataset 9p45-t37j)
 * - Added Early College participation import (dataset p2yd-4gvj)
 * - Added 4 new import cards to admin import page
 * - Working on ranking calculator for composite school scores
 *
 * Version 0.5.3 - STAFFING & SPENDING IMPORT (Dec 19-20, 2025)
 * - Added staffing import from E2C Hub (dataset j5ue-xkfn)
 * - Aggregates teacher FTE, admin FTE, support FTE per school
 * - Added district spending import (dataset er3w-dyti)
 * - Includes: avg teacher salary, expenditures per pupil, student-teacher ratio
 *
 * Version 0.5.2 - DEMOGRAPHICS IMPORT (Dec 19, 2025)
 * - Added demographics import from E2C Hub (dataset t8td-gens)
 * - Imports: enrollment, race/ethnicity, gender, free/reduced lunch, ELL, special ed
 * - Maps to schools via state_school_id (org_code)
 * - Supports multi-year data (2000-2025 available)
 *
 * Version 0.5.1 - GEOCODING (Dec 19, 2025)
 * - Added geocoder class for batch geocoding schools without coordinates
 * - Added admin geocoding REST endpoints (/admin/geocode/status, /admin/geocode/run)
 * - Uses Nominatim (OpenStreetMap) with rate limiting
 *
 * Version 0.5.0 - PLATFORM INTEGRATION (Dec 19, 2025)
 * - Added MLS plugin integration hooks (bmn_schools_for_location, bmn_district_for_location)
 * - Added shortcodes ([bmn_nearby_schools], [bmn_school_info], [bmn_district_info], [bmn_top_schools])
 * - Added iOS-optimized endpoints (/property/schools, /schools/map)
 * - Added frontend CSS for shortcode display
 *
 * Version 0.4.0 - ENHANCED FEATURES (Dec 19, 2025)
 * - Added cache manager for API responses
 * - Added school comparison endpoint (/schools/compare)
 * - Added trend analysis endpoints (/schools/{id}/trends, /districts/{id}/trends)
 * - Added top schools endpoint (/schools/top)
 *
 * Version 0.3.0 - BOUNDARIES & ENHANCED DATA (Dec 19, 2025)
 * - Added NCES EDGE provider for district boundaries (GeoJSON)
 * - Added Boston Open Data provider for BPS schools
 * - Added point-in-polygon API for district lookup
 * - Enabled all import buttons in admin
 *
 * Version 0.2.0 - DATA PROVIDERS (Dec 19, 2025)
 * - Added data provider base class
 * - Added MassGIS provider for school locations
 * - Added MA DESE provider for MCAS test scores
 * - Added admin import interface
 *
 * Version 0.1.0 - INITIAL FOUNDATION (Dec 19, 2025)
 * - Created plugin structure following existing BMN patterns
 * - Database manager with 10 tables
 * - Activity logger for debugging
 * - Basic REST API endpoints
 * - Admin dashboard
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Plugin version.
 * CRITICAL: Update in 3 locations when changing:
 * 1. This constant
 * 2. Plugin header above
 * 3. version.json
 */
define('BMN_SCHOOLS_VERSION', '0.6.39');
define('BMN_SCHOOLS_DB_VERSION', '0.6.38');
define('BMN_SCHOOLS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BMN_SCHOOLS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BMN_SCHOOLS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Debug mode - set to true for verbose logging
 */
if (!defined('BMN_SCHOOLS_DEBUG')) {
    define('BMN_SCHOOLS_DEBUG', false);
}

/**
 * The core plugin class.
 */
require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-bmn-schools.php';

/**
 * Begins execution of the plugin.
 *
 * @since 0.1.0
 */
function bmn_schools() {
    return BMN_Schools::instance();
}

/**
 * Plugin activation hook.
 */
function bmn_schools_activate() {
    require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-activator.php';
    BMN_Schools_Activator::activate();
}

/**
 * Plugin deactivation hook.
 */
function bmn_schools_deactivate() {
    require_once BMN_SCHOOLS_PLUGIN_DIR . 'includes/class-deactivator.php';
    BMN_Schools_Deactivator::deactivate();
}

register_activation_hook(__FILE__, 'bmn_schools_activate');
register_deactivation_hook(__FILE__, 'bmn_schools_deactivate');

// Initialize the plugin
add_action('plugins_loaded', 'bmn_schools');
