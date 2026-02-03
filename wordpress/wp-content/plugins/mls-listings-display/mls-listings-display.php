<?php
/**
 * Plugin Name:       MLS Listings Display
 * Plugin URI:        https://example.com/
 * Description:       Displays real estate listings from the Bridge MLS Extractor Pro plugin using shortcodes with mobile-optimized property search and display.
 * Version: 6.75.0
 * Author:            AZ Home Solutions LLC
 * Author URI:        https://example.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mls-listings-display
 *
 * @package           MLS_Listings_Display
 *
 * Version 6.75.0 - CMA PDF: iOS MANUAL ADJUSTMENTS INTEGRATION (Feb 3, 2026)
 * Updated CMA PDF generation endpoint to receive and apply manual adjustments from iOS:
 * - Added subject_condition parameter for relative condition comparisons
 * - Added manual_adjustments parameter with per-comparable condition, pool, waterfront
 * - Applied relative condition adjustments: (subject% - comp%) × price
 * - Applied pool (-$50K) and waterfront (-$200K) adjustments when comp has feature
 * - Recalculated estimated value range using adjusted prices
 * - Added subject_condition_label to PDF data for display
 *
 * Version 6.74.13 - CMA MOBILE API: ADD MARKET CONTEXT AND ADJUSTMENTS (Feb 3, 2026)
 * Enhanced the iOS CMA endpoint to return market context and detailed adjustments data
 * for the iOS app enhancement (Phase 1 & 2 of CMA Enhancement Plan)
 * - Added market_context object with market_type, avg_sp_lp_ratio, avg_dom, monthly_velocity
 * - Added adjustments object per comparable with itemized breakdowns (sqft, beds, baths, year, garage)
 * - Added adjusted_price for each comparable (sold_price + total_adjustment)
 * - Added gross_pct and net_pct adjustment percentages with warnings if >25% net
 * - Added avg_dom to response summary calculated from comparables
 * - Added year_built and garage_spaces to subject and comparable objects
 * - Created calculate_mobile_adjustments() helper function for adjustment calculations
 *
 * Version 6.74.8 - FIX AGENT-CLIENT RELATIONSHIP COUNT DISCREPANCIES (Feb 2, 2026)
 * Fixed bug where client counts showed incorrect numbers after unassign operations
 * Root causes:
 * 1. unassign_client() returned true even when 0 rows were updated (silent failure)
 * 2. Legacy data might have agent_profile.id instead of WordPress user_id as agent_id
 *    but unassign operations only checked for user_id
 * Fixes:
 * - unassign_client() now checks both agent_profile.id AND WordPress user_id
 * - Returns true only when at least 1 row was actually updated
 * - Fixed same issue in unassign_all_clients() and remove_agent_client_relationship()
 * - Added cleanup_orphaned_relationships() utility to fix existing stale data
 * - Added get_relationship_stats() for debugging relationship integrity
 * - Added AJAX handlers for admin cleanup functionality
 *
 * Version 6.74.7 - FIX CMA PDF COMPARABLES NOT DISPLAYING (Feb 2, 2026)
 * Fixed comparables section in PDF showing empty (no comparable cards)
 * Root cause: PDF generator filters comparables by grade (A/B only) and requires
 * specific fields that weren't being provided
 * - Added comparability_grade calculation (A-F based on similarity to subject)
 * - Added all required fields: unparsed_address, list_price, adjusted_price,
 *   bedrooms_total, bathrooms_total, building_area_total, year_built,
 *   standard_status, days_on_market, adjustments object
 * - Grade calculation considers: distance, sqft difference, bedroom difference
 *
 * Version 6.74.6 - FIX CMA PDF SHOWS "INSUFFICIENT DATA" (Feb 2, 2026)
 * Fixed PDF generator showing blank data despite comparables being found
 * Root cause: Data structure mismatch - REST API passed flat structure but
 * PDF generator expected nested 'summary' object with specific fields
 * - Rebuilt $cma_data to match PDF generator's expected structure
 * - Added proper 'summary' object with estimated_value, total_found, avg_price, etc.
 * - Added median price, average DOM, and price per sqft calculations
 * - Added confidence level calculation (high/medium/low based on comparable count)
 *
 * Version 6.74.5 - FIX CMA PDF GENERATION 404 ERROR (Feb 2, 2026)
 * Fixed "Failed to Generate PDF: this information is no longer available" error in iOS CMA
 * - PDF endpoint was returning 404 when property lookup failed
 * - Added fallback lookup strategies: try listing_key first, then listing_id (MLS number)
 * - Matches property detail endpoint lookup behavior which handles both formats
 * - Fixed comparables exclusion to use subject's listing_key instead of input parameter
 *
 * Version 6.74.4 - FIX CMA TOOL NOT FINDING COMPARABLES (Feb 2, 2026)
 * Fixed "Unable to estimate" error in iOS CMA tool for all properties
 * Root cause: CMA query searched active table for closed listings, but closed
 * listings are moved to archive table after sale (per BME v4.0.15+ archive system)
 * - Changed to search bme_listing_summary_archive for closed comparable sales
 * - Also check archive table when looking up subject property
 * - Changed date filter from modification_timestamp to close_date (actual sale date)
 * - Extended lookback period from 6 months to 12 months for more comparables
 *
 * Version 6.74.3 - FIX WEB ANALYTICS PLATFORM IDENTIFICATION (Feb 1, 2026)
 * Fixed web activity tracking showing "unknown" platform instead of "web"
 * - JavaScript tracker now sends platform: 'web' and device_info in batch requests
 * - PHP endpoint injects platform into each activity (consistent with iOS behavior)
 *
 * Version 6.74.2 - FIX AGENT LOGIN NOTIFICATIONS NOT FIRING (Feb 1, 2026)
 * Fixed bug where agent notifications weren't being sent for client logins, app opens, etc.
 * Root cause: get_client_agent() in MLD_Agent_Client_Manager had incorrect JOIN condition
 * - BUG: INNER JOIN agent_profiles ON agent_id = ap.id (profile auto-increment ID)
 * - FIX: INNER JOIN agent_profiles ON agent_id = ap.user_id (WordPress user ID)
 * The agent_client_relationships.agent_id stores WordPress user_id, not profile.id
 * Only client 53 worked accidentally because they had a relationship with agent_id = 4
 * which happened to match profile.id = 4 by coincidence.
 *
 * Version 6.74.1 - FIX REGISTRATION INTERNAL SERVER ERROR (Feb 1, 2026)
 * Fixed fatal error during user registration caused by missing filter callback:
 * - MLD_Email_Validator::init() registered 'pre_user_email' filter pointing to
 *   non-existent method 'validate_user_email_on_create'
 * - When wp_create_user() ran, WordPress called the missing method → fatal error → HTTP 500
 * - Removed the broken filter hook (validation already handled by wp_pre_insert_user_data filter)
 * Files: class-mld-email-validator.php
 *
 * Version 6.74.0 - CMA BANK APPRAISAL ALIGNMENT (Feb 1, 2026)
 * Enhanced CMA comparable sales engine to align with bank appraisal standards:
 * - Market-derived time adjustments: Now uses actual market appreciation data from
 *   MLD_Market_Forecasting instead of static 6% rate, per Fannie Mae guidelines
 * - Tighter default radius: Changed from 3 miles to 1 mile (bank appraisal standard)
 *   with auto-expansion if insufficient comps found (1→2→3→5→10 mile tiers)
 * - Lot size adjustments: Added for non-condo properties (2% per 0.25 acre, 10% cap)
 * - Bracketing verification: Ensures at least one superior and one inferior comp,
 *   flags when value is not properly bracketed (required by bank appraisals)
 * Files: class-mld-comparable-sales.php
 *
 * Version 6.73.3 - CMA PDF GENERATOR DATABASE SCHEMA FIX (Jan 31, 2026)
 * Fixed database column mismatches in CMA PDF photo retrieval:
 * - get_subject_photo_url(): Changed from is_primary=1 (doesn't exist) to main_photo_url from summary tables
 * - get_comparable_photo_url(): Changed from is_primary/display_order to order_index
 * - Removed query to wp_bme_media_archive (table doesn't exist)
 * - Added fallback chain: photo_url > main_photo > main_photo_url > summary table > media table
 * - Now queries summary/summary_archive tables first for main_photo_url (more reliable)
 *
 * Version 6.73.2 - CMA PDF GENERATOR MOBILE-OPTIMIZED OVERHAUL (Jan 31, 2026)
 * Comprehensive enhancement of CMA PDF reports for mobile device readability:
 * - Font sizes increased 40-50% throughout (section headers: 22pt, body: 14pt)
 * - Cover page now displays agent profile photo prominently
 * - Full agent contact info section with photo, name, title, phone, email, license
 * - Agent profile loaded from MLD_Agent_Client_Manager::get_agent_for_api() or Team Member CPT
 * - Comparable property cards enlarged with larger photos (75pt wide)
 * - More property details displayed (lot size, garage, property type, MLS#)
 * - NEW: City Market Trends section showing monthly sales activity
 * - Subject property section enhanced with tax and HOA information
 * - 2 comparable cards per page (was 3) for better readability
 * - Up to 8 comparables shown (was 6)
 * - Disclaimer page includes agent contact footer
 *
 * Version 6.73.1 - BOT REGISTRATION PREVENTION (Jan 28, 2026)
 * Multi-layered invisible protection against bot registrations:
 * - Layer 1: Honeypot field - hidden field bots auto-fill but humans never see
 * - Layer 2: Time-based validation - rejects submissions faster than 3 seconds
 * - Layer 3: Disposable email blocking - blocks 100+ temp email domains (mailinator, etc.)
 * - Layer 4: Gibberish pattern detection - detects keyboard walks, excessive consonants, test patterns
 * - Bot detections return fake "success" to avoid tipping off bots
 * - Disposable/gibberish emails return clear error messages
 * - Both web (class-mld-referral-signup.php) and iOS (class-mld-mobile-rest-api.php) protected
 * - Debug logging for blocked attempts when WP_DEBUG is enabled
 * - New class: class-mld-email-validator.php for email validation utilities
 *
 * Version 6.72.4 - MOBILE PROPERTY PAGE MODAL OVERLAY FIX (Jan 27, 2026)
 * Fixed orphan modal overlay blocking touch events on mobile property pages:
 * - Added CSS to hide all .mld-modal-overlay by default on mobile property pages
 * - Only show overlay when parent modal has .active class
 * - Prevents gallery and page becoming unresponsive on page load
 * See CLAUDE.md pitfall #42 for debugging tips
 *
 * Version 6.72.3 - SAVED SEARCH CROSS-PLATFORM CONSISTENCY (Jan 27, 2026)
 * Fixed saved search filters to work identically across iOS and web:
 * - Added beds_min key mapping in shared query builder (normalizes to beds)
 * - iOS now saves beds as beds_min (integer) instead of beds (was inconsistent)
 * - Both platforms now use identical filter key format for saved searches
 *
 * Version 6.72.2 - WEB BEDS FILTER ALIGNED WITH iOS (Jan 27, 2026)
 * Changed web beds filter from multi-select to min-only to match iOS behavior:
 * - UI: Changed from checkbox buttons (1, 2, 3) to min-only buttons (1+, 2+, 3+)
 * - JS: Uses beds_min instead of beds array, uses handleMinOnlySelection
 * - PHP: Added beds_min parameter support in class-mld-query.php
 * This ensures saved searches work identically on both platforms.
 *
 * Version 6.72.1 - INSTANT NOTIFICATION FILTER MATCHING GAPS FIXED (Jan 27, 2026)
 * Added missing filter checks to instant notification matching:
 * - matches_lot_size(): lot_size_min, lot_size_max
 * - matches_parking(): garage_spaces_min, parking_total_min
 * - matches_amenities(): pool, fireplace, waterfront, view, cooling, spa, virtual tour, senior community
 * - matches_rental_filters(): pets_dogs, pets_cats, pets_none, pets_negotiable, laundry_features
 * - matches_special_filters(): exclusive_only, price_reduced, max_dom, min_dom, open_house_only
 * - Fixed matches_year_built() to also check year_built_max (was only checking min)
 * Ensures notification matching parity with live query filters for all saved search criteria.
 *
 * Version 6.68.23 - COMPARABLE SALES AUDIT IMPROVEMENTS (Jan 23, 2026)
 * Security & Rate Limiting:
 * - Moved nonce check before rate limiting to prevent rate limit exhaustion attacks
 * - Increased rate limit to 30 req/min for logged-in users, 15 for anonymous
 * - Added CDN IP detection (X-Forwarded-For, X-Real-IP, CF-Connecting-IP)
 * Cache & Validation:
 * - Fixed cache key to include all 17+ filter parameters (was only 4)
 * - Added coordinate validation (-90/90 lat, -180/180 lng)
 * - Added numeric range validation (radius, limit, percentages)
 * - Added status whitelist validation for JSON input
 * Price & Score Fixes:
 * - Added price validation to skip comparables with NULL prices
 * - Fixed comparability score adjustment weighting (removed /2 divisor)
 * - Fixed confidence calculator to return 0 for < 3 comparables (FHA requirement)
 * Logging & Error Handling:
 * - Added market data failure logging when using defaults
 * - Enhanced frontend AJAX error detection (429, 403, 500, 504, network errors)
 *
 * Version 6.68.22 - COMPARABLE PROPERTIES ARCHIVE TABLE FIX (Jan 23, 2026)
 * - Fixed comparable sales querying wrong table (active vs archive)
 * - Closed listings are stored in bme_listing_summary_archive, not bme_listing_summary
 * - Now queries archive table when status filter is 'Closed' only
 * - Combined with v6.68.21 COALESCE fix for complete solution
 *
 * Version 6.68.21 - COMPARABLE PROPERTIES NULL CLOSE_PRICE FIX (Jan 23, 2026)
 * - Fixed comparable sales returning 0 results when close_price is NULL in database
 * - Used COALESCE(close_price, list_price) to fall back to list price for filtering
 * - Fixed date filter using wrong parameter (sold_within_days → months_back)
 * - Changed date calculation from date() to wp_date() for WordPress timezone compliance
 *
 * Version 6.68.20 - FLOOR LAYOUT BEDROOM COUNT FIX (Jan 23, 2026)
 * - Fixed bedroom counting to check bathroom FIRST (v6.68.19 bug fix)
 * - "Master Bathroom" was being counted as both bedroom AND bathroom
 * - Added "suite" to bedroom detection for "Primary Suite", "Master Suite" room types
 * - iOS app has matching fix in FloorLevel.swift and RoomDetailRow.swift
 *
 * Version 6.68.19 - FLOOR LAYOUT ROOM LEVEL TRACKING (Jan 23, 2026)
 * - Enhanced rooms API with level status tracking (has_level, is_likely_placeholder flags)
 * - Added normalize_room_level() to standardize MLS level strings (First, Second, etc.)
 * - Extract special rooms (Bonus Room, In-Law Apt, etc.) from interior_features
 * - Added infer_special_room_level() to guess floor levels for special rooms based on context
 * - Added computed_room_counts with bedrooms/bathrooms counts and special room indicators
 * - Added special_rooms array with is_special and level_inferred flags
 * - Rooms now sorted by level (known floors first, unknown last)
 * - iOS app v349: FloorLayoutModalView displays rooms grouped by floor with "Unknown Floor" section
 *
 * Version 6.68.18 - iOS UNIVERSAL LINKS SUPPORT (Jan 21, 2026)
 * - Added Apple App Site Association (AASA) file served at /.well-known/apple-app-site-association
 * - Enables iOS Universal Links for property URLs (bmnboston.com/property/*)
 * - Users with the app installed will have property pages open directly in the app
 * - Works for links shared via email, text messages, social media, etc.
 * - AASA file includes applinks and webcredentials configurations
 * - iOS app v342: Added universal link handler to BMNBostonApp.swift
 * - iOS app v342: Added Associated Domains entitlement (applinks:bmnboston.com)
 *
 * Version 6.68.17 - ENHANCED PROPERTY PAGE APP PROMPT (Jan 21, 2026)
 * - Redesigned inline card with more prominent dark blue gradient design
 * - Added sticky header bar that appears when scrolling past the main card (iOS only)
 * - Implemented smart deep linking: opens app if installed, otherwise App Store
 * - Deep link format: bmnboston://property/{mls_number}
 * - Single dismiss button dismisses both card and sticky bar
 * - Smooth animations for sticky bar appearance/disappearance
 *
 * Version 6.68.16 - PROPERTY PAGE iOS APP DOWNLOAD PROMPT (Jan 21, 2026)
 * - Added contextual inline card on property detail pages encouraging iOS app download
 * - Shows after "About This Home" section for natural content flow
 * - Mobile (iOS): Shows phone icon, "Continue in the App" message, "Open App" button
 * - Desktop: Shows same card but with QR code instead of button
 * - Separate cookie (mld_app_property_card_dismissed) from global banner (14-day dismissal)
 * - Detection is client-side JavaScript for CDN cache compatibility
 * - Does not show on Android (no Android app) or in-app browser
 * - New hook: mld_after_property_description for template integration
 *
 * Version 6.68.15 - NEW SEARCH LOOKBACK FIX (Jan 21, 2026)
 * - Fixed: New saved searches no longer receive alerts for properties added before search creation
 * - Root cause: Fifteen-minute processor and instant matcher didn't filter by search creation date
 * - Added change_detected_at to MLD_Change_Detector result for timestamp comparison
 * - MLD_Fifteen_Minute_Processor now compares change_detected_at with search.created_at
 * - MLD_Instant_Matcher now compares listing.modification_timestamp with search.created_at
 * - All handler methods updated: handle_new_listing, handle_updated_listing,
 *   handle_price_reduction, handle_status_change
 * - Users now only receive notifications for properties added/changed AFTER their search was created
 *
 * Version 6.68.14 - SCHOOL GRADE METHOD CONSISTENCY FIX (Jan 20, 2026)
 * - Fixed: School filter now uses same grade calculation as API display
 * - Bug: Norfolk showed B+ in app but filter saw A- (different methods)
 * - get_district_average_grade_by_city() averaged school percentiles (79% → A-)
 * - get_district_grade_for_city() uses district_rankings table (62% → B+)
 * - Fix: Both matchers now use get_district_grade_for_city() for consistency
 * - Users will now see same grades in filter results as shown on property details
 *
 * Version 6.68.13 - INSTANT NOTIFICATION SCHOOL FILTER SUPPORT (Jan 20, 2026)
 * - Fixed: Instant notifications now apply school filters (school_grade, near_a_elementary, etc.)
 * - Root cause: MLD_Instant_Matcher was missing school criteria checks (15-min/digest had it)
 * - Added matches_school_criteria() method to class-mld-instant-matcher.php
 * - Added grade_meets_minimum() helper method for grade comparison
 * - BMN Schools Integration now loaded by instant-notifications-init.php
 * - Supports all school filters: school_grade, school_district_id, near_a_elementary,
 *   near_ab_elementary, near_a_middle, near_ab_middle, near_a_high, near_ab_high
 * - Graceful degradation if BMN Schools plugin not available
 *
 * Version 6.68.8 - SCHOOL FILTER GRADE ROUNDING FIX (Jan 19, 2026)
 * - Fixed: District grade calculation was rounding up borderline cases
 * - Bug: Dartmouth (69.8 percentile) was rounded to 70 → A- instead of B+
 * - Fix: Removed round() in get_all_district_averages() to use raw percentile
 * - Now 69.8 percentile correctly returns B+ (since 69.8 < 70)
 * - School filter for "A" districts now correctly excludes B+ districts
 * - Includes debug logging from 6.68.7 for continued monitoring
 *
 * Version 6.68.7 - SCHOOL FILTER DEBUG LOGGING (Jan 19, 2026)
 * - Added debug logging to diagnose school filter bypass in saved search notifications
 * - MLD_Enhanced_Filter_Matcher::matches_school_criteria() now logs class availability
 * - Logs district grade lookups and grade comparison results
 * - MLD_Fifteen_Minute_Processor::load_dependencies() logs BMN Schools Integration loading
 * - Debug logging helps identify why school filters may be bypassed (e.g., class not loaded)
 * - To be removed after diagnosis is complete
 *
 * Version 6.68.1 - SCHOOL FILTER MATCHING FOR SAVED SEARCH NOTIFICATIONS (Jan 19, 2026)
 * - Fixed: Saved searches with school filters now correctly filter notifications
 * - Added matches_school_criteria() to MLD_Enhanced_Filter_Matcher class
 * - Supports: school_grade (district average), school_district_id, near_a_elementary,
 *   near_ab_elementary, near_a_middle, near_ab_middle, near_a_high, near_ab_high
 * - Also supports legacy filters: near_top_elementary, near_top_high
 * - BMN Schools Integration dependency loaded by MLD_Fifteen_Minute_Processor
 * - Graceful degradation: school filters skipped if BMN Schools plugin not active
 *
 * Version 6.68.0 - DIRECT PROPERTY LOOKUP FILTER BYPASS (Jan 19, 2026)
 * - Fixed: MLS number and address searches now bypass restrictive filters
 * - When searching for a specific property via autocomplete (MLS# or address),
 *   the API now returns that property regardless of active filters (status, price, beds, etc.)
 * - This matches the behavior already implemented in class-mld-query.php (web path)
 * - Bypassed filters: status, price (min/max), beds, baths, sqft, year built, lot size,
 *   property type, property sub type, and map bounds
 * - MLS number and address parameters ARE the search criteria (kept active)
 * - Street name searches still use filters (partial match, not direct lookup)
 *
 * Version 6.67.3 - PROPERTY HISTORY TIMEZONE FIX (Jan 19, 2026)
 * - Fixed event dates showing in future due to UTC/local timezone mismatch
 * - Event dates from bme_property_history now converted from UTC to EST (ISO8601 with timezone)
 * - Fixed granular time on market calculation for tracked history timestamps
 * - Added listing_timestamp_is_utc flag to handle different timestamp sources
 * - Deduplication of history events by (event_type, date, price)
 * - Status change events now show actual old_status from database (not hardcoded)
 *
 * Version 6.67.0 - ADMIN PUSH NOTIFICATION DASHBOARD REFACTOR (Jan 18, 2026)
 * - Refactored 3 instant-notifications admin pages to use real push notification data
 * - Dashboard now queries wp_mld_push_notification_log instead of unused mld_search_activity_matches
 * - Shows actual sent/failed counts, success rate percentage
 * - Charts display 7-day sent vs failed breakdown
 * - Activity tables show notification_type, listing_id (from payload JSON), APNs reason for failures
 * - Fixed timezone handling: database stores in WordPress timezone (EST), properly converted for display
 * - Updated menu labels: "Push Notifications", "Notification Activity"
 * - "Clear Pending Queue" button repurposed to "Clear Old Failed Logs" (deletes failed logs >7 days)
 * - Notification type filter expanded: new_listing, price_change, status_change, appointment types, test
 *
 * Version 6.58.0 - AUTOMATED HEALTH MONITORING SYSTEM (Jan 11, 2026)
 * - NEW: MLD_Health_Monitor class for unified health checks across MLD, Schools, SNAB
 * - NEW: MLD_Health_Alerts class for email notifications on health degradation
 * - NEW: MLD_Health_CLI class for WP-CLI commands (wp health check/status/history/cleanup)
 * - NEW: REST endpoints /unified-health and /ping for external monitoring (Uptime Robot, Pingdom)
 * - NEW: Health check history stored in wp_mld_health_history table
 * - NEW: Alert throttling to prevent email spam (configurable)
 * - NEW: Recovery alerts when system health is restored
 * - NEW: Exit codes for CLI scripts (0=healthy, 1=degraded, 2=unhealthy)
 * - NEW: CDN bypass headers on health endpoints for accurate monitoring
 *
 * Version 6.55.0 - REFRESH TOKEN ROTATION SECURITY (Jan 11, 2026)
 * - SECURITY: Implemented refresh token rotation with blacklist
 * - Each refresh token can now only be used ONCE (single-use tokens)
 * - Revoked tokens stored in wp_mld_revoked_tokens table with expiration tracking
 * - Token reuse attempts are logged for security monitoring
 * - Refresh endpoint now returns full user data (keeps iOS in sync)
 * - Added cleanup method for expired revoked tokens
 * - Database schema version bumped to 1.13.0
 *
 * Version 6.54.5 - JSON DECODE VALIDATION (Jan 10, 2026)
 * - SECURITY: Added json_last_error() validation to all json_decode() calls
 * - Prevents crashes from malformed JSON in cached boundary data
 * - Prevents silent failures in CMA session/comparables data
 * - Prevents errors in saved search polygon/filter parsing
 * - Validates external API (Nominatim) responses before processing
 *
 * Version 6.54.4 - PUBLIC API RATE LIMITING (Jan 10, 2026)
 * - SECURITY: Added rate limiting to public API endpoints to prevent DoS attacks
 * - Properties endpoint: 60 requests/minute per IP
 * - Property detail/history: 120 requests/minute per IP
 * - Autocomplete: 120 requests/minute per IP
 * - Added proper CDN/proxy IP detection (X-Real-IP, X-Forwarded-For, CF-Connecting-IP)
 * - Returns 429 with Retry-After header when rate limited
 *
 * Version 6.54.3 - SECURITY HARDENING (Jan 10, 2026)
 * - SECURITY: Added JWT algorithm validation to prevent "none" algorithm attack
 * - SECURITY: Added check_agent_auth() for all agent endpoints (prevents client access to agent data)
 * - SECURITY: Removed user_id impersonation from analytics auth callback
 * - SECURITY: Added cache headers to login/refresh endpoints to prevent CDN token caching
 * - SECURITY: Removed debug logging from check_auth (was exposing user IDs and emails)
 * - SECURITY: Fixed base64url_decode to validate input and use strict mode
 *
 * Version 6.54.2 - CLIENT WELCOME EMAIL ERROR HANDLING (Jan 10, 2026)
 * - Fixed silent failure when welcome email fails to send
 * - create_client() now returns array with user_id and email_sent status
 * - REST API and Admin AJAX handlers updated to report email failures
 * - Log warning when email send fails for troubleshooting
 *
 * Version 6.53.0 - NOTIFICATION DEDUPLICATION FIX (Jan 10, 2026)
 * - Fixed duplicate notifications appearing in Notification Center on login
 * - Added server-side deduplication: /notifications/history now groups by (user, type, listing, hour)
 * - Added was_recently_sent() check to prevent duplicate notification sends within 1 hour
 * - Per-device logging is intentional for APNs delivery tracking but now deduplicated for display
 *
 * Version 6.52.3 - LOGIN REDIRECT FIX (Jan 10, 2026)
 * - Fixed login redirect to use /my-dashboard/ instead of /dashboard/
 * - Updated referral signup page, listing update emails, and referral signup class
 *
 * Version 6.52.2 - iOS REFERRAL STATS API FIX (Jan 9, 2026)
 * - Fixed "Failed to load referral data" error in iOS app
 * - Updated /agent/referral-stats endpoint response format to match iOS model
 * - Now returns: total_referrals, this_month, last_three_months, by_month
 * - Added monthly breakdown with readable month format (e.g., "January 2026")
 *
 * Version 6.52.1 - ADMIN AGENT MANAGEMENT FIXES (Jan 9, 2026)
 * - Fixed "Call to undefined method get_agent_profile()" error when setting default agent
 * - Corrected method call to use get_agent() instead of get_agent_profile()
 * - Fixed CSS class prefix mismatch: changed .bme- to .mld- to match HTML template classes
 * - Agent profile images now display correctly in circular frames on admin page
 *
 * Version 6.52.0 - AGENT REFERRAL LINK SYSTEM (Jan 9, 2026)
 * - Added automatic agent-client matching with referral links
 * - New MLD_Referral_Manager class for referral code management
 * - New database tables: wp_mld_agent_referral_codes, wp_mld_referral_signups
 * - Default agent assignment for organic signups via WordPress option
 * - REST API endpoints: GET/POST agent/referral-link, GET agent/referral-stats
 * - Admin UI: "Set as Default" button, referral link copy button, stats display
 * - Web dashboard: Referral Link card for agent users in Profile tab
 * - Dedicated signup page: /signup?ref=CODE with agent intro
 * - iOS v209: AgentReferralView for sharing referral link, copy/share buttons
 *
 * Version 6.51.1 - WEB NOTIFICATION CENTER FIX (Jan 9, 2026)
 * - Fixed fatal error in notification preferences AJAX handler
 * - Changed get_all_preferences() to get_preferences() (correct method name)
 * - Fixed update_preference() to use update_preferences() with proper array format
 * - Created /notifications/ and /notification-settings/ WordPress pages
 *
 * Version 6.51.0 - ACCOUNT DELETION FEATURE (Jan 9, 2026)
 * - Apple App Store Guideline 5.1.1(v) compliance: Users can now delete their accounts
 * - Added DELETE /auth/delete-account REST API endpoint
 * - Deletes all user data from 20+ database tables (saved searches, favorites, hidden, etc.)
 * - Sends confirmation email after successful account deletion
 * - Added account deletion section to web dashboard Settings tab
 * - Added Vue.js confirmation modal requiring user to type "DELETE" to confirm
 * - Auto-reassigns agent's clients to admin before agent account deletion
 *
 * Version 6.50.10 - RICH NOTIFICATIONS FOR AGENT ACTIVITY (Jan 9, 2026)
 * - Added enrich_property_context() method to MLD_Agent_Activity_Notifier
 * - Agent notifications for favorite_added and tour_requested now include property images
 * - Notifications include listing_key for deep linking to property details
 * - Property address fetched from MLD summary tables if not already in context
 * - Enables iOS Notification Service Extension to display property thumbnails
 *
 * Version 6.50.9 - AGENT NOTIFICATION ON WEB CLIENT LOGIN (Jan 9, 2026)
 * - Added wp_login hook to trigger agent notification when clients log in via web
 * - Previously, agent only received notification when client logged in via iOS app
 * - Now agents are notified for BOTH iOS and web logins
 * - Uses existing mld_client_logged_in hook with platform='web' parameter
 * - Only triggers for users with 'client' user type (not agents/admins)
 *
 * Version 6.50.8 - TOKEN EXPIRATION & RATE LIMITING FIX (Jan 9, 2026)
 * - Increased access token expiration from 15 minutes to 30 days
 * - Increased refresh token expiration from 7 days to 30 days
 * - Fixes issue where users were unexpectedly logged out after 30-60 minutes of inactivity
 * - Users now stay logged in for 1 month unless they manually log out
 * - RATE LIMITING: Increased max login attempts from 5 to 20 before lockout
 * - RATE LIMITING: Reduced lockout duration from 15 minutes to 5 minutes
 * - Fixes issue where users were getting locked out too easily
 *
 * Version 6.50.7 - STALE TOKEN CLEANUP, QUIET HOURS QUEUING & EMAIL PREFERENCES (Jan 9, 2026)
 * - Added cleanup_stale_tokens() method to deactivate tokens unused for 90+ days
 * - Added get_device_token_stats() method for monitoring token statistics
 * - Added daily cron job (mld_cleanup_stale_device_tokens) running at 5:00am
 * - Inactive tokens older than 180 days are automatically deleted
 * - QUIET HOURS QUEUING: Notifications blocked by quiet hours are now queued for later
 * - Added wp_mld_deferred_notifications table to store queued notifications
 * - Added process_deferred_notifications() method to send queued notifications
 * - Cron job (mld_process_deferred_notifications) runs every 15 minutes to process queue
 * - Users no longer miss important notifications that arrive during quiet hours
 * - EMAIL PREFERENCES: Property change emails now respect user notification preferences
 * - Price change emails filtered by price_change email preference
 * - Status change emails filtered by status_change email preference
 * - Push notifications for property changes now respect quiet hours (queued for later)
 *
 * Version 6.50.6 - NOTIFICATION TIMEZONE FIX (Jan 9, 2026)
 * - FIX: Notification timestamps now correctly use WordPress timezone (America/New_York)
 * - Previously strtotime() interpreted MySQL datetimes as UTC, causing 5-hour offset
 * - Added format_datetime_iso8601() helper that properly interprets WordPress timezone
 * - Notifications now show correct "time ago" in iOS Notification Center
 *
 * Version 6.50.5 - JWT AUTH FIX: Wrong User After App Restart (Jan 8, 2026)
 * - CRITICAL FIX: check_auth() now prioritizes JWT over WordPress session cookies
 * - Previously, if a WordPress session cookie existed (from web login), the JWT was ignored
 * - This caused /me endpoint to return wrong user when iOS app had JWT from user A but
 *   WordPress session cookie existed from user B's previous web login
 * - Now: If JWT is present in Authorization header, ALWAYS use JWT (ignore cookies)
 * - Added debug logging to check_auth() and handle_get_me() for troubleshooting
 *
 * Version 6.50.3 - DISMISS ALL NOTIFICATIONS ENDPOINT (Jan 8, 2026)
 * - Added POST /notifications/dismiss-all endpoint to dismiss all notifications at once
 * - iOS "Clear All" now syncs with server instead of just clearing locally
 *
 * Version 6.50.2 - INCLUDE FAILED NOTIFICATIONS IN HISTORY (Jan 8, 2026)
 * - FIX: Notification history now includes status='failed' notifications (not just 'sent')
 * - FIX: Users who had BadDeviceToken errors now see their notifications in Notification Center
 * - Notification Center shows all notifications generated for user regardless of push delivery status
 * - Emails still work for failed push notifications, so users should see corresponding notifications
 *
 * Version 6.50.1 - NOTIFICATION HISTORY TYPE FIX (Jan 8, 2026)
 * - FIX: Cast listing_id to string and saved_search_id to int in notification history response
 * - FIX: iOS was failing to parse response due to type mismatch (listing_id was int, expected string)
 *
 * Version 6.50.0 - SERVER-DRIVEN NOTIFICATION CENTER (Jan 8, 2026)
 * - Added is_read, read_at, is_dismissed, dismissed_at columns to push_notification_log table
 * - Added POST /notifications/{id}/read endpoint to mark single notification as read
 * - Added POST /notifications/{id}/dismiss endpoint to dismiss a notification
 * - Added POST /notifications/mark-all-read endpoint to mark all notifications as read
 * - Updated GET /notifications/history to include is_read, is_dismissed status and unread_count
 * - Server is now source of truth for notification state (syncs across devices/reinstalls)
 *
 * Version 6.49.16 - NOTIFICATION HISTORY SYNC (Jan 8, 2026)
 * - Added GET /notifications/history endpoint for in-app notification center
 * - iOS can now sync missed notifications when app opens
 * - Returns all successfully sent notifications with listing details
 * - Supports pagination (limit/offset) and filtering (since, types)
 *
 * Version 6.49.15 - INDIVIDUAL PUSH NOTIFICATIONS (Jan 8, 2026)
 * - FIX: Always send individual push notifications per property (no more summary "X new listings" notifications)
 * - Each notification shows property details and allows direct deep link to property
 * - Capped at 25 notifications per 15-minute batch to avoid overwhelming users
 * - Respects user notification preferences for each type (new_listing, price_change, status_change, open_house)
 * - Fixed preference mapping: new_listing now maps to new_listing preference (was mapped to saved_search)
 *
 * Version 6.49.14 - WEB CONTACT AGENT ROUTING (Jan 8, 2026)
 * - Contact Agent modal now shows assigned agent info for logged-in clients
 * - Falls back to site contact settings for non-logged-in users
 * - Form submissions routed to assigned agent email when available
 * - Matches iOS app behavior for consistent cross-platform experience
 *
 * Version 6.49.12 - SITE CONTACT SETTINGS API (Jan 8, 2026)
 * - Added GET /settings/site-contact endpoint for default contact info
 * - Returns site name, phone, email, photo from theme customizer settings
 * - Used by iOS app when user doesn't have an assigned agent
 *
 * Version 6.49.11 - iOS LISTING AGENT DATA FIX (Jan 8, 2026)
 * - FIX: Property detail API now returns listing agent/office from bme_listings + bme_agents + bme_offices tables
 * - Added agent_mls_id and office_mls_id to API response
 * - Summary table doesn't have agent columns, now properly JOINs to get agent data
 *
 * Version 6.49.10 - iOS BRACKET FORMATTING FIX PART 2 (Jan 8, 2026)
 * - Fixed remaining bracket-formatted fields: lot_features, parking_features, room features
 * - These fields were in the initial property response and rooms array, not the $details section
 *
 * Version 6.49.9 - iOS BRACKET FORMATTING FIX (Jan 8, 2026)
 * - FIX: Property detail array fields now display as comma-separated strings instead of JSON brackets
 * - Added format_array_field() helper to convert ["Value1","Value2"] to "Value1, Value2"
 * - Applied to ~45 array fields: heating, cooling, flooring, appliances, basement, etc.
 * - Empty arrays [] now return null instead of displaying as empty brackets
 * - Matches web template format_array_field() behavior for iOS parity
 *
 * Version 6.49.8 - NEIGHBORHOOD AUTOCOMPLETE FIX (Jan 8, 2026)
 * - FIX: Autocomplete now searches all 3 neighborhood fields (subdivision_name, mls_area_major, mls_area_minor)
 * - Previously only searched subdivision_name, missing neighborhoods like "Back Bay" stored in mls_area_minor
 * - Now uses UNION query to search all 3 columns and return matching neighborhoods
 *
 * Version 6.49.7 - iOS NEIGHBORHOOD FILTER FIX (Jan 8, 2026)
 * - FIX: REST API was reading neighborhood parameter but never filtering by it
 * - Added WHERE clause for neighborhood filtering to all property query methods
 * - Searches subdivision_name, mls_area_major, and mls_area_minor fields
 * - Supports both single neighborhood and array (multi-select) from iOS
 * - Updated get_active_properties(), get_archive_properties(), get_combined_properties()
 *
 * Version 6.49.6 - FIX: Neighborhood filter critical error (Jan 8, 2026)
 * - FIX: Neighborhood filter causing "Cannot use object of type stdClass as array" fatal error
 * - ROOT CAUSE: Traditional query path returned objects instead of arrays
 * - AFFECTED: get_listings_for_map_traditional() and apply_school_filter() in class-mld-query.php
 * - Added ARRAY_A to wpdb->get_results() and converted object syntax to array syntax
 *
 * Version 6.49.5 - FIX: New listing notification_type payload (Jan 8, 2026)
 * - FIX: New listing push notifications now send notification_type: "new_listing" instead of "saved_search"
 * - This enables in-app Notification Center to navigate to property details for new listings
 *
 * Version 6.49.4 - PUSH NOTIFICATION SYSTEM ENHANCEMENTS (Jan 8, 2026)
 * - FEATURE: Rate limit monitoring alerts - sends admin email when utilization exceeds 80%
 * - FEATURE: Batch notification coalescing - 5+ matches send summary instead of individual notifications
 * - FEATURE: Notification engagement tracking - new database table and REST endpoints
 * - FEATURE: Rich notification image failure logging via iOS App Groups
 * - Added check_rate_limit_alert() to MLD_Push_Notifications
 * - Added coalescing logic to MLD_Fifteen_Minute_Processor (threshold: 5 matches)
 * - Added /notifications/engagement and /admin/notification-engagement-stats endpoints
 * - iOS: Token re-registration when user enables notifications from Settings
 *
 * Version 6.49.3 - PUSH NOTIFICATION QUEUE MONITORING DASHBOARD (Jan 8, 2026)
 * - FEATURE: Added Push Notification Queue section to System Health dashboard
 * - FEATURE: Shows retry queue status: pending, processing, completed, failed counts
 * - FEATURE: Shows 24-hour delivery statistics with success rate
 * - FEATURE: Shows real-time rate limiting status and utilization
 * - FEATURE: "Process Queue Now" button for manual queue processing
 * - FEATURE: Warning alerts when queue depth exceeds threshold (>50 pending)
 * - Updated class-mld-health-dashboard.php with new monitoring section
 *
 * Version 6.49.2 - NOTIFICATION PREFERENCE ENFORCEMENT (Jan 8, 2026)
 * - FEATURE: Push notifications now respect user notification preferences
 * - FEATURE: Checks if push is enabled for notification type before sending
 * - FEATURE: Respects quiet hours configuration (no push during user's quiet hours)
 * - FEATURE: Skipped notifications logged with reason for debugging
 * - Updated send_to_user(), send_property_notification() to check preferences
 * - Added map_change_type_to_preference() helper for type mapping
 *
 * Version 6.49.1 - PROACTIVE RATE LIMITING FOR PUSH NOTIFICATIONS (Jan 8, 2026)
 * - FEATURE: Proactive rate limiting to prevent APNs 429 rate limit errors
 * - FEATURE: Tracks requests per second across all notification sends
 * - FEATURE: Automatically adds delays when approaching 60% of limit (300/sec)
 * - FEATURE: Gradual slowdown as approaching limit instead of sudden blockage
 * - FEATURE: get_rate_limit_stats() for monitoring rate limiting behavior
 * - This prevents retry queue backlog during high-volume notification bursts
 *
 * Version 6.49.0 - SERVER-SIDE BADGE COUNT MANAGEMENT (Jan 8, 2026)
 * - FEATURE: Server-side badge count tracking with wp_mld_user_badge_counts table
 * - FEATURE: Badge count now syncs across all notification types (saved searches, property alerts, agent activity)
 * - FEATURE: REST API endpoints: GET /badge-count, POST /badge-count/reset
 * - FEATURE: iOS syncs badge count on app launch and after login
 * - FEATURE: Badge count resets when user views notifications
 * - Database version bumped to 1.9.0 for new badge counts table
 *
 * Version 6.48.6 - PUSH NOTIFICATION RETRY QUEUE (Jan 8, 2026)
 * - FEATURE: Added retry queue for failed push notifications with exponential backoff
 * - FEATURE: Retriable errors (429, 5xx, network) automatically queued for retry
 * - FEATURE: Max 5 retries with 1min, 2min, 4min, 8min, 16min delays
 * - FEATURE: Stale pending items expired after 24 hours
 * - FEATURE: Cron job processes retry queue every 5 minutes
 * - FEATURE: Added get_retry_queue_stats() for monitoring queue health
 * - Database version bumped to 1.8.0 for new wp_mld_push_retry_queue table
 *
 * Version 6.48.5 - PUSH NOTIFICATION DELIVERY LOGGING (Jan 8, 2026)
 * - FEATURE: Added wp_mld_push_notification_log table for tracking all push notification deliveries
 * - FEATURE: All MLD push notifications now logged with status, APNs response, and error details
 * - FEATURE: SNAB appointments plugin can now use MLD APNs credentials as fallback
 * - FEATURE: SNAB notifications logged to shared log table for unified analytics
 * - FEATURE: Added get_delivery_stats() for push notification analytics
 * - FEATURE: Added cleanup_old_logs() for 30-day log retention
 * - Database version bumped to 1.7.0 for new table
 *
 * Version 6.48.4 - PER-TOKEN SANDBOX DETECTION (Jan 7, 2026)
 * - FEATURE: Push notifications now support both TestFlight and App Store builds simultaneously
 * - FEATURE: Added is_sandbox column to wp_mld_device_tokens table
 * - FEATURE: Each device token is now sent to the correct APNs environment (sandbox or production)
 * - FEATURE: iOS app determines sandbox status during token registration
 * - This eliminates manual switching between APNs environments for testing
 *
 * Version 6.48.3 - BUNDLE ID FIX (Jan 7, 2026)
 * - FIX: Updated default APNs bundle ID from com.bmnboston.realestate to com.bmnboston.app
 * - FIX: Updated all bundle ID references in settings and notifications classes
 * - This ensures push notifications work correctly with the iOS app's actual bundle ID
 *
 * Version 6.48.2 - INDIVIDUAL PROPERTY PUSH NOTIFICATIONS (Jan 7, 2026)
 * - FEATURE: Each matching property now gets its own push notification
 * - FEATURE: Notifications include property address, city, price
 * - FEATURE: Direct deep link to property detail page (not search results)
 * - FEATURE: Different notification formats for New Listing, Price Reduced, Status Change
 * - Added send_property_notification() and build_property_payload() to MLD_Push_Notifications
 * - 100ms delay between notifications to avoid APNs rate limiting
 *
 * Version 6.48.1 - PUSH NOTIFICATIONS FOR SAVED SEARCH ALERTS (Jan 7, 2026)
 * - FEATURE: Push notifications now sent alongside emails for new listing alerts
 * - FEATURE: Users with iOS devices registered receive push when saved search matches
 * - Added send_push_notification() helper to MLD_Fifteen_Minute_Processor
 * - Integrates with MLD_Push_Notifications class for APNs delivery
 *
 * Version 6.48.0 - PLATFORM FILTER FIX (Jan 6, 2026)
 * - FIX: Platform filter dropdown now works correctly (handles specific platform values)
 * - FIX: JS apiRequest no longer overwrites platform parameter from dropdown
 * - DEBUG: Added console.log for debugging platform filter issues
 *
 * Version 6.47.1 - ACTIVITY STREAM TIMEZONE FIX (Jan 6, 2026)
 * - FIX: Activity stream now uses UTC timestamps to match database storage
 * - FIX: JS field name corrected (user_display_name instead of display_name)
 *
 * Version 6.47.0 - ENHANCED LIVE ACTIVITY LOGS (Jan 6, 2026)
 * - FEATURE: Rich visitor info (logged-in username/email, anonymous visitor hash)
 * - FEATURE: Traffic source display (Google, Facebook, Direct, etc.) with icons
 * - FEATURE: Returning visitor badge for repeat visitors
 * - FEATURE: Time range selector (15m, 1h, 4h, 24h, 7 days)
 * - FEATURE: Platform filter (Web Desktop, Web Mobile, iOS App)
 * - FEATURE: Logged-in only filter toggle
 * - FEATURE: Pagination for activity stream (50 per page)
 * - FEATURE: Session journey side panel showing navigation path
 * - NEW: REST API endpoint /admin/session/{id}/journey
 *
 * Version 6.46.0 - ANALYTICS DATA CAPTURE FIXES (Jan 6, 2026)
 * - FIX: IP detection now checks Kinsta/CDN headers (X-Real-IP, True-Client-IP, etc.)
 * - FIX: Referrer captured at init time instead of flush time (prevents internal overwrite)
 * - FIX: Search engine domains normalized (google.com, google.co.uk -> "Google")
 * - FIX: Geographic distribution limit increased from 10 to 50 cities
 * - FIX: Traffic sources limit increased from 10 to 30
 *
 * Version 6.45.8 - HEARTBEAT NONCE FIX (Jan 5, 2026)
 * - FIX: Heartbeat requests no longer send X-WP-Nonce (was causing 403 errors)
 * - FIX: Active Now count should now update correctly
 *
 * Version 6.45.7 - ANALYTICS DEBUG LOGGING (Jan 5, 2026)
 * - DEBUG: Added console.log to JS tracker to diagnose property_view tracking
 *
 * Version 6.45.6 - ANALYTICS TRENDS RANGE PARAMETER FIX (Jan 5, 2026)
 * - FIX: Traffic Trends now accepts 'range' parameter from JS (24h, 7d, 30d)
 * - FIX: Uses WordPress timezone for date calculations
 *
 * Version 6.45.5 - ANALYTICS PROPERTY VIEW + TRAFFIC TRENDS FIX (Jan 5, 2026)
 * - FIX: Property views now tracked on all property pages (fetch listing from URL)
 * - FIX: Traffic Trends chart now renders correctly (transform data for Chart.js)
 *
 * Version 6.45.4 - ANALYTICS DASHBOARD TIMEZONE FIXES (Jan 5, 2026)
 * - FIX: Dashboard stats now query events table directly for accurate counts
 * - FIX: Traffic trends chart fallback now uses events instead of session aggregates
 * - FIX: Hourly aggregation now uses WordPress timezone instead of server UTC
 * - FIX: Activity stream timestamps converted to WordPress timezone before JS display
 *
 * Version 6.45.3 - SITE ANALYTICS DASHBOARD FIXES (Jan 5, 2026)
 * - FIX: Property Views now correctly tracks SEO-friendly URLs (/property/slug-123/)
 * - FIX: Searches now tracked via mld:search_execute event dispatch
 * - FIX: Active Now count fixed (timezone mismatch in presence cleanup)
 * - FIX: Timestamps now display correctly instead of all showing "Just now"
 *
 * Version 6.45.1 - TIMEZONE FIX FOR AGENT CLIENT ACTIVITY (Jan 5, 2026)
 * - FIX: Fixed timezone mismatch in client activity date comparisons
 * - Changed date() to current_time() for WordPress timezone consistency
 * - Affected: handle_get_metrics(), handle_get_clients_for_agent()
 * - See CLAUDE.md Pitfall #10: WordPress Timezone vs PHP Timezone
 *
 * Version 6.45.0 - DRAWER UX IMPROVEMENTS (Jan 4, 2026)
 * - UX: Disabled swipe-to-close gesture on all drawer menus
 * - UX: Chatbot bubble now hides when drawer is open, reappears when closed
 * - UX: Removed Save Search button from search page drawer (available on main page)
 * - FIX: Property details page drawer now uses collapsible user menu
 *
 * Version 6.44.0 - COLLAPSIBLE USER MENU IN DRAWERS (Jan 4, 2026)
 * - UX: Drawer user menu now collapsed by default to save vertical space
 * - UX: User toggle button shows avatar (or guest icon), name, and chevron
 * - UX: Click to expand and reveal: Dashboard, Favorites, Searches, Profile, Admin, Logout
 * - UX: Guest users see "Login / Register" button that expands to show login options
 * - UX: Removed phone number footer from all drawer menus
 * - STYLE: Added teal gradient to guest toggle button
 * - STYLE: Added smooth 0.25s expand animation with fade-in effect
 * - A11Y: Full aria-expanded/aria-controls support for screen readers
 * - APPLIES TO: Theme drawer (mobile menu) and MLS plugin drawer (search/property pages)
 *
 * Version 6.43.0 - AGENT CLIENT ACTIVITY NOTIFICATIONS (Jan 4, 2026)
 * - FEATURE: Real-time notifications when assigned clients perform activities
 * - FEATURE: Per-type toggles for email and push notifications
 * - FEATURE: 2-hour debounce for app open notifications to avoid spam
 * - NEW: 5 notification triggers: login, app open, favorite, saved search, tour request
 * - NEW: REST API endpoints: /agent/notification-preferences (GET/PUT), /app/opened (POST)
 * - NEW: MLD_Agent_Activity_Notifier class - Main orchestrator with event hooks
 * - NEW: MLD_Agent_Notification_Preferences class - Per-type toggle management
 * - NEW: MLD_Agent_Notification_Log class - Logging utility for debugging
 * - NEW: MLD_Agent_Notification_Email class - Branded HTML email builder
 * - NEW: send_activity_notification() method in MLD_Push_Notifications
 * - DB: New tables: wp_mld_agent_notification_preferences, wp_mld_agent_notification_log, wp_mld_client_app_opens
 *
 * Version 6.42.1 - CLIENT PREFERENCES FIX (Jan 4, 2026)
 * - FIX: Property preferences now populate correctly
 * - FIX: Removed subdivision_name from summary table queries (column doesn't exist)
 * - FIX: Active listings use NULL for neighborhood, archive uses actual subdivision_name
 *
 * Version 6.42.0 - CLIENT PREFERENCES PROFILE (Jan 4, 2026)
 * - FEATURE: Comprehensive client preferences/profile analytics
 * - FEATURE: Location preferences (top cities, neighborhoods, ZIPs)
 * - FEATURE: Property preferences (beds, baths, sqft, price, garage, property types)
 * - FEATURE: Engagement patterns (activity by hour/day, favorites, searches)
 * - FEATURE: Profile strength score (0-100) with component breakdown
 * - NEW: REST API endpoint /agent/clients/{id}/preferences
 * - UI: Rich profile display with bar charts, stat cards, and strength meter
 *
 * Version 6.41.3 - MOST VIEWED PROPERTIES (Jan 4, 2026)
 * - FEATURE: New "Most Viewed Properties" section in Client Insights dashboard
 * - FEATURE: Shows properties viewed 2+ times by client, ordered by view count
 * - NEW: REST API endpoint /agent/clients/{id}/most-viewed
 * - ENHANCEMENT: View count badges with gradient ranking for top 3
 *
 * Version 6.41.2 - RICH ACTIVITY TIMELINE FIX (Jan 4, 2026)
 * - FIX: Activity enrichment now works for iOS activities (listing_key hash lookup)
 * - FIX: Supports both MLS number (web) and listing_key hash (iOS) entity_id formats
 *
 * Version 6.41.1 - RICH ACTIVITY TIMELINE (Jan 4, 2026)
 * - FEATURE: Activity timeline now shows full property addresses, photos, and prices
 * - FEATURE: Property-related activities link to property detail pages
 * - FEATURE: Activity icons and descriptions for 25+ activity types
 * - ENHANCEMENT: Backend enriches activity data with full property details from database
 *
 * Version 6.41.0 - CLIENT ANALYTICS DATA INTEGRITY (Jan 4, 2026)
 * - FEATURE: Data integrity hooks for agent-client analytics
 * - FEATURE: Real-time engagement score updates on client activity
 * - FEATURE: One-time data cleanup script for orphaned records
 * - NEW: MLD_Client_Analytics_Hooks class - Listens for assignment/activity events
 * - NEW: MLD_Data_Cleanup class - Fixes orphaned scores, relationships
 * - HOOK: mld_agent_assigned_to_client - Fires when client assigned
 * - HOOK: mld_client_agent_changed - Fires when client reassigned
 * - HOOK: mld_client_activity_recorded - Fires on every activity (debounced)
 * - FIX: Engagement scores now update in real-time (was 12+ hour delay)
 * - FIX: agent_id in scores table updates on client reassignment
 *
 * Version 6.40.0 - CLIENT ENGAGEMENT ANALYTICS (Jan 4, 2026)
 * - FEATURE: Client engagement scoring system (0-100 scale)
 * - FEATURE: Property interest tracking with weighted scores
 * - FEATURE: "Client Insights" tab for agents in dashboard
 * - FEATURE: Engagement score trend analysis (rising/falling/stable)
 * - NEW: wp_mld_client_engagement_scores table - Engagement scores with component breakdown
 * - NEW: wp_mld_client_property_interest table - Property-level interest tracking
 * - NEW: MLD_Engagement_Score_Calculator class - Score calculation and storage
 * - NEW: MLD_Property_Interest_Tracker class - Property interest aggregation
 * - NEW: REST API endpoints: /agent/clients/analytics/summary, /detailed, /timeline, /property-interests
 * - NEW: Daily/hourly cron jobs for engagement score and property interest aggregation
 * - UPDATED: iOS ActivityTracker with 15+ new event types (photos, map, contact, engagement)
 * - UPDATED: Vue.js dashboard with sortable client list, detail view, and activity timeline
 *
 * Version 6.39.0 - COMPREHENSIVE SITE ANALYTICS SYSTEM (Jan 4, 2026)
 * - FEATURE: Cross-platform visitor tracking system (Web + iOS app)
 * - FEATURE: Track ALL visitors - anonymous and logged-in users
 * - FEATURE: City-level IP geolocation using MaxMind GeoLite2
 * - FEATURE: Device/browser/OS detection with comprehensive user agent parsing
 * - NEW: wp_mld_public_sessions table - Visitor sessions with geo/device data
 * - NEW: wp_mld_public_events table - Individual tracking events
 * - NEW: wp_mld_analytics_hourly table - Pre-aggregated hourly statistics
 * - NEW: wp_mld_analytics_daily table - Daily aggregates (permanent retention)
 * - NEW: wp_mld_realtime_presence table - MEMORY table for live visitor tracking
 * - NEW: MLD_Public_Analytics_Database class - CRUD operations and dashboard queries
 * - NEW: MLD_Device_Detector class - User agent parsing (browser, OS, device type)
 * - NEW: MLD_Geolocation_Service class - IP-to-city lookup with caching
 * - NEW: MaxMind MMDB reader for local geolocation (no external API calls)
 * - PHASE 1 of 6: Database & Core Classes complete (see ANALYTICS_IMPLEMENTATION_PROGRESS.md)
 *
 * Version 6.38.0 - COMPREHENSIVE WEB ANALYTICS TRACKING (Jan 4, 2026)
 * - FEATURE: ~30 new analytics event types for detailed user behavior tracking
 * - FEATURE: Scroll depth tracking (25%, 50%, 75%, 100% milestones)
 * - FEATURE: Time-on-page tracking with 30s engagement pings
 * - FEATURE: Map interaction tracking (zoom, pan, draw, marker/cluster clicks)
 * - FEATURE: Photo gallery & lightbox tracking (views, photos viewed count)
 * - FEATURE: Contact/share button click tracking
 * - FEATURE: Saved search CRUD operations tracking
 * - FEATURE: Filter modal and search execute tracking
 * - EVENTS: search_execute, filter_apply, filter_clear, filter_modal_open/close
 * - EVENTS: map_zoom, map_pan, map_draw_start, map_draw_complete, marker_click, cluster_click
 * - EVENTS: photo_view, photo_lightbox_open/close, tab_click, video_play, street_view_open
 * - EVENTS: contact_click, contact_form_submit, share_click
 * - EVENTS: saved_search_view/edit/delete, alert_toggle, time_on_page, scroll_depth
 * - UPDATED: mld-analytics-tracker.js with comprehensive event listeners
 * - UPDATED: class-mld-client-analytics-database.php with all new activity types
 *
 * Version 6.35.3 - SHARE WITH CLIENT BUTTON ON PROPERTY DETAIL (Jan 3, 2026)
 * - FEATURE: "Share with Client" button on property detail page for agents
 * - FEATURE: Modal to select client and add note when sharing
 * - FEATURE: Share button in agent dashboard My Clients tab
 * - FEATURE: Agent can share properties from property detail page or dashboard
 *
 * Version 6.35.2 - FROM MY AGENT DASHBOARD TAB (Jan 3, 2026)
 * - FEATURE: "From Agent" tab in client dashboard showing shared properties
 * - FEATURE: Client can mark properties as interested/not interested
 * - FEATURE: Client can dismiss shared properties
 * - FEATURE: Badge shows count of unviewed shared properties
 * - UI: Styled shared property cards with agent info and notes
 *
 * Version 6.35.1 - PROPERTY SHARING NOTIFICATIONS (Jan 3, 2026)
 * - FEATURE: Push notifications when agents share properties with clients
 * - FEATURE: Email notifications with property cards when shared
 * - NEW: MLD_Shared_Properties_Notifier class for push + email
 * - INTEGRATION: Uses SNAB push notification system (APNs)
 * - INTEGRATION: Uses MLD_Email_Template_Engine for styled emails
 * - FIX: Notifications only sent for new shares, not re-shares
 *
 * Version 6.35.0 - PROPERTY SHARING SYSTEM (Jan 3, 2026)
 * - FEATURE: Agents can share properties with their clients (Sprint 3)
 * - FEATURE: Bulk sharing - share multiple properties at once
 * - FEATURE: Client response tracking (interested/not interested)
 * - FEATURE: View tracking for shared properties
 * - NEW: wp_mld_shared_properties database table
 * - NEW: MLD_Shared_Properties_Manager class with CRUD methods
 * - API: POST /shared-properties - Share properties (agent)
 * - API: GET /shared-properties - Get shared properties (client)
 * - API: GET /agent/shared-properties - Get shares by agent
 * - API: PUT /shared-properties/{id} - Update client response
 * - API: DELETE /shared-properties/{id} - Revoke/dismiss share
 * - API: POST /shared-properties/{id}/view - Record view
 *
 * Version 6.34.3 - CUSTOM AVATAR URL IN API (Jan 3, 2026)
 * - FEATURE: Login and /me endpoints now return avatar_url field
 * - FEATURE: Custom profile photo from mld_agent_profiles table takes priority
 * - FALLBACK: Gravatar URL returned if no custom photo is set
 *
 * Version 6.34.2 - FAVORITES/HIDDEN REMOVAL UI FIX (Jan 3, 2026)
 * - FIX: Favorites removal now updates UI immediately (fixed mls_number filter comparison)
 * - FIX: Hidden properties removal now updates UI immediately (fixed mls_number filter)
 * - FIX: Unhide API fallback for MLS number lookups
 *
 * Version 6.34.1 - VUE.JS DASHBOARD FIXES (Jan 3, 2026)
 * - FIX: Schedule Showing button now links to correct /book/ page (was /book-appointment/)
 * - FIX: Saved Searches now display correct match count (using match_count field)
 * - FIX: Agent dashboard now shows clients correctly (fixed agent_id lookup)
 * - Added View Profile button for agents (shown when profile_url is available)
 *
 * Version 6.34.0 - VUE.JS DASHBOARD MIGRATION COMPLETE (Jan 3, 2026)
 * - COMPLETE: Vue.js dashboard now fully replaces PHP dashboard
 * - DEPRECATED: [mld_user_dashboard] shortcode now redirects to Vue.js dashboard
 * - DEPRECATED: user-dashboard.php, user-dashboard-enhanced.php moved to /deprecated/
 * - DEPRECATED: mld-saved-searches-dashboard.css/js moved to /deprecated/
 * - All dashboard features verified working: Saved Searches, Favorites, Hidden, Appointments
 * - My Agent (client view) and My Clients (agent view) features working
 * - Email Preferences with digest settings working
 *
 * Version 6.33.6 - VUE.JS DASHBOARD: PROPERTY URL FIX (Jan 3, 2026)
 * - FIX: Property links now use listing_id (MLS number) instead of listing_key hash
 * - FIX: Favorites and Hidden tabs now correctly link to property detail pages
 * - FIX: Remove/Unhide buttons now use correct listing_id for API calls
 * - PARITY: URLs now match PHP dashboard implementation
 *
 * Version 6.33.2 - VUE.JS DASHBOARD: HIDDEN PROPERTIES & APPOINTMENTS (Jan 3, 2026)
 * - FEATURE: Hidden Properties tab in Vue.js dashboard (unhide properties)
 * - FEATURE: Appointments tab with upcoming/past appointments view
 * - FEATURE: Cancel appointment modal with confirmation
 * - FEATURE: Reschedule appointment modal with date picker and time slots
 * - FEATURE: Collapsible past appointments section
 * - STYLE: Appointment cards with status badges and type labels
 * - STYLE: Time slot picker grid for rescheduling
 * - Prepares for PHP dashboard deprecation after testing
 *
 * Version 6.33.1 - AGENT-CLIENT SYSTEM: SPRINT 2 WEB DASHBOARD PARITY (Jan 3, 2026)
 * - FEATURE: Agent metrics panel in web dashboard Overview tab
 * - DISPLAY: Total clients, active clients, total searches, total favorites
 * - DISPLAY: Actionable tip when clients are actively searching
 * - STYLE: Colored stat cards (highlight, success variants) for visual hierarchy
 * - RESPONSIVE: 2-column layout on mobile for agent metrics
 *
 * Version 6.33.0 - AGENT-CLIENT SYSTEM: SPRINT 1 FOUNDATION (Jan 3, 2026)
 * - FEATURE: SNAB staff dropdown in Agent admin modal - link agents to booking system
 * - FEATURE: save_agent() now persists snab_staff_id to database
 * - FEATURE: get_all_snab_staff() method for dropdown population
 * - FIX: Updated activator.php with complete agent table schemas
 * - Database schema alignment for agent profiles table
 *
 * Version 6.31.16 - SAVED SEARCH PLATFORM AUDIT IMPROVEMENTS (Jan 2, 2026)
 * - FIX: Frequency labels show human-readable text (e.g., "Daily at 9 AM" instead of "daily")
 * - FIX: Expanded filter summary on dashboard to show more filter types (neighborhoods, status, schools, amenities)
 * - ADD: Database index for cron notification queries (notification_frequency, is_active, last_notified_at)
 * - ADD: Filter normalization on save - beds/baths formats standardized for cross-platform consistency
 * - UI: Dashboard JS now shows consistent frequency labels matching PHP
 *
 * Version 6.31.15 - iOS SAVED SEARCH EMAIL ALERT FILTER FIX (Jan 2, 2026)
 * - FIX: Enhanced Filter Matcher now handles iOS filter key formats for email alerts
 * - FIX: beds filter - now handles single integer (iOS sends min beds as int, not array)
 * - FIX: neighborhood filter - now checks lowercase 'neighborhood' key (iOS format)
 * - FIX: zip filter - now checks lowercase 'zip' key (iOS format)
 * - Ensures saved search alerts properly filter properties using iOS-created filters
 *
 * Version 6.31.9 - HIDDEN PROPERTIES API (Jan 1, 2026)
 * - Added REST API endpoints for hidden properties (GET/POST/DELETE /hidden)
 * - Mirrors favorites API pattern for consistency
 * - iOS users can now hide properties from search results
 *
 * Version 6.31.8 - SCHOOL FILTERS FIX (Jan 1, 2026)
 * - CRITICAL FIX: School filters now work after year rollover (2025→2026)
 * - BUG: Code queried for school rankings with year=2026, but data is from 2025
 * - FIX: Added get_latest_ranking_year() helper that queries MAX(year) from rankings
 * - Affected filters: school_grade, near_a_elementary, near_ab_elementary, etc.
 * - All school filter queries now use the most recent year with available data
 *
 * Version 6.31.7 - SCHOOLS SITEMAP (Dec 27, 2025)
 * - SEO: Added schools sitemap generation for BMN Schools virtual pages
 * - Generates URLs for /schools/, /schools/{district}/, /schools/{district}/{school}/
 * - Includes priority based on district rankings and school grades
 * - Added to sitemap index, robots.txt, and regeneration schedule
 *
 * Version 6.31.6 - SECURITY: RATE LIMITING & INPUT VALIDATION (Dec 27, 2025)
 * - SECURITY: Added rate limiting to login endpoint (5 attempts per 15 minutes per email+IP)
 * - SECURITY: Added rate limiting to register endpoint (3 attempts per 15 minutes per IP)
 * - SECURITY: 15-minute lockout after exceeding limits, returns HTTP 429
 * - SECURITY: Added sort parameter validation with whitelist (prevents SQL injection)
 * - Added check_auth_rate_limit(), record_failed_auth_attempt(), clear_auth_rate_limit() helpers
 * - Added validate_sort_parameter() helper with allowed values only
 *
 * Version 6.31.5 - PUSH NOTIFICATIONS ADMIN ENABLED (Dec 26, 2025)
 * - Enabled Push Notifications settings page in WordPress admin
 * - Settings > Push Notifications menu now available
 * - Configure APNs credentials for iOS push notification testing
 *
 * Version 6.31.4 - DASHBOARD VIEW RESULTS BUTTON FIX (Dec 26, 2025)
 * - FIX: "View Results" button on dashboard now works for iOS-created saved searches
 * - FIX: Added mld_build_search_url_from_filters() to build URL from filters when search_url is empty
 * - FIX: Handles both iOS format (city, min_price) and web format (City, price_min) filter keys
 *
 * Version 6.31.3 - IOS SAVED SEARCH WEB COMPATIBILITY (Dec 26, 2025)
 * - FIX: iOS-created saved searches now open correctly on web
 * - FIX: buildSearchUrl() now handles both iOS format (city, min_price, property_type)
 *        and web format (City, price_min, PropertyType) filter keys
 * - Added support for additional filter types: sqft, year_built, neighborhood, zip
 * - Added support for special filters: price_reduced, open_house_only, new_listing_days
 *
 * Version 6.31.2 - SAVED SEARCH SECURITY & SYNC FIXES (Dec 26, 2025)
 * - SECURITY: Added no-cache headers to all authenticated endpoints (fixes CDN caching leak)
 * - SECURITY: Added user_id > 0 validation to prevent orphaned saves
 * - FIX: Added frequency enum normalization (fifteenMin -> fifteen_min)
 * - FIX: Added filter array normalization for consistent storage
 * - FIX: Strengthened empty name validation with trim()
 * - NEW: Device token format validation for iOS (APNs) and Android (FCM)
 * - NEW: Polygon size limit (100 points max)
 * - SECURITY: Hid database errors from API responses (log instead)
 * - GDPR: Added soft delete cleanup cron (permanently removes is_active=0 after 30 days)
 *
 * Version 6.31.0 - PUSH NOTIFICATIONS SYSTEM (Dec 26, 2025)
 * - NEW: APNs (Apple Push Notification service) integration for iOS saved search alerts
 * - NEW: MLD_Push_Notifications class with JWT authentication for APNs HTTP/2 API
 * - NEW: Push notification admin settings page (Settings > Push Notifications)
 * - NEW: Test notification feature in admin to verify APNs configuration
 * - NEW: Automatic push delivery after email for saved search matches
 * - NEW: Device count tracking and status monitoring
 * - NEW: Invalid token handling (410 response deactivates tokens)
 * - INTEGRATION: Push notifications sent after successful email in notification cron
 *
 * Version 6.30.24 - iOS POLYGON DRAW SEARCH (Dec 25, 2025)
 * - NEW: Added polygon filter to iOS REST API for true draw search functionality
 * - NEW: Uses MLD_Spatial_Filter_Service::build_summary_polygon_condition() for point-in-polygon SQL
 * - NEW: Polygon filtering works for active, archive, and combined status queries
 * - PARITY: iOS now has same polygon search capability as web AJAX path
 *
 * Version 6.30.23 - POLYGON + SCHOOL FILTER FIX (Dec 25, 2025)
 * - FIX: School filters now work with polygon search (property name case mismatch)
 * - FIX: filter_properties_by_school_criteria handles both 'city'/'City' and 'latitude'/'Latitude'
 * - Root cause: Traditional path returns PascalCase (City, Latitude), school filter expected lowercase
 *
 * Version 6.30.22 - COMPLETE FILTER PARITY (Dec 25, 2025)
 * - NEW: Added school_district_id to web query builder unsupported_filters (uses post-query filtering)
 * - NEW: Added has_basement filter to iOS REST API (uses summary table)
 * - NEW: Added pet_friendly filter to iOS REST API (uses summary table)
 * - PARITY: 100% filter parity achieved between iOS and Web paths
 *
 * Version 6.30.21 - WEB AMENITY FILTER PARITY (Dec 25, 2025)
 * - NEW: Added has_virtual_tour filter to web query builder (uses summary table)
 * - NEW: Added PoolPrivateYN, FireplaceYN filter support (mapped to summary table columns)
 * - NEW: Added conditional JOIN architecture for filters requiring details/features tables
 * - NEW: Added helper functions: has_details_filters(), has_features_filters()
 * - NEW: Added build_details_filter_conditions() for CoolingYN, GarageYN, AttachedGarageYN, parking_total_min
 * - NEW: Added build_features_filter_conditions() for WaterfrontYN, ViewYN, SpaYN, MLSPIN_WATERVIEW_FLAG
 * - PERF: JOINs only added when necessary (no performance impact for basic searches)
 * - PARITY: Web map now supports all amenity filters that iOS REST API supports
 *
 * Version 6.30.20 - SHARED QUERY BUILDER (Dec 25, 2025)
 * - NEW: Created MLD_Shared_Query_Builder class for unified filter logic
 * - NEW: Shared builder normalizes filter keys between iOS REST API and Web AJAX
 * - NEW: Common methods: normalize_filters(), build_conditions(), build_where_clause()
 * - NEW: School filter helpers: has_school_filters(), build_school_criteria()
 * - NEW: Sort helper: get_sort_clause() with consistent sort options
 * - REFACTOR: Query class school helpers now delegate to shared builder
 * - ARCH: Foundation for eliminating duplicate filter logic in code paths
 * - NOTE: Full filter migration deferred - Query class has specialized street handling
 *
 * Version 6.30.19 - WEB FILTER PARITY (Dec 25, 2025)
 * - NEW: Added price_reduced filter to web query builder (parity with iOS)
 * - NEW: Added new_listing_days filter to web query builder (parity with iOS)
 * - NEW: Added max_dom filter to web query builder (parity with iOS)
 * - NEW: Added neighborhood filter to web query builder (parity with iOS)
 * - DOC: Created CODE_PATH_PARITY_AUDIT.md documenting all filter differences
 *
 * Version 6.30.18 - GLOSSARY MODAL POSITION FIX v2 (Dec 25, 2025)
 * - FIX: Modal content now uses explicit fixed positioning (top:50%, left:50%, transform:translate)
 * - FIX: Added !important to all positioning rules to override theme conflicts
 * - Previous fix (JS moving modal to body) wasn't sufficient due to CDN caching
 *
 * Version 6.30.17 - GLOSSARY MODAL POSITION FIX (Dec 23, 2025)
 * - FIX: Glossary modal now opens centered on screen instead of off-screen
 * - Root cause: Modal was inside container with transform, breaking position:fixed
 * - Solution: JavaScript now moves modal to document.body on init
 *
 * Version 6.30.13 - ERROR LOG CLEANUP
 * - FIX: Removed DEBUG error_log calls from comparable AJAX handler
 * - FIX: Removed non-existent room_description column from rooms query
 *
 * Version 6.30.12 - INTERNAL REST DISPATCH FIX (Server Overload Prevention)
 * - CRITICAL FIX: Replaced wp_remote_get() calls with internal REST dispatch (rest_do_request)
 * - Root cause: MLD plugin was making HTTP requests to itself for school data
 * - Each property page load consumed additional PHP-FPM workers for school API calls
 * - Under high load, server was DDoS-ing itself with internal HTTP connections
 * - Internal dispatch avoids HTTP overhead and doesn't consume extra workers
 * - Affected methods: get_schools_for_location(), get_schools_for_district(), get_district_for_point()
 *
 * Version 6.30.11 - COMPARABLE SALES AJAX PERFORMANCE FIX (504 Timeout Prevention)
 * - FIX: Added bot detection to block Googlebot/bingbot from triggering expensive AJAX
 * - FIX: Added rate limiting (10 requests/minute per IP)
 * - FIX: Added 30-minute result caching per property
 * - FIX: Reduced debug logging (no more print_r of full arrays)
 * - FIX: Optimized market context call (reuses if already computed)
 * - Prevents 504 Gateway errors when crawlers hit property pages
 *
 * Version 6.30.6 - WEB DISTRICT RATING FILTER & CHIP FIXES
 * - NEW: Minimum District Rating picker on web (iOS-style segmented control)
 * - FIX: Grade filter now includes variants (A includes A+/A/A-)
 * - FIX: Property cards show district grade with percentile (🎓 A- top 30%)
 * - FIX: Duplicate chips for school toggles removed
 * - FIX: Chip labels now use "A/B - Rated High" format
 *
 * Version 6.30.5 - DISTRICT GRADE ON PROPERTY CARDS
 * - NEW: Property list API now returns district_grade and district_percentile for each property
 * - NEW: get_district_grade_for_city() method in BMN Schools Integration
 * - NEW: get_district_percentile_from_score() for percentile calculation
 * - iOS property cards can now display district school rating
 * - Enables district rating filter in property search
 *
 * Version 6.30.4 - DISTRICT-BASED SCHOOL DISPLAY
 * - Property detail pages now show ALL schools in the school district
 * - Added get_schools_for_district() method using district_id API parameter
 * - Schools are grouped by level (Elementary/Middle/High) with up to 10 per level
 * - Falls back to radius-based search if district not found
 *
 * Version 6.30.3 - SCHOOL FILTER iOS-STYLE REDESIGN
 * - REDESIGN: School filters now use iOS-style toggle switches (not radio buttons)
 * - NEW: Green (#22c55e) toggle sliders matching iOS app design
 * - NEW: Mutually exclusive toggles (A-only unchecks A/B and vice versa)
 * - FIX: Direct API parameter names (near_a_*, near_ab_*) - no mapping needed
 * - FIX: Filter tags now display correctly for toggle-based filters
 *
 * Version 6.30.2 - SCHOOL BADGES ON LISTING CARDS & MAP POPUPS (Phase 3)
 * - NEW: School grade badges on map sidebar listing cards
 * - NEW: School grade badges on map popup cards
 * - NEW: School grade badges on PHP listing card templates
 * - NEW: Best nearby school grade added to listing API response
 * - NEW: Grade-colored badges (A=green, B=blue, C=yellow, D=orange, F=red)
 * - Uses grid-based caching (~0.7mi grid, 30min TTL) for performance
 *
 * Version 6.30.1 - WEB SCHOOL QUALITY FILTERS (Phase 2)
 * - NEW: School Quality filter section in search modal (matching iOS)
 * - NEW: Elementary school filter (Any / A-rated / A or B-rated)
 * - NEW: Middle school filter (Any / A-rated / A or B-rated)
 * - NEW: High school filter (Any / A-rated / A or B-rated)
 * - NEW: Filter tags with graduation cap emoji for active school filters
 * - CHANGE: Radio button groups for exclusive selection per school level
 * - Maps frontend values to backend API parameters (near_a_*, near_ab_*)
 *
 * Version 6.30.0 - ENHANCED WEB SCHOOLS SECTION (Phase 1)
 * - NEW: iOS-matching enhanced schools section on desktop and mobile property pages
 * - NEW: Letter grade badges with color coding (A=green, B=blue, C=yellow, D=orange, F=red)
 * - NEW: School level grouping (Elementary/Middle/High) with level-specific colors
 * - NEW: District card with optional ranking badge
 * - NEW: Percentile badges, state rankings, composite scores
 * - NEW: Trend indicators (improved/declined) with arrow icons
 * - NEW: Data completeness indicators showing confidence level
 * - NEW: Demographics row (students, diversity, free lunch %)
 * - NEW: Highlight chips for school features (ratio, MCAS, etc.)
 * - NEW: Glossary system with tooltip on hover and modal on click
 * - NEW: mld-schools.css with responsive design for mobile
 * - NEW: mld-schools-glossary.js for interactive glossary functionality
 *
 * Version 6.29.5 - SCHOOL DISTRICT AVERAGE GRADE FILTER
 * - CHANGE: "Minimum School Grade" filter now uses district average instead of best nearby school
 * - Calculates the average percentile rank of ALL schools in the property's school district
 * - More accurate representation of overall school quality in an area
 * - New method: get_district_average_grade() for district-wide grade calculation
 *
 * Version 6.29.4 - A-RATED SCHOOLS INCLUDE A- FIX
 * - FIX: "Near A-rated" now includes A+, A, and A- schools (percentile >= 70)
 * - Previously only matched A and A+ (percentile >= 80), missing A- schools
 * - "Near A or B rated" now includes all B and A grades (percentile >= 40)
 * - Marblehead now shows results when filtering by A-rated schools
 *
 * Version 6.29.3 - LEVEL-SPECIFIC SCHOOL QUALITY FILTERS
 * - NEW: 6 new school toggle filters replacing previous 2 generic toggles
 * - Elementary (K-4): near_a_elementary (1mi of A-rated), near_ab_elementary (1mi of A/B-rated)
 * - Middle (4-8): near_a_middle (1mi of A-rated), near_ab_middle (1mi of A/B-rated)
 * - High (9-12): near_a_high (1mi of A-rated), near_ab_high (1mi of A/B-rated)
 * - All filters use 1-mile radius for accuracy
 * - Legacy filters (near_top_elementary, near_top_high) retained for backwards compatibility
 *
 * Version 6.29.2 - SCHOOL GRADE THRESHOLD FIX
 * - FIX: Aligned MLD grade thresholds with BMN Schools percentile system
 * - Previously: 93-96% = A, causing 88% schools to show as B+
 * - Now: 80-89% = A, matching BMN Schools Ranking Calculator
 * - Reading schools now correctly graded as A (88%) instead of B+
 *
 * Version 6.29.1 - SCHOOL FILTER TOTAL COUNT FIX
 * - FIX: Total count was calculated after trimming to page size
 * - Now correctly calculates filter pass rate before trimming results
 *
 * Version 6.29.0 - SCHOOL QUALITY PROPERTY FILTERS (Phase 4)
 * - Added school quality filters to property search API:
 *   - school_grade: Filter by minimum school grade (A, B, C)
 *   - near_top_elementary: Properties within 2 miles of A-rated elementary school
 *   - near_top_high: Properties within 3 miles of A-rated high school
 *   - school_district_id: Filter by specific school district
 * - Extended MLD_BMN_Schools_Integration with property filtering methods
 * - Over-fetch pagination to maintain consistent page sizes with school filters
 * - Cached school lookups for performance (1-hour TTL for top schools)
 *
 * Version 6.28.1 - BMN SCHOOLS DATABASE VERIFICATION
 * - Added 10 BMN Schools plugin tables to database verification tool
 * - Verifies: bmn_schools, bmn_school_districts, bmn_school_test_scores, etc.
 * - Enables single-click repair for all school data tables
 *
 * Version 6.27.27 - BOUNDARY LOCATION REST API ENDPOINT
 * - NEW: /boundaries/location endpoint for city/neighborhood/zipcode GeoJSON polygons
 * - NEW: Returns real boundary data from OpenStreetMap Nominatim API
 * - NEW: Supports types: city, neighborhood, zipcode
 * - NEW: Uses existing wp_mld_city_boundaries cache table (30-day expiry)
 * - NEW: Enables iOS app to display accurate geographic boundaries instead of circles
 *
 * Version 6.27.26 - NEIGHBORHOOD ANALYTICS API ENDPOINT
 * - FEATURE: New /neighborhoods/analytics endpoint for map price overlays
 * - FEATURE: Returns neighborhood median price, listing count, and market heat
 * - FEATURE: Filters by map bounds and property type
 * - FEATURE: Returns center coordinates for overlay positioning
 *
 * Version 6.27.25 - PHOTOS ARRAY IN LIST ENDPOINT FOR CAROUSEL
 * - FEATURE: Properties list endpoint now returns `photos` array (first 5 photos per listing)
 * - FEATURE: Batch photo fetch using optimized single query with ROW_NUMBER
 * - FEATURE: Photos returned for active, archive, and combined property queries
 * - Used for photo carousel feature in iOS app property cards
 *
 * Version 6.27.24 - ARCHIVE SUMMARY TABLE OPTIMIZATION
 * - PERF: Created bme_listing_summary_archive table (90K+ rows) for fast archive queries
 * - PERF: get_archive_properties() now queries single table instead of 5-table JOINs
 * - PERF: get_combined_properties() now uses both summary tables (no JOINs needed)
 * - PERF: Archive queries improved from 4-5 seconds to <200ms (matching Active performance)
 * - PERF: Increased cache TTL from 2 minutes to 30 minutes (matching web plugin)
 *
 * Version 6.27.23 - ACTIVE QUERY PERFORMANCE OPTIMIZATION
 * - PERF: Separated COUNT query JOINs from data query JOINs for Active/Pending status
 * - PERF: COUNT queries no longer JOIN location or open_house tables unless filtering requires them
 * - PERF: This matches the web plugin's optimized query pattern
 *
 * Version 6.27.22 - MIXED STATUS QUERY FIX
 * - FIX: Combined status queries (Active + Pending + Sold) now work correctly
 * - FIX: When selecting multiple statuses spanning both active and archive tables, results are now combined via UNION
 * - NEW: get_combined_properties() function queries both table sets and merges results
 * - FIX: Property type filters now work correctly with mixed status queries
 *
 * Version 6.27.21 - MOBILE API ARCHIVE TABLE SUPPORT
 * - NEW: Properties endpoint now supports Sold/Closed/Expired status filters
 * - NEW: Archive table routing for status filters (Sold, Closed, Expired, Withdrawn, Canceled)
 * - NEW: Property detail now checks archive tables when not found in summary
 * - NEW: "Sold" status mapped to "Closed" in database (how MLS stores sold listings)
 * - NEW: close_price and close_date fields returned for sold properties
 * - FIX: Sold listings were not appearing in iOS app due to summary table only having active listings
 *
 * Version 6.27.20 - CRITICAL FIX: Open Houses Query
 * - FIX: Properties API returning 0 results due to invalid column references
 * - FIX: bme_open_houses table uses JSON column (open_house_data), not direct columns
 * - FIX: Changed from open_house_start_time to expires_at for filtering
 * - FIX: Property detail now parses JSON open house data correctly
 *
 * Version 6.27.19 - COMPLETE iOS PROPERTY DETAIL PARITY
 * - NEW: 70+ additional fields in property detail API response
 * - NEW: Room data array with type, level, dimensions, features, description
 * - NEW: Interior: window_features, door_features, attic, insulation, accessibility_features, security_features
 * - NEW: Interior: common_walls, entry_level, entry_location, levels, rooms_total, main_level_bedrooms/bathrooms
 * - NEW: Area breakdown: above_grade_finished_area, below_grade_finished_area, total_area
 * - NEW: Exterior: water_body_name, foundation_area
 * - NEW: Lot: lot_size_square_feet, land_lease fields, horse_yn/amenities, vegetation, topography
 * - NEW: Lot: frontage_type/length, road_surface_type, road_frontage_type
 * - NEW: Parking: attached_garage_yn, carport_spaces/yn, driveway_surface
 * - NEW: Utilities: utilities, water_source, sewer, electric, gas, internet_type, cable_available_yn
 * - NEW: Utilities: smart_home_features, energy_features, green_building/certification/sustainability
 * - NEW: Community: community_features, association_yn/name/phone, fee2 fields, master/condo fees, pet_restrictions
 * - NEW: Schools: school_district, elementary_school, middle_or_junior_school, high_school
 * - NEW: Financial: tax_assessed_value, tax_legal_description, tax_lot/block/map_number, parcel_number
 * - NEW: Financial: additional_parcels_yn/description, zoning, zoning_description
 * - NEW: Details: year_built_source/details/effective, building_name/features, property_attached_yn
 * - NEW: Details: property_condition, disclosures, exclusions, inclusions, ownership, occupant_type
 * - NEW: Details: possession, listing_terms, listing_service, special_listing_conditions
 *
 * Version 6.27.18 - COMPREHENSIVE MOBILE API DATA FOR iOS
 * - NEW: List endpoint now returns mls_number, original_price, neighborhood, baths_full/half, status, garage_spaces, has_open_house
 * - NEW: Detail endpoint returns 50+ additional fields for full iOS property detail parity
 * - NEW: Interior features: flooring, appliances, fireplace_features, fireplaces_total, laundry_features, interior_features
 * - NEW: Exterior features: construction_materials, architectural_style, roof, foundation_details, pool_features, spa_features, etc.
 * - NEW: Lot details: lot_size_acres, lot_size_dimensions, lot_features
 * - NEW: Parking details: parking_total, parking_features, covered_spaces, open_parking_spaces
 * - NEW: HOA details: hoa_fee_frequency, association_amenities, pets_allowed, senior_community
 * - NEW: Financial: tax_year, price_per_sqft (calculated)
 * - NEW: Agent info: name, email, phone, photo_url, office_name, office_phone
 * - NEW: Open houses array with start_time, end_time, remarks
 * - NEW: Virtual tour URL from bme_virtual_tours table
 * - NEW: Feature flags: has_pool, has_waterfront, has_view, has_spa, has_fireplace, has_garage, has_cooling
 *
 * Version 6.27.17 - ADDRESS/MLS/STREET NAME FILTER SUPPORT
 * - FIX: Properties endpoint now supports address, mls_number, and street_name filters
 * - FIX: When user selects address from autocomplete, only that listing is returned
 * - FIX: When user selects MLS number from autocomplete, only that listing is returned
 * - FIX: Street name filter now works with partial matching
 *
 * Version 6.27.16 - MOBILE AUTOCOMPLETE FIX
 * - FIX: Autocomplete now queries bme_listing_location table for neighborhoods, streets, addresses
 * - FIX: Added street name suggestions (searches street_name column)
 * - FIX: Neighborhoods now search subdivision_name from location table with proper JOIN
 * - FIX: Addresses now search unparsed_address from location table with proper JOIN
 *
 * Version 6.27.15 - PRICE REDUCED FILTER FIX
 * - FIX: Mobile API price_reduced filter was not working (parameter read but never used)
 * - FIX: Added WHERE clause to filter properties where list_price < original_list_price
 * - FIX: Converted price_reduced parameter to boolean using FILTER_VALIDATE_BOOLEAN
 *
 * Version 6.27.14 - MOBILE API PHOTOS FIX v2
 * - FIX: Photos query now uses listing_id (MLS number) instead of listing_key (hash)
 * - FIX: Details query also fixed to use listing_id instead of listing_key
 * - FIX: bme_media and bme_listing_details tables are indexed by listing_id, not listing_key
 *
 * Version 6.27.13 - MOBILE API PHOTOS FIX
 * - FIX: Mobile REST API now queries correct bme_media table for photos
 * - FIX: Changed from non-existent bme_listing_photos to bme_media table
 * - FIX: Property detail endpoint now returns all photos instead of just main photo
 *
 * Version 6.27.10 - CHATBOT MLS NUMBER FIX
 * - FIX: Chatbot showing internal listing_key hash instead of actual MLS number
 * - FIX: format_property_for_context() now correctly uses listing_id for mls_number
 * - Never expose internal listing_key to users
 *
 * Version 6.27.9 - CHATBOT PAGE CONTEXT FIX
 * - FIX: Page context not being passed to chatbot engine (sanitize_user_data stripped it)
 * - NEW: sanitize_page_context() method for proper page context sanitization
 * - NEW: sanitize_array_recursive() for nested data sanitization
 * - Page context now includes: page_url, referrer_url, device_type, browser
 *
 * Version 6.27.8 - CHATBOT SESSION PERSISTENCE
 * - NEW: Chat session persists across page navigations (30-minute timeout)
 * - NEW: Chat window state saved (reopens if was open on previous page)
 * - NEW: Chat history reloaded when returning to existing session
 * - NEW: Page change detection with contextual notice
 * - NEW: getOrCreateSessionId() with localStorage persistence
 * - NEW: restoreChatState() for seamless page navigation
 * - NEW: showPageChangeNotice() for context-aware messages
 * - NEW: endChatSession() for explicit session termination
 *
 * Version 6.27.7 - PAGE-AWARE CHATBOT
 * - NEW: Chatbot recognizes current page type (property, calculator, CMA, search, etc.)
 * - NEW: Property page detection with full listing data from database
 * - NEW: DOM scraping for visible property details (MLS#, price, specs)
 * - NEW: Calculator page context extraction
 * - NEW: CMA page context with subject property info
 * - NEW: Search results page context
 * - NEW: Homepage and content page context
 * - NEW: AI receives page context for contextual answers
 *
 * Version 6.27.6 - CHATBOT PROPERTY LINKS
 * - NEW: Chatbot includes property detail page links when sharing listings
 * - NEW: property_url field in format_property_summary() and format_property_detail()
 * - NEW: get_property_url() helper in tool executor
 * - NEW: AI instructed to always include property links in responses
 *
 * Version 6.27.5 - CHATBOT CLICKABLE LINKS
 * - NEW: URLs in chatbot responses are now clickable links
 * - NEW: linkifyUrls() method for URL detection and conversion
 * - NEW: formatMessageText() for processing bot responses
 * - NEW: .mld-chat-link styling for links in chat
 *
 * Version 6.27.4 - CHATBOT KNOWLEDGE BASE IMPROVEMENTS
 * - FIX: Chatbot now properly uses scanned knowledge base content
 * - NEW: Keyword-based search for relevant knowledge entries
 * - NEW: Full content_text returned instead of just summaries
 * - NEW: Relevance scoring for knowledge base results
 * - IMPROVED: Knowledge base context in AI prompts
 *
 * Version 6.27.0 - LEAD CAPTURE GATE FORM FOR CHATBOT
 * - NEW: Lead capture gate form shown before chatbot interaction
 * - NEW: Required fields: Name + (Phone OR Email) - flexible contact method
 * - NEW: Logged-in WordPress users with complete profiles skip the gate
 * - NEW: Lead data persisted in localStorage for returning visitors
 * - NEW: Personalized greeting after lead capture: "Great to meet you, [FirstName]!"
 * - NEW: lead_gate_enabled setting (defaults to enabled)
 * - NEW: User phone lookup from WP user meta (phone, billing_phone, user_phone)
 * - NEW: Mobile-responsive full-screen gate form
 * - NEW: Dark mode support for gate form
 *
 * Version 6.26.12 - TIMEZONE FIX FOR DASHBOARD DISPLAY
 * - FIX: Dashboard shows wrong date/time (12/17 4am instead of 12/18 9am)
 * - FIX: format_appointment() now uses DateTime with wp_timezone() instead of strtotime()
 * - All appointment dates/times now display correctly in WordPress timezone
 *
 * Version 6.26.11 - RESCHEDULE INTEGRATION FIX
 * - FIX: Time slot timezone conversion (4am-11:30am → 9am-4:30pm) - use wp_timezone()
 * - FIX: Google Calendar now updates when rescheduling (correct API format)
 * - FIX: Email notifications now sent for reschedule (correct method: send_reschedule)
 * - FIX: Email notifications now sent for cancellation (correct method: send_cancellation)
 *
 * Version 6.26.10 - CSS CONTRAST FIX
 * - FIX: Time slots white-on-white text (added explicit dark text colors)
 * - FIX: Modal content contrast (explicit color on modal-content, modal-body)
 * - FIX: Form inputs contrast (explicit background/color on all form controls)
 *
 * Version 6.26.9 - APPOINTMENTS TAB FIX
 * - FIX: AJAX handlers not registering (added singleton initialization on init hook)
 * - FIX: Reschedule time slots now load properly from frontend dashboard
 *
 * Version 6.26.8 - APPOINTMENTS TAB INTEGRATION
 * - NEW: Appointments tab in [mld_user_dashboard] shortcode
 * - NEW: Integration with SN Appointment Booking (SNAB) plugin
 * - NEW: View, cancel, and reschedule appointments from dashboard
 * - NEW: MLD_SNAB_Integration class for cross-plugin communication
 * - NEW: show_appointments attribute for user dashboard shortcode
 * - NEW: Appointment cards with status badges and action buttons
 * - NEW: Reschedule modal with date picker and available time slots
 * - NEW: Past appointments collapsible section
 *
 * Version 6.25.5 - MOBILE FULLSCREEN MODE
 * - NEW: Fullscreen toggle button on mobile property page bottom sheet handle
 * - NEW: Browser fullscreen API integration (hides browser UI completely)
 * - NEW: Bottom sheet expands to 100% when fullscreen enabled
 * - NEW: FullscreenManager class in property-mobile-v3.js
 * - NEW: .mld-fullscreen-toggle button styling
 * - IMPROVED: Open position now 100% instead of 80% for maximum content visibility
 * - IMPROVED: Hides hamburger and gallery controls in fullscreen mode
 *
 * Version 6.25.4 - NAVIGATION HAMBURGER FIXES
 * - FIXED: Mobile hamburger now truly sticky (moved outside gallery container)
 * - REMOVED: Duplicate hamburger from desktop gallery overlay (only in sticky nav now)
 * - IMPROVED: Mobile hamburger placed directly in body for proper position:fixed behavior
 *
 * Version 6.25.3 - STICKY NAVIGATION HAMBURGER
 * - NEW: Hamburger menu in desktop sticky navigation bar (always accessible when scrolling)
 * - NEW: .mld-v3-nav-toggle-sticky class for sticky nav hamburger
 * - IMPROVED: Mobile hamburger stays fixed when scrolling (position: fixed)
 * - IMPROVED: Desktop users can access nav menu from sticky bar after scrolling past gallery
 *
 * Version 6.25.2 - PROPERTY PAGE NAVIGATION DRAWER
 * - NEW: Navigation drawer added to property detail pages (desktop and mobile)
 * - CHANGED: Back button replaced with hamburger menu on property pages
 * - NEW: .mld-v3-nav-toggle class for desktop property page hamburger
 * - NEW: .mld-nav-toggle-property class for mobile property page hamburger
 * - IMPROVED: Nav drawer assets now load on both search pages and property pages
 *
 * Version 6.25.1 - MOBILE NAVIGATION OPTIMIZATION
 * - IMPROVED: Mobile hamburger now positioned after filters button (saves screen space)
 * - IMPROVED: Save Search button hidden on mobile (moved to drawer)
 * - NEW: "Save Search" action button in drawer menu (search pages only)
 * - IMPROVED: Clicking Save Search in drawer triggers existing save modal
 *
 * Version 6.25.0 - SEARCH PAGE NAVIGATION DRAWER
 * - NEW: Navigation menu drawer for search pages (full-map and half-map views)
 * - NEW: Hamburger menu button in top-left corner of search interface
 * - NEW: Slide-in drawer with WordPress primary menu for site navigation
 * - NEW: Works on both mobile AND desktop (search pages have no header)
 * - NEW: Accessible with focus trap, Escape key close, and ARIA attributes
 * - NEW: Swipe-to-close gesture support on mobile devices
 * - NEW: mld-nav-drawer.css and mld-nav-drawer.js assets
 * - NEW: mld_nav_drawer_fallback_menu() for sites without primary menu
 *
 * Version 6.24.0 - FILE UPLOADS & FORM TEMPLATES
 * - NEW: File Upload field type with drag-drop interface
 * - NEW: Progress indicator and multiple file support
 * - NEW: MIME type validation with magic byte verification
 * - NEW: Form Template Library with 6 pre-built real estate templates
 * - NEW: MLD_Contact_Form_Upload class for file handling
 * - NEW: MLD_Contact_Form_Templates class for template management
 * - NEW: wp_mld_form_uploads and wp_mld_form_templates tables
 *
 * Version 6.23.0 - MULTI-STEP FORMS
 * - NEW: Multi-step form wizard functionality for contact forms
 * - NEW: Progress indicator (numbered steps or progress bar)
 * - NEW: Previous/Next navigation buttons with per-step validation
 * - NEW: Step configuration UI in form builder Settings tab
 * - NEW: MLD_Contact_Form_Multistep class for step organization
 * - NEW: mld-contact-form-multistep.js for frontend navigation
 * - IMPROVED: Smooth slide animations between steps
 * - IMPROVED: Mobile-responsive step display
 *
 * Version 6.22.0 - CONDITIONAL LOGIC & NEW FIELD TYPES
 * - NEW: Conditional Logic for contact form fields (show/hide based on field values)
 * - NEW: 6 new field types (Number, Currency, URL, Hidden, Section, Paragraph)
 * - NEW: MLD_Contact_Form_Conditional class for server-side rule evaluation
 * - NEW: mld-contact-form-conditional.js for frontend conditional visibility
 *
 * Version 6.16.0 - CMA ENHANCEMENT FEATURES
 * - NEW: Save/Load CMA Sessions - Logged-in users can save and restore CMA analyses
 * - NEW: ARV Property Adjustment Modal - Edit subject property specs (beds, baths, sqft, year, garage, pool, condition) for After Repair Value calculations
 * - NEW: PDF Export with TCPDF - Clean, professional CMA reports with full property details
 * - NEW: My CMAs Modal - View, load, delete, and favorite saved CMA sessions
 * - NEW: wp_mld_cma_saved_sessions table for persistent CMA storage
 * - IMPROVED: CMA tool now supports ARV mode indicator when adjustments are active
 * - IMPROVED: Session data includes subject overrides, filters, comparables, and summary statistics
 *
 * Version 6.12.2 - DYNAMIC FILTERS UPDATE
 * - NEW: get_available_property_types() - Dynamic property type dropdown from database
 * - NEW: get_available_property_subtypes() - Dynamic sub-type dropdown
 * - NEW: get_price_range_bounds() - Dynamic price range with suggested breakpoints
 * - NEW: get_bedroom_bathroom_options() - Dynamic bed/bath filter options
 * - NEW: get_year_built_range() - Dynamic year built range
 * - NEW: get_date_range_options() - Standardized date range options
 * - NEW: get_all_filter_options() - Combined filter options API
 * - UPDATED: All tab functions now accept date_range parameter
 * - UPDATED: Agent/office functions use months lookback instead of year
 * - UPDATED: Feature premium functions accept months parameter
 * - IMPROVED: Admin dashboard with dynamic property type filter from database
 * - IMPROVED: Date range filter (3, 6, 12, 24, 36 months)
 *
 * Version 6.12.1 - COMPREHENSIVE DATA UTILIZATION UPDATE
 * - NEW: get_price_analysis() - Full price metrics including SP/LP, SP/OLP, price reductions
 * - NEW: get_dom_analysis() - Days on market distribution (<7, 7-14, 14-30, 30-60, 60-90, 90+ days)
 * - NEW: get_property_type_performance() - Compare performance across property types
 * - NEW: get_all_feature_premiums() - Calculate premiums for waterfront, view, pool, fireplace, garage
 * - NEW: get_agent_performance_detailed() - Comprehensive agent/buyer agent metrics
 * - NEW: get_financial_analysis() - Tax and HOA metrics
 * - NEW: get_property_characteristics() - Size, bedrooms, bathrooms, age distribution
 * - NEW: get_price_by_bedrooms() - Price analysis grouped by bedroom count
 * - NEW: get_comprehensive_market_summary() - All metrics in one API call
 * - NEW: Property Analysis tab in admin dashboard with DOM distribution charts
 * - NEW: Feature Premiums tab showing value impact of property features
 * - IMPROVED: All analytics now utilize archive tables for historical data
 * - IMPROVED: DOM calculated from dates (listing_contract_date to close_date)
 * - FIXED: Outlier filtering for SP/LP ratio (50-150% range)
 *
 * Version 6.12.0 - ENHANCED MARKET ANALYTICS SYSTEM
 * - NEW: Comprehensive market analytics dashboard with 6 tabs
 * - NEW: Dynamic city selection (auto-detects all cities with listings)
 * - NEW: Pre-computed monthly stats table for 8-25x faster queries
 * - NEW: City market summary cache for instant dashboard loads
 * - NEW: Agent/office performance tracking with market share analysis
 * - NEW: Property feature premium calculator (waterfront, pool, view analysis)
 * - NEW: City comparison tool (compare up to 5 cities side-by-side)
 * - NEW: Market heat index with hot/balanced/cold classification
 * - NEW: Year-over-year comparison metrics
 * - NEW: Supply vs demand analysis (months of supply, absorption rate)
 * - IMPROVED: Market trends now use pre-computed data (100ms vs 2500ms)
 * - IMPROVED: Added indexes to archive tables for analytics optimization
 * - IMPROVED: Cron jobs for daily/hourly/weekly analytics refresh
 * - DATABASE: 4 new tables (mld_market_stats_monthly, mld_city_market_summary,
 *   mld_agent_performance, mld_feature_premiums)
 *
 * Version 6.10.6 - CMA ADJUSTMENT REFINEMENTS
 * - IMPROVED: Year built adjustment now capped at 20 years max with percentage-based calculation (0.4%/year, max 10%)
 * - IMPROVED: Bedroom adjustments now scale with property value (2-3% per bedroom, bounded $15k-$75k)
 * - IMPROVED: Bathroom adjustments now scale with property value (0.66-1% per bathroom, bounded $5k-$30k)
 * - IMPROVED: Square footage adjustments now have diminishing returns (100%/75%/50% tiers) and 10% cap
 * - IMPROVED: Garage adjustments now percentage-based (2.5% first/$15k-$60k, 1.5% additional/$10k-$40k)
 * - REDUCED: Road type premium default from 25% to 5% (more conservative)
 * - ADDED: FHA/Fannie Mae style adjustment warnings (10% individual, 15% net, 25% gross thresholds)
 * - ADDED: Hard caps on extreme adjustments (20% individual, 30% net, 40% gross)
 * - RESULT: CMA tool now produces more accurate, industry-standard property valuations
 *
 * Version 6.10.5 - DATABASE VERIFICATION TOOL UPDATE
 * - ADDED: 8 missing chatbot tables to database verification tool
 * - TABLES: mld_chat_agent_assignments, mld_chat_data_references, mld_chat_query_patterns
 * - TABLES: mld_chat_response_cache, mld_chat_state_history, mld_chat_training
 * - TABLES: mld_prompt_usage, mld_prompt_variants
 * - RESULT: Database verification tool now checks all 43 required tables
 *
 * Version 6.10.4 - PROPERTY SEARCH FIX (Summary Table Bypass)
 * - FIXED: MLS Number and Address searches now bypass summary table entirely
 * - ROOT CAUSE: Summary table only contains active listings, not archived/sold
 * - SOLUTION: Added MLS Number, Street Address, ListingId to unsupported_filters list
 * - RESULT: These searches now query main tables (active + archive) directly
 *
 * Version 6.10.3 - PROPERTY SEARCH FIX (Complete)
 * - FIXED: MLS Number searches now work (added to summary table filter bypass)
 * - FIXED: "(All Units)" searches now show all properties at address regardless of status
 * - UPDATED: build_summary_filter_conditions() now bypasses status for MLS/Address searches
 * - UPDATED: All address searches (single AND multi-unit) query both active/archive tables
 * - RESULT: Multi-unit view shows all historical sales at an address
 *
 * Version 6.10.2 - DIRECT PROPERTY SELECTION FIX (Archive Support)
 * - FIXED: Specific property searches now query BOTH active AND archive tables
 * - LOGIC: determine_query_tables() now detects MLS # or Address searches
 * - RESULT: Sold/expired properties found even when Active filter is selected
 *
 * Version 6.10.1 - DIRECT PROPERTY SELECTION FIX (Improved)
 * - FIXED: Backend-only detection of specific property searches (no frontend dependency)
 * - LOGIC: Status filter bypassed when MLS Number OR single specific Address filter is present
 * - SUPPORTED: Both "Address" (unparsed) and "Street Address" (parsed) filter types
 * - EXCLUDED: Building searches with "(All Units)" still respect status filter
 *
 * Version 6.10.0 - DIRECT PROPERTY SELECTION FIX (Initial)
 * - FIXED: Selecting a specific MLS # or Address from autocomplete now bypasses status filter
 * - UX: Users can find Sold/Expired/Withdrawn properties by MLS # even with Active filter set
 * - IMPROVED: Added direct_property_selection flag for explicit property lookups
 * - LOGIC: Status filter only bypassed when user explicitly selects from autocomplete
 *
 * Version 6.9.9 - DEBUG LOGGING CLEANUP
 * - CLEANUP: Wrapped all remaining error_log statements in WP_DEBUG checks
 * - AFFECTED: class-mld-admin-notifier.php (10 statements)
 * - AFFECTED: class-mld-ajax.php (8 statements)
 * - AFFECTED: class-mld-neighborhood-analytics.php (4 statements)
 * - AFFECTED: class-mld-comparable-sales.php (2 statements)
 * - PRODUCTION: No debug output in production environments
 *
 * Version 6.9.8 - SECURITY & PERFORMANCE HARDENING
 * - SECURITY: Exception messages no longer leak technical details to frontend
 * - SECURITY: Added geographic coordinate validation (prevents NaN/Infinity attacks)
 * - SECURITY: Standardized nonce validation using check_ajax_referer()
 * - SECURITY: Enhanced cookie security with SameSite=Lax attribute
 * - PERFORMANCE: Optimized chart data queries from 14 to 2 (N+1 fix)
 * - CLEANUP: Removed commented debug code blocks
 *
 * Version 6.9.7 - PRODUCTION HARDENING & DEBUG CLEANUP
 * - FIXED: Removed hardcoded localhost:9002 from environment detection (production-safe)
 * - FIXED: MailHog URL in CMA email success message now only shows in development
 * - IMPROVED: All debug error_log() statements now wrapped in WP_DEBUG checks
 * - CLEANED: Mobile template, chatbot init, chatbot engine, AJAX handlers
 * - PERFORMANCE: Reduced log noise in production environments
 *
 * Version 6.9.6 - CHATBOT AGENT ASSIGNMENT FIX
 * - FIXED: Chatbot now uses real agent names instead of placeholder/fake names
 * - ADDED: get_assigned_agent_name() method with intelligent fallback chain
 * - IMPROVED: Agent lookup checks mld_agent_name setting, default agent ID, BME agents table
 * - FALLBACK: Uses business name or "Our Team" if no agent configured
 * - UX: Agent notification messages now display actual agent names from your team
 *
 * Version 6.9.5 - CHATBOT BUSINESS HOURS CONFIGURABLE
 * - FIXED: Business hours now read from admin settings instead of hardcoded value
 * - IMPROVED: Chatbot context uses configurable business hours from AI Config tab
 * - ADDED: get_business_hours() helper method with fallback chain (settings → legacy option → default)
 * - UX: Business hours set in admin now properly display in chatbot responses
 *
 * Version 6.9.4 - DATABASE COLUMN MAPPING FIX
 * - FIXED: BridgeMLSDataProvider was using PascalCase column names instead of snake_case
 * - FIXED: City/status/price filters now work correctly with proper column references
 * - FIXED: Location data (city, state, zip) now properly JOINed from wp_bme_listing_location table
 * - IMPROVED: All query methods now use correct table aliases (l. for listings, loc. for location)
 * - IMPROVED: Added needsLocationJoin() helper to optimize queries without location filters
 * - IMPROVED: Photos table reference corrected to wp_bme_media with proper column names
 *
 * Version 6.9.3 - PERFORMANCE & CODE CLEANUP
 * - FIXED: Critical performance bug in ListingRepository::count() that fetched up to 999,999 records
 * - PERFORMANCE: Now uses efficient SQL COUNT() query via DataProvider interface
 * - REMOVED: MLD_FORCE_OPTIMIZATION_TESTING debug flag (was forcing optimization in all environments)
 * - IMPROVED: Asset optimization now properly respects WP_DEBUG setting
 * - ADDED: getListingCount() method to DataProviderInterface for efficient counting
 *
 * Version 6.9.0 - A/B TESTING & ADVANCED TRAINING FEATURES
 * - ADDED: A/B testing system for prompt optimization with variant management
 * - ADDED: Performance tracking and analytics for prompt variants
 * - ADDED: Extended prompt variables (business_hours, specialties, service_areas, etc.)
 * - ADDED: Advanced search and filtering for training examples
 * - ADDED: Filter by type, rating, tags, date range with real-time search
 * - IMPROVED: Training page now supports complex queries and multi-criteria filtering
 * - PERFORMANCE: Optimized training data retrieval with indexed searches
 *
 * Version 6.8.0 - TRAINING SYSTEM & CONVERSATION MANAGEMENT
 * - ADDED: Training examples system for chatbot improvement
 * - ADDED: Conversation rating and feedback collection
 * - ADDED: Custom tags and categorization for training data
 * - IMPROVED: Admin interface for managing training examples
 *
 * Version 6.7.1 - CHATBOT ENHANCEMENTS & PRODUCTION FIXES
 * - FIXED: Timezone display showing UTC instead of site timezone in chatbot analytics
 * - ADDED: 54 OpenAI chat models including GPT-5, GPT-5.1, GPT-4.1, and O-series
 * - IMPROVED: Model selection now includes latest reasoning models and variants
 * - PERFORMANCE: TPM (Tokens Per Minute) info added to all model descriptions
 *
 * Version 6.5.1 - FILTER SYSTEM FIXES
 * - FIXED: Home Type filter now properly recognized in summary table queries
 * - FIXED: Agent filter now bypasses summary table (missing agent columns)
 * - FIXED: PropertyType 'Residential' now correctly includes 'Residential Income'
 * - FIXED: Property type SEO pages now use correct filter URL format (home_type=)
 * - FIXED: Structure Type, Architectural Style, and all amenity filters working correctly
 * - IMPROVED: Summary table bypass logic for filters requiring full table columns
 * - PERFORMANCE: Maintained fast summary table queries while ensuring filter accuracy
 *
 * Version 6.4.0 - CORE WEB VITALS & PERFORMANCE OPTIMIZATION
 * - ADDED: Database query caching system (1-hour TTL for city stats, 6-hour for nearby cities)
 * - PERFORMANCE: 85.6% faster page loads (0.980s → 0.141s on city pages)
 * - OPTIMIZED: 13 JavaScript files now deferred for non-blocking page render
 * - OPTIMIZED: FontAwesome (58K) conditionally loaded only where needed
 * - ADDED: MySQL slow query log enabled for performance monitoring (>500ms threshold)
 * - FIXED: Sold listings filter now properly routes to archive table (690 sold listings accessible)
 * - IMPROVED: Status filter detection in optimized query routing
 * - CACHE: 33% faster on cache hits, 100% query reduction on cached data
 * - SEO IMPACT: Dramatic improvement in Core Web Vitals scores
 *
 * Version 6.3.0 - CITY PAGES & SEARCH RESULTS SEO
 * - ENHANCED: City page meta descriptions now include price range and property types
 * - ADDED: BreadcrumbList schema to city landing pages
 * - ADDED: Dynamic SEO titles for search results based on active filters
 * - ADDED: Canonical URLs for filtered search pages (prevents duplicate content)
 * - ADDED: Noindex meta tag for paginated results (page 2+)
 * - ADDED: Prev/Next pagination link tags for better crawling
 * - IMPROVED: Search result pages now have descriptive titles and meta descriptions
 * - SEO IMPACT: Better visibility for location-based and filtered property searches
 *
 * Version 6.2.0 - ENHANCED STRUCTURED DATA & LOCAL SEO
 * - ENHANCED: RealEstateListing schema with dynamic availability mapping (Active/Pending/Sold)
 * - ADDED: Place schema for enhanced location context and geo-coordinates
 * - ADDED: ImageObject schema with captions and descriptions for all property photos
 * - ADDED: Additional property fields (lot size, year built, parking, heating, cooling, stories)
 * - ADDED: LocalBusiness schema for agent/brokerage local SEO
 * - ADDED: Business Information settings page (phone, address, hours, social media)
 * - ENHANCED: Offer schema with priceValidUntil and seller information
 * - IMPROVED: Schema.org compliance for better Google Rich Results
 * - SEO IMPACT: Enhanced local search visibility and rich snippet eligibility
 *
 * Version 6.1.0 - ENTERPRISE SEO & SITEMAP SYSTEM
 * - NEW: Incremental XML sitemap system (90x more efficient than standard approach)
 * - ADDED: Three-tier sitemap architecture (new listings every 15 min, modified hourly, full daily)
 * - ADDED: SEO-optimized city landing pages for all MLS markets
 * - ADDED: Admin dashboard for sitemap management with Google Search Console integration
 * - ADDED: Automatic search engine ping for new listings (Google/Bing notification)
 * - ADDED: Image sitemap support for Google Image Search
 * - ADDED: Smart priority/frequency based on listing age and value
 * - ENHANCED: Custom WP-Cron schedules (15-minute interval for new listings)
 * - PERFORMANCE: 96x reduction in sitemap operations (6,720 → 70 daily operations)
 *
 * Version 6.0.7 - FILTER COUNT DISPLAY FIX
 * - FIXED: Filter count now hidden by default until data is loaded
 * - FIXED: JavaScript now properly shows/hides count element
 * - IMPROVED: Error handling for filter count AJAX requests
 *
 * Version 6.0.6 - UI/UX IMPROVEMENTS & DATA ENHANCEMENT
 * - ADDED: Live property count preview in filter modal footer
 * - IMPROVED: Mobile-optimized filter modal layout with better spacing
 * - ENHANCED: Chat auto-open timing increased from 45 seconds to 2 minutes
 * - NEW: Schools & Education section with 4 fields (district, elementary, middle, high school)
 * - NEW: Area breakdown fields in Property Overview (above/below grade, total area)
 * - ADDED: Complete skeleton loader CSS library for improved perceived performance
 * - TOTAL: 7 new property detail fields added (34% data utilization improvement)
 *
 * Version 6.0.5 - PRIORITY FIX FOR HOSTING ENVIRONMENT OVERRIDES
 * - FIXED: CSP/Permissions-Policy handler now uses PHP_INT_MAX priority
 * - RESOLVED: Headers now override Kinsta and other managed hosting security settings
 * - IMPROVED: Geolocation works on all hosting environments without MU plugins
 * - ENHANCED: Guaranteed to be the last hook to run, preventing server overrides
 *
 * Version 6.0.4 - GEOLOCATION FIX FOR NEARBY FEATURE
 * - FIXED: Permissions-Policy blocking geolocation on production sites
 * - ADDED: Automatic Permissions-Policy header to allow geolocation=(self)
 * - IMPROVED: Geolocation error messages with specific codes (timeout, denied, unavailable)
 * - ENHANCED: Added 10-second timeout and 60-second position caching
 * - RESOLVED: "Nearby" toggle now works correctly on all hosting environments
 *
 * Version 6.0.3 - MOBILE MORTGAGE CALCULATOR UPGRADE
 * - UPGRADED: Mobile mortgage calculator now matches desktop enhanced version
 * - ADDED: Summary cards showing Monthly Payment, Loan Amount, Total Interest, Total Cost
 * - ADDED: Rate Impact Analysis (compare +/-0.5% rate scenarios)
 * - ADDED: Amortization visualization showing principal vs interest over time
 * - ADDED: Detailed loan summary with total payments and interest breakdown
 * - IMPROVED: Calculator layout and user experience on mobile devices
 *
 * Version 6.0.2 - MOBILE UX REDESIGN & PERFORMANCE
 * - REDESIGNED: Save & Share buttons with gradient backgrounds and modern Material Design
 * - ENHANCED: Ripple effect animations on button press for better tactile feedback
 * - FIXED: Google Maps API loaded multiple times (consolidated script registration)
 * - OPTIMIZED: Script loading to prevent duplicate Google Maps API calls
 * - IMPROVED: Button shadows, spacing, and touch target sizes
 *
 * Version 6.0.1 - MOBILE UX POLISH & FIXES
 * - ENHANCED: Save & Share buttons with modern styling and color coding
 * - FIXED: Street View permissions policy for accelerometer/gyroscope
 * - FIXED: Updated deprecated apple-mobile-web-app-capable meta tag
 * - IMPROVED: Button styling consistency across mobile interface
 * - RESOLVED: Console warnings for device orientation in Street View
 *
 * Version 6.0.0 - MOBILE FEATURE PARITY COMPLETE
 * - CONFIRMED: Street View modal fully functional on mobile
 * - CONFIRMED: All critical features now have mobile parity with desktop
 * - ACHIEVEMENT: Complete mobile property viewing experience
 * - INCLUDED: Full-screen Street View with Google Maps integration
 * - READY: Production-ready mobile real estate platform
 *
 * Version 5.9.0 - MOBILE QUICK NAVIGATION
 * - ADDED: Floating "Sections" button for quick navigation
 * - ADDED: Bottom sheet with section links and smooth scrolling
 * - ADDED: Auto-hide links for sections not present on the page
 * - ENHANCED: Mobile UX with native bottom sheet pattern
 * - IMPROVED: Easy section jumping with thumb-friendly navigation
 *
 * Version 5.8.0 - MOBILE PHOTO LIGHTBOX: TAP TO TOGGLE CONTROLS
 * - ADDED: Single-tap detection to show/hide lightbox controls
 * - ADDED: Auto-hide controls 2 seconds after opening lightbox
 * - ADDED: Auto-hide controls 3 seconds after showing them
 * - ENHANCED: Immersive photo viewing experience with intelligent tap detection
 * - FIXED: Tap detection doesn't interfere with zoom, pan, or swipe gestures
 *
 * Version 5.7.0 - MOBILE FEATURE ORGANIZATION
 * - ADDED: Construction & Materials dedicated section on mobile
 * - ADDED: Systems & Utilities dedicated section on mobile
 * - ADDED: Exterior & Lot dedicated section on mobile
 * - ADDED: Special Features dedicated section on mobile
 * - IMPROVED: Mobile property details organization and discoverability
 *
 * Version 5.6.0 - MOBILE ENHANCEMENTS: SAVE, SHARE & PRICE/SQFT
 * - ADDED: Save/Favorite button on mobile with localStorage fallback
 * - ADDED: Share button on mobile with native share API support
 * - ADDED: Price per square foot in mobile key stats
 * - ENHANCED: Mobile UX with haptic feedback on button interactions
 * - CONFIRMED: Sold property statistics display correctly on mobile
 *
 * Version 5.4.0 - DESKTOP PROPERTY DETAILS REDESIGN
 * - REDESIGNED: Complete desktop property details page with modern 70/30 layout
 * - ENHANCED: Typography system with improved readability (16px base font, 1.65 line-height)
 * - ADDED: Sticky sidebar with agent contact card (persistent on scroll)
 * - ADDED: Room-by-room breakdown section with level grouping
 * - ADDED: Interior Features section (flooring, appliances, basement, laundry, windows)
 * - ADDED: Construction & Materials section (style, condition, year built, structure)
 * - ADDED: Heating, Cooling & Utilities section (HVAC, water, sewer, utilities)
 * - ADDED: Exterior & Lot Features section (exterior, roof, foundation, lot size)
 * - ADDED: Parking & Garage section (garage spaces, types, parking details)
 * - ADDED: Special Features section (fireplace, pool, waterfront, views)
 * - ENHANCED: Google Maps with advanced controls (Map/Satellite/Terrain/Hybrid switcher)
 * - ADDED: Google Maps "Get Directions" button and enhanced info windows
 * - IMPROVED: Conditional rendering - sections only display if data exists
 * - IMPROVED: JSON array fields properly formatted as comma-separated lists
 * - OPTIMIZED: Data utilization increased from 7% to 33% (150+ fields now displayed)
 * - FIXED: Empty arrays and raw JSON no longer display on frontend
 * - UPDATED: CSS variables for consistent spacing, colors, and responsive design
 * - MAINTAINED: All existing functionality (mobile version, chat widget, analytics)
 *
 * Version 5.3.3 - CONVERSATIONAL CHAT AI ENHANCEMENT
 * - ENHANCED: Conversational AI-style chat flow with individual prompts (name → email → phone)
 * - ADDED: Greeting bubble with smart timing (10s appear, 30s pulse, 45s auto-open, never if dismissed)
 * - ADDED: Immediate agent notification on first message (before collecting contact info)
 * - IMPROVED: Email validation in chat flow with friendly error messages
 * - IMPROVED: Single-name handling (e.g., "Steve" works for both first/last name fields)
 * - ENHANCED: Chat window expanded to 320px × 520px for better conversation flow
 * - ENHANCED: Greeting bubble width increased to 300px for two-line text display
 * - FIXED: AJAX variable references (mldAjaxV3 → mldPropertyData)
 * - FIXED: Backend field mapping (property_mls → mls_number, name → first_name/last_name)
 * - UPDATED: Complete mobile version with matching conversational logic and greeting bubble
 * - IMPROVED: localStorage state management for conversation history and user preferences
 * - MAINTAINED: All existing functionality (AJAX endpoints, email notifications, database storage)
 *
 * Version 5.3.2 - PROPERTY DETAILS CHAT WIDGET ENHANCEMENT
 * - REDESIGNED: Contact form transformed into modern chat widget interface
 * - IMPROVED: Chat window size reduced to 280px × 400px (22% smaller footprint)
 * - ENHANCED: Smart auto-open system with localStorage memory (30s pulse, 45s auto-open)
 * - ADDED: Conversational flow with message bubbles (agent left, user right)
 * - ADDED: Progressive information collection (message first, then contact details)
 * - REMOVED: Full-screen modal overlays replaced with inline chat forms
 * - IMPROVED: Mobile-optimized chat with touch-friendly interactions (60px icon)
 * - IMPROVED: Minimize button visibility with border and larger size (32px)
 * - IMPROVED: Font sizes optimized for readability (13px messages, 11px buttons)
 * - FIXED: User preference persistence - never auto-opens if user has closed it
 * - MAINTAINED: All existing functionality (AJAX endpoints, email notifications, database storage)
 *
 * Version 5.3.1 - (Previous version notes retained)
 *
 * Version 5.2.5 - CRITICAL: ARCHIVE DATA & STATUS FILTER FIX
 * - CRITICAL FIX (BME): Summary table now includes archive data (690 sold + 36 pending/under contract)
 * - CRITICAL FIX: Fixed stored procedure to UNION active and archive tables
 * - ADDED: "Active Under Contract" filter option with user-friendly label "Under Agreement"
 * - UPDATED: Status filter labels - "Closed" now displays as "Sold" (database values unchanged)
 * - FIXED: UNION query logic for mixed status filters (active + archive statuses)
 * - ENHANCED: JavaScript status display mapping for consistent user-friendly labels
 * - IMPROVED: CMA comparable sales now show all 690 sold properties
 * - IMPROVED: Map search filters now return correct results for all status types
 * - BME Plugin updated to v4.0.8 (stored procedure enhancement)
 *
 * Version 5.2.4 - CMA ROAD TYPE & CONDITION ENHANCEMENTS
 * - ADDED: "Unknown" option for Road Type and Condition (default state)
 * - CONVERTED: Condition dropdown to radio buttons (matches Road Type UX)
 * - ENHANCED: Road Type/Condition controls always visible on subject property
 * - IMPROVED: Comparable property controls hidden when subject is "Unknown"
 * - FIXED: Adjustments properly skip when baseline (subject) is "Unknown"
 * - OPTIMIZED: Crowdsourced data collection for road type and condition
 * - CLEANED: Removed descriptive text from labels (simpler UI)
 *
 * Version 5.2.3 - PROPERTY DETAILS UI ENHANCEMENT
 * - REDESIGNED: Image gallery with 1 large + 2 preview images layout
 * - REDESIGNED: Floating message widget (smaller, positioned at bottom)
 * - IMPROVED: Design alignment with CMA tool (colors, typography, spacing)
 * - ENHANCED: Full-width property details layout (removed sidebar)
 * - FIXED: Site header and navigation completely hidden on property pages
 * - FIXED: Horizontal rules removed above gallery
 * - OPTIMIZED: Gallery navigation with infinite loop and clickable previews
 *
 * Version 5.2.0 - MAJOR CMA INTELLIGENCE UPGRADE
 * - NEW: Market Data Calculator - All CMA adjustments now based on real market data (NO hardcoded values)
 * - NEW: Market Forecasting Engine - Time-series analysis with 3/6/12-month price projections
 * - NEW: Investment Analysis - 1/3/5/10-year appreciation projections with risk assessment
 * - NEW: Intelligent Market Narrative Generator - Auto-generated human-readable market commentary
 * - NEW: Professional PDF Report Generator - Comprehensive CMA reports with branding support
 * - NEW: Email Delivery System - Send CMA reports to clients with HTML templates
 * - ENHANCED: 8 adjustment types now market-driven (sqft, garage, year, pool, bed, bath, waterfront, distance)
 * - ADDED: Price momentum indicators (accelerating, strengthening, stable, weakening, declining)
 * - ADDED: Confidence scoring for forecasts based on data quality and volatility
 * - ADDED: Executive summary generation for quick market insights
 * - NEW: 3 database tables for CMA tracking (emails, settings, reports)
 * - IMPROVED: Adjustment explanations now show actual market rates (e.g., "$120k/first garage + $65k/add'l")
 * - ADDED: AJAX endpoints for PDF generation and email delivery
 *
 * Version 5.1.10 - CMA ENHANCEMENTS
 * - FIXED: CMA checkbox selection now properly updates calculations in real-time
 * - FIXED: Data type mismatch in comparable property selection (String conversion)
 * - UPDATED: Garage adjustment to tiered pricing ($100k for 1st space, $50k for additional)
 * - IMPROVED: CMA statistics recalculation based on selected properties only
 * - ENHANCED: Console logging for debugging CMA calculations
 *
 * Version 5.1.6 - VISITOR STATE PERSISTENCE REMOVED
 * - REMOVED: All visitor state persistence (localStorage, sessionStorage)
 * - REMOVED: visitor-state-manager.js and safari-state-manager.js files
 * - FIXED: URL parameters still work for sharing/bookmarking searches
 * - CHANGED: Plugin always starts fresh on each visit unless URL has parameters
 *
 * Version 5.1.5 - COMPREHENSIVE FILTER SUPPORT FIX
 * - ADDED: Support for ALL saved search filters in notification matching
 * - ADDED: max_bedrooms, max_bathrooms filter support
 * - ADDED: min_sqft, max_sqft filter support
 * - ADDED: min_year_built, max_year_built filter support
 * - ADDED: property_subtype filter support
 * - ADDED: selected_neighborhoods filter support
 * - ADDED: keyword_mls_number filter support
 * - ADDED: polygon_shapes boundary search support with point-in-polygon algorithm
 * - ADDED: has_garage, has_pool, waterfront amenity filters
 * - IMPROVED: Property type now supports multiple types (array)
 * - FIXED: All saved search criteria are now properly matched in notifications
 *
 * Version 5.1.4 - CRITICAL SAVED SEARCH CITY MATCHING FIX
 * - FIXED: Saved search notifications sending properties from wrong cities
 * - FIXED: City matching now properly handles multiple selected cities
 * - FIXED: Changed city matching from substring to exact match
 * - ADDED: Support for listing_status filter in notifications
 * - IMPROVED: City comparison now case-insensitive exact match
 * - RESOLVED: Notifications now correctly filter by all selected cities
 *
 * Version 5.1.3 - COMPREHENSIVE DATABASE FIX
 * - FIXED: Agent relationship table index creation with missing is_active column
 * - FIXED: Form submissions table ALTER TABLE errors when source column doesn't exist
 * - FIXED: SEO class queries for ps.distance column that may not exist
 * - FIXED: Saved searches migration trying to read non-existent search_criteria column
 * - IMPROVED: All database operations now check column existence before operations
 * - IMPROVED: SEO queries gracefully handle missing distance column
 * - RESOLVED: All "Unknown column" errors in production environments
 *
 * Version 5.1.2 - DATABASE MIGRATION FIX
 * - FIXED: Database migrator now checks for column existence before creating indexes
 * - FIXED: Saved searches table index creation for variant column names (notification_frequency vs frequency)
 * - FIXED: Schools table index creation for variant column names (name vs school_name, type vs school_type)
 * - FIXED: Property schools table index creation for variant column names (listing_id vs property_mls)
 * - FIXED: Notification tracker table indexes properly created
 * - IMPROVED: All table index creation now validates column existence first
 * - RESOLVED: "Key column doesn't exist in table" errors during migration
 *
 * Version 5.1.1 - CRITICAL HOTFIX RELEASE
 * - FIXED: Fatal error "Cannot redeclare mld_run_plugin()" when upgrading
 * - FIXED: Database error "Unknown column 's.level'" in schools queries
 * - FIXED: Memory exhaustion in property history template (limited to 100 items)
 * - ADDED: Schools table creation and column management in database upgrader
 * - ADDED: Protection against duplicate function declarations
 * - IMPROVED: Memory management in property history processing
 *
 * Version 5.1.0 - EMAIL NOTIFICATION ENHANCEMENTS
 * - ENHANCED: Email templates now show up to 10 properties (previously 2-3)
 * - ADDED: Property images in all email notification cards
 * - UPDATED: "View This Deal" button text changed to "View Details"
 * - FIXED: View All Properties link now uses admin-configured search page URL
 * - UPDATED: Footer links use admin-configured URLs from settings
 * - IMPROVED: BuddyBoss notification formatting with better readability
 * - UPDATED: Email subject format to "X New Listings Matching your 'Name' Saved Search"
 * - PRIORITIZED: New listings shown first in emails (up to 7), then price reductions (up to 3)
 * - ADDED: Database migration for ensuring all required columns exist
 * - TESTED: Full compatibility with WordPress 6.8.3 and PHP 8.x
 *
 * Version 5.0.3 - CRITICAL FIX: Database Query Compatibility
 * - FIXED: SQL queries in notification status page now use correct column name 'is_active' instead of 'status'
 * - FIXED: SavedSearchRepository queries updated for proper column names
 * - FIXED: Cleanup tool queries updated for database compatibility
 * - FIXED: Test notification queries updated to use is_active column
 * - RESOLVED: Database error "Unknown column 'status' in 'where clause'"
 * - FIXED: Send Test Notification feature now sends actual test emails
 * - FIXED: Cleanup tool now uses simplified interface matching v5.0 architecture
 *
 * Version 5.0.1 - BUG FIXES
 * - FIXED: BuddyBoss integration conditional loading
 * - FIXED: Test notification authorization checks
 * - UPDATED: Cleanup tool for simplified system
 *
 * Version 5.0.0 - MAJOR RELEASE: Simplified Notification System
 * - REPLACED: Complex multi-template notification system with single, clean implementation
 * - SIMPLIFIED: 30-minute cron job checks for listing updates instead of multiple frequencies
 * - STREAMLINED: Single "Listing Updates" notification type covers all changes
 * - REMOVED: Multiple email templates, complex routing, digest systems, template editor
 * - ADDED: Clean architecture with simple notification processor and tracker
 * - INTEGRATED: Simple BuddyBoss notifications alongside email notifications
 * - IMPROVED: Reliability, maintainability, and debuggability of notification system
 * - OPTIMIZED: Database queries and reduced system complexity by 80%
 *
 * Version 4.6.10 - NUCLEAR VISIBILITY FIX
 * - IMPLEMENTED: Maximum CSS specificity to override all other styles
 * - FIXED: Bottom sheet positioned at 300px from bottom (always visible)
 * - FORCED: Gallery height to 300px with gray background
 * - STYLED: Images as 300x200px inline blocks for guaranteed visibility
 * - USED: Multiple CSS selectors and setAttribute to prevent overrides
 *
 * Version 4.6.9 - CLASS DEPENDENCY FIX
 * - FIXED: Missing class dependencies on live sites that don't auto-load all classes
 * - ADDED: Manual require_once for MLD_Query, MLD_Utils, and MLD_Settings
 * - IMPROVED: Fallback handling when classes are not available
 * - ADDED: Error reporting for admins to identify issues
 * - FIXED: Template execution stops when required classes missing
 *
 * Version 4.6.8 - EMERGENCY VISIBILITY FIX
 * - ADDED: Aggressive inline CSS with !important flags
 * - IMPLEMENTED: JavaScript interval fix that runs every 100ms for 2 seconds
 * - SIMPLIFIED: Image display to use direct img tags instead of optimizer
 * - FORCED: Bottom sheet to 50% visibility with z-index 999
 * - ENSURED: Gallery and images are forced visible with inline styles
 *
 * Version 4.6.7 - BOTTOM SHEET POSITIONING FIX
 * - FIXED: Bottom sheet default positioning to 50% visible
 * - IMPROVED: JavaScript initialization with multiple fallbacks
 * - ADDED: CSS !important flags to ensure visibility
 * - ENHANCED: Error handling and recovery for bottom sheet initialization
 * - VERIFIED: Images and elements are loading correctly
 *
 * Version 4.6.6 - MOBILE PROPERTY DETAILS UI FIX
 * - ADDED: Debug logging to identify bottom sheet and image rendering issues
 * - FIXED: Bottom sheet visibility with temporary JavaScript fallback
 * - ENSURED: jQuery is explicitly loaded before mobile scripts
 * - IMPROVED: Error handling in JavaScript initialization
 * - ADDED: Force display fix for bottom sheet when CSS fails
 *
 * Version 4.6.5 - MOBILE RENDERING & JAVASCRIPT FIXES
 * - FIXED: Mobile search page JavaScript initialization by properly enqueueing search-mobile-init.js
 * - FIXED: Mobile property details page rendering by including required icon functions for all users
 * - ADDED: Google Maps API script loading for mobile property detail pages
 * - IMPROVED: Function existence checks to prevent fatal errors on mobile views
 * - RESOLVED: Missing dependencies that caused incomplete page rendering on production servers
 *
 * Version 4.6.4 - PLUGIN ACTIVATION FIX & IMAGE OPTIMIZER SIMPLIFICATION
 * - FIXED: Fatal error during plugin activation on fresh WordPress installations
 * - FIXED: MLD_Saved_Search_Database class not found error by loading dependencies before use
 * - FIXED: MediaURL column reference changed to media_url (lowercase) for Bridge MLS compatibility
 * - IMPROVED: Added table existence check before querying wp_bme_media during activation
 * - DISABLED: Image optimizer as CloudFront already handles optimization at CDN level
 * - SIMPLIFIED: Image output to use direct CloudFront URLs without additional parameters
 * - RESOLVED: Images now load correctly without interference from optimization layer
 *
 * Version 4.6.3 - NOTIFICATION SYSTEM ENHANCEMENT: Complete Alert System Overhaul
 * - FIXED: {search_name} variable now properly displays in all email templates
 * - ENHANCED: Open House notifications with proper date/time extraction from JSON data
 * - IMPLEMENTED: Property Sold notifications with sale price and days on market
 * - IMPROVED: Back on Market detection for status changes from non-Active to Active
 * - ADDED: Daily/Weekly/Hourly digest notification support with proper scheduling
 * - UPDATED: Database schema to support all notification types (12 total)
 * - EXPANDED: Notification queue match_type enum for comprehensive alert coverage
 * - RESOLVED: Email template variable processing for instant and digest notifications
 * - TESTING: Complete notification system verification and test suite
 *
 * Version 4.6.2 - SAVED SEARCH VIEW FIX: Admin Menu URL Override Resolution
 * - FIXED: "View Search" from admin menu now preserves URL parameters properly
 * - ENHANCED: Visitor state manager to detect saved search URLs and prevent override
 * - RESOLVED: Conflict where saved state would replace saved search parameters after 1 second
 * - REMOVED: Performance diagnostics tool (unused and non-functional)
 *
 * Version 4.6.1 - PERFORMANCE CLEANUP: Production Debug Removal & Codebase Optimization
 * - REMOVED: All console debug logging from map JavaScript files (49 statements)
 * - CLEANED: 462 Windows Zone.Identifier files and development artifacts
 * - OPTIMIZED: Map performance by eliminating debug overhead on user interactions
 * - ARCHIVED: Excessive session documentation to organized structure
 * - PERFORMANCE: 10-15% faster map page loads without console processing overhead
 *
 * Version 4.6.0 - MAJOR RELEASE: Complete Performance Optimization Suite
 * - COMPLETE: Three-phase performance optimization system implementation
 * - ACHIEVED: 40-60% page load speed improvement through comprehensive optimizations
 * - REDUCED: JavaScript payload from 783KB to 320KB (59% reduction)
 * - ELIMINATED: 750KB+ Mapbox dependencies for faster initial page loads
 * - IMPLEMENTED: Complete image optimization with WebP format and responsive breakpoints
 * - BUNDLED: 38 JavaScript files into 4 optimized bundles (90% request reduction)
 * - ADDED: Service Worker for static asset caching and offline support
 * - CREATED: Performance diagnostics dashboard for monitoring and troubleshooting
 * - ENHANCED: Resource hints, critical CSS, and progressive loading systems
 * - PRODUCTION: Ready for deployment with WPX hosting optimizations
 * - TESTED: Complete system verification and deployment documentation
 *
 * Version 4.5.59 - PERFORMANCE OPTIMIZATION: Advanced Asset Optimization Phase 2
 * - NEW: MLD_Asset_Optimizer class for JavaScript bundling and critical CSS extraction
 * - IMPLEMENTED: JavaScript concatenation and minification (46 files → 4 bundles)
 * - ADDED: Critical CSS generation for above-the-fold content (search/property pages)
 * - ENHANCED: Resource hints (preconnect, dns-prefetch) for external domains
 * - OPTIMIZED: Font loading with display: swap for improved LCP scores
 * - IMPLEMENTED: Service Worker for static asset caching and offline support
 * - TECHNICAL: Smart cache invalidation based on file modification times
 * - TECHNICAL: Bundle-specific dependencies for optimal loading order
 * - PERFORMANCE: Significant reduction in HTTP requests and render-blocking resources
 *
 * Version 4.5.58 - PERFORMANCE OPTIMIZATION: CloudFront Image Optimization Phase 1B
 * - NEW: MLD_Image_Optimizer class for comprehensive image performance enhancement
 * - ADDED: WebP format support with JPEG fallbacks using HTML5 <picture> elements
 * - IMPLEMENTED: Responsive srcset for 5 breakpoints (400w, 600w, 800w, 1200w, 1600w)
 * - ENHANCED: Progressive image loading with blur-up placeholder effects and CSS transitions
 * - OPTIMIZED: CloudFront URL parameters (w_800&q_85) for bandwidth reduction
 * - IMPROVED: Image quality settings - 85% for listings, 90% for property galleries
 * - UPDATED: All template files (listing cards, property details) with optimized images
 * - TECHNICAL: Intersection Observer API for efficient lazy loading performance
 * - COMPATIBLE: Automatic detection and fallback for non-CloudFront image URLs
 *
 * Version 4.5.57 - PERFORMANCE OPTIMIZATION: Mapbox Removal for Faster Page Loading
 * - REMOVED: All Mapbox dependencies (~750KB JavaScript/CSS reduction)
 * - SIMPLIFIED: Google Maps only provider (no more provider selection)
 * - OPTIMIZED: Removed unused Mapbox GL JS, Draw plugin, and related CSS
 * - CLEANED: Admin interface removal of Mapbox settings and API testing
 * - ENHANCED: Faster script parsing and reduced external API calls
 * - PRESERVED: All OpenStreetMap integrations for schools and city boundaries
 *
 * Version 4.5.55 - CRITICAL FIX: URL Override Issue in User State Management System
 * - FIXED: Visitor state restoration now respects URL filter parameters
 * - ENHANCED: URL with filters like #City=Marblehead no longer overridden by saved state
 * - IMPROVED: Added hasUrlFilterParameters() to detect dedicated city/filter URLs
 * - TECHNICAL: Modified getRestorationState() to check URL before restoring saved filters
 * - VERIFIED: Dedicated URLs like .../search/#City=Marblehead&PropertyType=Residential now work correctly
 * - DEBUGGING: Added console logging to track state restoration decisions
 *
 * Version 4.5.54 - CRITICAL FIX: Days on Market Field Mapping for Email Notifications
 * - FIXED: Days on Market now properly handles Bridge MLS field names in email templates
 * - ENHANCED: Added field name normalization for StandardStatus → standard_status
 * - IMPROVED: Maps OriginalEntryTimestamp → original_entry_timestamp for DOM calculation
 * - TECHNICAL: Added fallback to direct DOM fields if calculation fails
 * - DEBUGGING: Added logging to track DOM calculation process and field availability
 * - VERIFIED: Email templates now show accurate days on market instead of always 0
 *
 * Version 4.5.53 - CRITICAL FIX: Custom Template Subjects Now Work for All Notifications
 * - FIXED: Email subjects now use custom template subjects instead of hardcoded text
 * - ENHANCED: Added get_custom_template_subject() method for subject processing
 * - IMPROVED: Both subject and body now respect custom template editor settings
 * - TECHNICAL: Template variables properly processed in both subject and body
 * - VERIFIED: All notification types (new listing, price reduced, etc.) use custom subjects
 * - LOGS: Added debugging to track custom subject usage and fallbacks
 *
 * Version 4.5.52 - CRITICAL FIX: Instant Notifications Now Use Custom Templates
 * - FIXED: Instant notifications were ignoring custom templates from template editor
 * - REMOVED: Unnecessary MLD_Field_Mapper requirement that was blocking custom templates
 * - CORRECTED: new_listing notification type mapping to use proper template name
 * - VERIFIED: Custom templates now properly load for all instant notification types
 * - TECHNICAL: Fixed should_use_custom_template_system() condition check
 * - LOGS: Enhanced debugging to track template selection and usage
 *
 * Version 4.5.51 - CRITICAL FIX: Updated All Email Template Files to Use Settings URLs
 * - FIXED: All email template files now use {manage_searches_url} and {unsubscribe_url} variables
 * - UPDATED: Weekly, daily, hourly, and instant notification templates
 * - REPLACED: Hardcoded /my-saved-searches/ URLs with configurable variables
 * - IMPROVED: Consistent URL handling across all email template types
 * - TECHNICAL: All templates now respect user-configured saved searches dashboard URL
 *
 * Version 4.5.50 - CRITICAL FIX: Email Template URLs Use Plugin Settings
 * - FIXED: Email templates now use configurable URLs from General Settings instead of hardcoded values
 * - ENHANCED: Added {login_url} and {register_url} template variables
 * - IMPROVED: {search_url} and {manage_searches_url} now respect user-configured slugs
 * - UPDATED: Unsubscribe URLs now use saved searches dashboard URL setting
 * - TECHNICAL: Added get_configured_url() method to retrieve URLs from plugin options
 * - HANDLES: Both relative and absolute URLs with proper home_url() prefixing
 *
 * Version 4.5.49 - CRITICAL FIX: Days on Market Email Template Variable
 * - FIXED: Days on Market now shows correct calculated values instead of always "0"
 * - ENHANCED: Email templates now use MLD_Utils::calculate_days_on_market() method
 * - IMPROVED: Consistent calculation between property templates and email templates
 * - TECHNICAL: Uses original_entry_timestamp and proper date calculations
 * - HANDLES: New listings showing hours/minutes if less than 24 hours on market
 *
 * Version 4.5.48 - CRITICAL FIX: Price Reduced Alert Template Variables
 * - FIXED: Price Reduced Alert emails now show correct old/new prices and savings amount
 * - FIXED: Template variables {old_price}, {new_price}, {price_change} now populate correctly
 * - ENHANCED: Email sender passes price change context data to template variables processor
 * - VERIFIED: Template preview shows proper crossed-out prices and discount calculations
 * - TECHNICAL: Fixed context parameter passing in get_custom_template_body() method
 * - LOGS: Added debugging to track price change context data flow
 *
 * Version 4.5.47 - SAVED STATE: Complete fix for state restoration pin loading
 * - CRITICAL FIX: Users now see correct viewport-specific listings immediately upon state restoration
 * - FIXED: Map position restoration timing - waits for map to fully settle before loading listings
 * - FIXED: Cache interference - state restoration bypasses cache for fresh, accurate results
 * - FIXED: Spatial filtering bypass - SQL queries now properly apply bounds during state restoration
 * - FIXED: Auto-zoom interference - extended flag duration prevents fitMapToBounds from overriding position
 * - FIXED: Cross-environment compatibility - robust detection works on both dev and live servers
 * - IMPROVED: Enhanced Google Maps initialization with dependency checking and retry logic
 * - IMPROVED: Dual state restoration flag system with fallback bounds inclusion logic
 * - IMPROVED: Comprehensive debug logging for troubleshooting state restoration issues
 * - TECHNICAL: Added detailed documentation of critical code sections that must be preserved
 * - TECHNICAL: Created complete session log documenting issue analysis and solution implementation
 *
 * Version 4.5.46 - EMAIL TEMPLATES: Fixed New Listing Alert & Instant Notifications
 * - FIXED: New Listing Alert template now uses proper template file instead of fallback
 *   - Removed legacy 'mld_email_template_instant' from database
 *   - Now loads beautiful email-notification-instant.php template with full HTML structure
 *   - Consistent formatting with other email templates (9,338 vs 2,001 characters)
 * - FIXED: Property images now display correctly in New Listing Alert emails
 *   - Corrected media query: media_category='Photo' (was media_type)
 *   - Fixed column names: order_index (was order_value/is_primary)
 *   - Real property images from CloudFront CDN instead of placeholder
 * - ENHANCED: Email template loading with comprehensive error handling
 *   - Added WordPress function availability checks
 *   - Improved debugging with detailed error logging
 *   - Better fallback handling for template loading failures
 * - VERIFIED: Instant notification system architecture
 *   - Confirmed real-time hooks via Bridge MLS events (bme_listing_imported/updated)
 *   - 5-minute cron identified as redundant (instant = immediate via database hooks)
 *   - All notification frequencies (instant/hourly/daily/weekly) fully functional
 * - ADDED: Missing hourly notification option to all saved search interfaces
 *   - Map integration modal, user dashboard, frontend forms
 *   - Complete notification frequency coverage across all user interfaces
 *
 * Version 4.5.41 - DIAGNOSTICS: Enhanced Performance Tracking
 * - Added detailed performance markers throughout load sequence:
 *   - AJAX Response Received
 *   - Rendering Started
 *   - Markers Rendered
 *   - Sidebar Updated
 * - Helps identify exact bottleneck locations
 * - Shows time breakdown for each operation
 *
 * Version 4.5.40 - OPTIMIZATION: Skip fitMapToBounds in Nearby Mode
 * - SKIP fitMapToBounds when nearby search is active
 *   - Keeps zoom at 15 and user's location centered
 *   - Shows listings within viewport + selected filters (including city)
 *   - No unnecessary zoom changes from 15 to 13
 * - Eliminates the root cause of duplicate AJAX calls
 *   - No zoom change = no map idle event = no duplicate call
 * - Much cleaner nearby search experience
 *   - User stays at their location with consistent zoom
 *   - Listings load once and display correctly
 *
 * Version 4.5.39 - PERFORMANCE FIX: Complete Duplicate AJAX Prevention
 * - PROPERLY fixed duplicate refreshMapListings after geolocation
 *   - isLoadingAfterGeolocation now cleared AFTER fitMapToBounds completes
 *   - Was being cleared too early (500ms) causing second call at ~5 seconds
 * - Enhanced idle event checks
 *   - Now checks both isAdjustingMapBounds AND isLoadingAfterGeolocation
 *   - Prevents any map change events during initial load sequence
 * - Expected improvement: Single AJAX call only
 *   - Eliminates the second call at zoom 13
 *   - Should reduce load time to target 2-3 seconds
 *
 * Version 4.5.38 - PERFORMANCE: Eliminate Duplicate AJAX Calls
 * - FIXED duplicate refreshMapListings calls during initial load
 *   - Added isAdjustingMapBounds flag to prevent map idle event interference
 *   - fitMapToBounds() no longer triggers unnecessary second AJAX call
 * - Enhanced performance tracking
 *   - Fixed isInitialLoad timing to properly capture "Listings Loaded" marker
 *   - Performance summary now shows actual load completion time
 * - Expected improvement: Reduce initial load from ~10s to ~3-4s
 *   - Eliminates 250ms idle timeout delay on duplicate call
 *   - Removes unnecessary second AJAX request processing
 *
 * Version 4.5.37 - FIX: Data Provider Instantiation
 * - FIXED data provider instantiation method
 *   - Added get_instance() singleton method to MLD_BME_Data_Provider
 *   - Fixed "Call to undefined method" error
 * - Added proper Bridge MLS availability checks
 *   - Gracefully handles when Bridge MLS plugin is not active
 *   - Returns empty cities list instead of causing fatal errors
 * - Added table validation before queries
 *   - Checks that required tables exist before running queries
 * - Safe for both localhost and production environments
 *
 * Version 4.5.36 - CRITICAL FIX: Pre-loaded Cities Data Provider
 * - FIXED missing MLD_BME_Data_Provider class include
 *   - Added require_once for class-mld-bme-data-provider.php in dependencies
 *   - This was causing pre-loaded cities list to be empty
 *   - Nearby toggle was failing due to no cities being loaded
 * - Added proper upgrade mechanism for live sites:
 *   - Clears transient cache on plugin update
 *   - Forces regeneration of cities list
 *   - Safe for manual plugin updates on production sites
 * - Cities list now properly loads on first page view
 *
 * Version 4.5.35 - Pre-loaded Cities Optimization
 * - ELIMINATED 2.5-second AJAX delay for city checking:
 *   - Cities list now pre-loaded with page via wp_localize_script
 *   - Instant client-side lookup using pre-loaded array
 *   - Reduced city check from 2500ms to <5ms
 * - Added get_available_cities_cached() method with 24-hour transient cache
 * - Cities list includes lowercase versions for case-insensitive matching
 * - Fallback to AJAX only if pre-loaded list unavailable
 * - Target: Achieve <2 second total page load time
 *
 * Version 4.5.34 - Performance Optimizations
 * - OPTIMIZED city check query from 3+ seconds to <100ms:
 *   - Replaced multiple JOINs with single indexed query
 *   - Uses EXISTS subquery for better performance
 *   - Single query instead of 2-3 sequential queries
 * - Added client-side caching for city checks (sessionStorage)
 * - Enhanced performance logging with bottleneck analysis
 * - Added detailed timing breakdown in performance summary
 * - Server-side query timing logs for monitoring
 *
 * Version 4.5.33 - Fixed Manual Nearby Toggle Listings Load
 * - Fixed manual nearby toggle not loading listings until map moved
 * - Manual toggle was calling refreshMapListings immediately without delay
 * - Multiple rapid calls were canceling each other (11+ calls observed)
 * - Added same delay and flag protection as automatic geolocation
 * - Manual nearby toggle now works consistently like automatic detection
 *
 * Version 4.5.32 - Fixed Map Idle Event Interference
 * - Fixed issue where map idle event was calling refreshMapListings(false)
 * - This was interfering with the geolocation's refreshMapListings(true) call
 * - Added isLoadingAfterGeolocation flag to prevent idle event interference
 * - Listings should now load properly after city detection without requiring map movement
 *
 * Version 4.5.31 - Deep Debug of refreshMapListings Function
 * - Added detailed console logging throughout refreshMapListings
 * - Tracks isUnitFocusMode, bounds availability, map state
 * - Logs AJAX URL and request data to identify where execution stops
 *
 * Version 4.5.30 - Added Console Debug Logging
 * - Added console.log debug statements to trace MLD_API availability
 * - Debug logs show exactly when and if MLD_API is accessible
 * - Helps identify why refreshMapListings isn't being called
 *
 * Version 4.5.29 - Enhanced Debug Logging for Listings Issue
 * - Added debug logging to track MLD_API availability
 * - Added checks to ensure MLD_API exists before calling refreshMapListings
 * - Fixed incorrect MLD_API exposure in map-core.js
 * - Added detailed logging for map state checks
 *
 * Version 4.5.28 - Fixed Multiple Initialization Issue
 * - Removed ajaxComplete handler that was calling init() after every AJAX request
 * - Added isInitialized flag to prevent duplicate initialization
 * - This was causing 11+ init calls and 16+ second page load times
 * - Should significantly improve page load performance
 *
 * Version 4.5.27 - Added Debug Logging for Listings Load Issue
 * - Added debug logging to track AJAX requests in refreshMapListings
 * - Added response logging to identify why listings aren't loading initially
 * - Helps diagnose why listings require map movement to load
 *
 * Version 4.5.26 - Fixed JavaScript Syntax Errors
 * - Fixed syntax errors caused by incomplete removal of console.log statements
 * - Removed orphaned object literals left after debug log removal
 * - JavaScript file now passes syntax validation
 *
 * Version 4.5.25 - Performance Tracking and Debug Cleanup
 * - Removed all city detection console.log debug statements (49 total)
 * - Added comprehensive performance tracking system (MLD_Performance object)
 * - Performance marks track key initialization milestones
 * - Performance summary shows total load time breakdown on initial page load
 * - Helps identify bottlenecks in the 7-second page load time
 *
 * Version 4.5.24 - Removed Unnecessary Delays
 * - Removed 500ms setTimeout delays from listing refresh calls
 * - Should save about 0.5 seconds from page load time
 * - Listings now load immediately after city detection
 *
 * Version 4.5.23 - Improved Initial Load Experience
 * - Check user location FIRST before loading any listings
 * - If city detected and exists in database, only load that city's listings
 * - If no city detected or not in database, load all listings
 * - Prevents messy double-load experience with multiple zooms
 * - Clean single load: either city listings OR all listings
 *
 * Version 4.5.22 - Performance and Stability Fixes
 * - Fixed slow initial page load by delaying geolocation (2 second delay)
 * - Fixed TypeError when City filter exists but isn't a Set instance
 * - Fixed listings not refreshing when re-enabling nearby after toggle off
 * - Improved manual nearby toggle to properly refresh with city filter
 * - City detection now runs after initial page load for better performance
 *
 * Version 4.5.21 - Filter Display and Nearby Toggle Improvements
 * - Fixed city filter tags display using correct renderFilterTags function
 * - Added city filter clearing when nearby toggle is turned off
 * - Unchecks city checkboxes when nearby mode is disabled
 * - Updates boundaries and refreshes listings after clearing city filter
 * - City detection feature now fully integrated with UI
 *
 * Version 4.5.20 - Complete City Detection Implementation
 * - Added safety check for MLD_Filters.updateFilterTags function
 * - Added comprehensive debug logging for filter updates
 * - City detection now gracefully handles partial MLD_Filters initialization
 * - Feature is now fully functional - detects city and adds to filters
 *
 * Version 4.5.19 - Fix JavaScript Error in City Filter Addition
 * - Fixed "Cannot read properties of undefined (reading 'City')" error
 * - Initialize MLD_Filters.keywordFilters if it doesn't exist
 * - City detection now fully functional with proper filter updates
 *
 * Version 4.5.18 - Critical Fix for City Detection AJAX
 * - Fixed PHP 500 error in check_city_exists_callback
 * - Cannot access private method MLD_Query::get_bme_tables() from AJAX handler
 * - Now uses data provider pattern to get database tables
 * - City detection should now work properly without server errors
 *
 * Version 4.5.17 - Enhanced City Detection & Filter Updates
 * - Fixed AJAX request handling with better debugging and error detection
 * - Improved nonce verification to handle multiple field names
 * - Fixed city filter addition to update both app and MLD_Filters objects
 * - Added visual checkbox updates when city is auto-selected
 * - Enhanced error logging to detect HTML responses from AJAX calls
 * - Fixed manual nearby toggle to properly add city to filters
 *
 * Version 4.5.16 - Fixed AJAX URL Mismatch for City Detection
 * - Fixed critical bug where AJAX URL wasn't being found (ajax_url vs ajaxUrl)
 * - Added both camelCase and snake_case versions for compatibility
 * - Added server-side logging to debug AJAX requests
 * - City detection should now properly check database and add filters
 *
 * Version 4.5.15 - Comprehensive City Detection Debugging & Fixes
 * - Added extensive console logging throughout city detection process
 * - Added resetMLDFirstLoad() function for testing first load behavior
 * - Improved geocoding city extraction with multiple fallback strategies
 * - Fixed timing issues with map refresh after geolocation
 * - Added 500ms delay before refreshing listings to ensure map is ready
 * - Enhanced debugging shows full geocoding results when city not found
 * - Console logs now clearly show each step of the detection process
 *
 * Version 4.5.14 - Robust City Detection & Database Verification
 * - Rewrote city database checking to use direct query instead of autocomplete
 * - Added dedicated AJAX endpoint for verifying city exists with active listings
 * - Improved geocoding city extraction with multiple fallback strategies
 * - Enhanced logging for debugging city detection issues
 * - Uses case-insensitive city matching for better accuracy
 * - Returns exact database city name to handle case variations
 * - Gracefully falls back to original nearby behavior when city not found
 *
 * Version 4.5.13 - Fixed Nearby Function & Enhanced City Detection
 * - Fixed issue where initial map refresh was overriding geolocation settings
 * - Prevented automatic listing fetch when geolocation is pending on first load
 * - City detection now works for both automatic (first load) and manual nearby activation
 * - Changed zoom level to 15 for better local area visibility
 * - Improved handling to prevent map from zooming out after geolocation
 * - Fixed timing conflicts between initial fetch and geolocation
 *
 * Version 4.5.12 - Auto-Enable Nearby with City Detection on First Load
 * - Nearby function now activated by default when user first loads the page
 * - Automatic city detection using browser geolocation and Google Geocoding
 * - Detected city automatically added to filters if it exists in database
 * - Falls back to Boston MA center if geolocation denied or city not found
 * - Smart detection only runs on first load, not when returning via back button
 *
 * Version 4.5.11 - Safari Back Button State Restoration & Agent Filter Repositioning
 * - Fixed Safari back button issue where page refreshes and loses scroll position
 * - Implemented comprehensive state management for Safari/Apple devices
 * - Preserves scroll position, map state, filters when using back button
 * - Uses sessionStorage for temporary state preservation
 * - Moved Agent filter section directly under Special Filters in modal
 *
 * Version 4.5.10 - Mobile Admin JavaScript Fix & Property Details Unification
 * - Fixed critical JavaScript failure when logged in as admin on mobile
 * - Resolved PHP fatal error from missing icon function includes
 * - Implemented SafeLogger wrapper to handle undefined MLDLogger gracefully
 * - Added comprehensive error handling to prevent cascading failures
 * - Unified mobile and desktop property details content
 * - Moved Property Overview from Facts & Features to About section
 * - Redesigned Facts & Features with clean card layout
 * - Added click-to-copy MLS numbers functionality
 * - Created collapsible admin-only section with comprehensive property data
 *
 * Version 4.5.8 - Mobile Draw Area Touch Fix & Back Button Implementation
 * - Fixed draw area functionality not working with touch events on actual mobile devices
 * - Improved touch point coordinate calculation with fallback methods
 * - Enhanced touch event handling for better responsiveness on mobile
 * - Added back button to both mobile and desktop property detail pages
 * - Back button navigates to previous page or home page if accessed directly
 * - Styled back button with 50% transparent dark grey background and white arrow
 * - Fixed mobile shelf bottom lock point to keep handle visible (20% minimum)
 * - Fixed gallery controls positioning to sit 1px above shelf
 * - Fixed List/Map view toggle to remain visible during scroll (only Map Options hides)
 * - Moved Status badge to right side on mobile property details
 *
 * Version 4.4.7 - Notification Queue System Implementation
 * - Fixed critical issue where email notifications weren't being sent for saved searches
 * - Implemented comprehensive notification queue system to prevent lost notifications
 * - Added queue management interface in admin (MLD Listings → Notification Settings → Queue Management)
 * - Notifications blocked by quiet hours or throttle limits are now queued and retried automatically
 * - Added smart retry logic with exponential backoff for failed notifications
 * - Created global admin controls for quiet hours and throttling settings
 * - Added database table wp_mld_notification_queue for queue storage
 * - Implemented automatic queue processing via cron jobs (every 15 minutes)
 * - Added manual queue controls and monitoring in admin interface
 * - Cleaned up 15+ test and debug files from codebase
 * - Enhanced database activation/deactivation hooks for proper table management
 *
 * Version 4.4.6 - (Version skipped for consistency)
 *
 * Version 4.4.5 - Multiple City Boundaries Display Fix
 * - Fixed issue where only the first selected city boundary was displayed on the map
 * - Modified map-city-boundaries.js to support multiple simultaneous city boundaries
 * - Changed from tracking single currentCity to currentCities Set for multiple cities
 * - Added updateCityBoundaries method to handle adding/removing individual city boundaries
 * - Created unique IDs for each city boundary in both Google Maps and Mapbox
 * - Added addCityBoundary and removeCityBoundary methods for individual city management
 * - Maintained backward compatibility with existing code
 *
 * Version 4.4.4 - MLDLogger Admin Integration Fix
 * - Fixed "MLDLogger is not defined" error in admin pages
 * - Added MLDLogger script enqueue to saved-search-admin page
 * - Added MLDLogger script enqueue to client-management-admin page
 * - Added MLDLogger script enqueue to email-template-admin page
 * - Ensured MLDLogger loads before dependent admin scripts
 *
 * Version 4.4.3 - JavaScript Error Fix for Saved Searches
 * - Fixed "Cannot read properties of undefined (reading 'forEach')" error on map display
 * - Corrected data structure returned by ajax_get_saved_properties to match expected format
 * - Added defensive checks in updatePropertyCards to prevent undefined errors
 * - Fixed both logged-in and non-logged-in AJAX responses to return consistent structure
 *
 * Version 4.4.2 - City Boundaries Table Structure Fix
 * - Fixed city boundaries not saving due to missing columns on some installations
 * - Added automatic table structure repair for missing boundary_type and display_name columns
 * - Added "Fix City Boundaries Table" button in Database Repair tool
 * - Fixed unique key constraint to properly include boundary_type column
 * - Handles migration from old city_state index to new city_state_type index
 *
 * Version 4.4.1 - Database Table Creation Fix
 * - Fixed critical issue where database tables weren't created on new installations
 * - Created centralized MLD_Activator class to handle all table creation during activation
 * - Fixed SQL syntax error in schools table creation (trailing comma issue)
 * - Added boundary_type and display_name columns to city_boundaries table
 * - Fixed city boundaries caching to properly save fetched data
 * - Added error logging for boundary caching operations
 * - Added Database Repair Tool in admin menu for manual table creation/repair
 * - Ensures all 12 required database tables are created properly on activation
 *
 * Version 4.4.0 - Comprehensive Code Cleanup and Security Enhancements
 * - Removed all development and test files (15+ files) and their references
 * - Replaced 149 console statements with proper MLDLogger usage
 * - Standardized error handling (replaced die() with wp_die())
 * - Enhanced security with proper nonce verification and input sanitization
 * - Optimized database queries and removed unused code paths
 * - Fixed file permissions and ownership issues
 * - Cleaned up CSS unused styles and improved code organization
 * - Removed orphaned functionality and dead code branches
 * - Prepared codebase for next development phase with clean architecture
 *
 * Version 4.3.1 - Code Cleanup and Feature Removal
 * - Completely removed deprecated school district filtering functionality
 * - Cleaned up frontend filtering UI and backend query logic
 * - Removed unused database tables and AJAX handlers
 * - Streamlined admin interface by removing district import options
 * - Improved codebase maintainability and reduced complexity
 *
 * Version 4.3.0 - Performance Optimization and Search Enhancement
 * - Implemented comprehensive caching system with WordPress transients API
 * - Added MLD_Query_Cache class for intelligent query result caching
 * - Created performance monitoring dashboard (MLD_Performance_Monitor)
 * - Added database optimizer with automatic index management
 * - Fixed MySQL spatial index usage for city + bounds queries (FORCE INDEX)
 * - Removed PHP spatial filtering workaround - now uses native MySQL spatial queries
 * - Added lazy loading for property images with Intersection Observer
 * - Implemented modern build tools (Webpack, npm, Babel)
 * - Added comprehensive performance testing tools
 * - Fixed zoom 14-15 issue with multiple cities selected
 *
 * Version 4.2.0 - Mobile Gallery and Virtual Tour Improvements
 * - Added YouTube video integration in mobile gallery
 * - Implemented fullscreen video modal with enhanced close button
 * - Support for multiple virtual tours (Matterport 3D + YouTube videos)
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants.
// Add timestamp for cache busting during development
define('MLD_VERSION', '6.75.0');

define( 'MLD_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MLD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); // Alias for MLD_PLUGIN_PATH for backward compatibility
define( 'MLD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MLD_PLUGIN_FILE', __FILE__ );

// Bootstrap new PSR-4 architecture
require_once MLD_PLUGIN_PATH . 'src/autoload.php';

/**
 * Add rewrite rules for Apple App Site Association (AASA) file
 *
 * This enables iOS Universal Links so that property URLs (https://bmnboston.com/property/123/)
 * automatically open in the iOS app when clicked from email, text messages, etc.
 *
 * @since 6.68.18
 */
function mld_add_aasa_rewrite_rules() {
    add_rewrite_rule(
        '^\.well-known/apple-app-site-association$',
        'index.php?mld_aasa=1',
        'top'
    );
    add_rewrite_rule(
        '^apple-app-site-association$',
        'index.php?mld_aasa=1',
        'top'
    );
}
add_action('init', 'mld_add_aasa_rewrite_rules', 1);

/**
 * Add query var for AASA
 */
function mld_add_aasa_query_var($vars) {
    $vars[] = 'mld_aasa';
    return $vars;
}
add_filter('query_vars', 'mld_add_aasa_query_var');

/**
 * Handle AASA request
 */
function mld_handle_aasa_request() {
    if (get_query_var('mld_aasa')) {
        mld_output_aasa_content();
        exit;
    }
}
add_action('template_redirect', 'mld_handle_aasa_request', 1);

/**
 * Fallback: Check REQUEST_URI directly for AASA requests
 * This handles cases where rewrite rules haven't been flushed yet
 */
function mld_serve_apple_app_site_association() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    // Remove query string if present
    $request_path = strtok($request_uri, '?');

    // Check for both possible AASA file locations
    if ($request_path === '/.well-known/apple-app-site-association' ||
        $request_path === '/apple-app-site-association') {
        mld_output_aasa_content();
        exit;
    }
}
// Hook into template_redirect for 404 handling
add_action('template_redirect', 'mld_serve_apple_app_site_association', 0);

/**
 * Output AASA JSON content
 */
function mld_output_aasa_content() {
    // Team ID from Apple Developer account (TH87BB2YU9)
    // Bundle ID: com.bmnboston.app
    $aasa = array(
        'applinks' => array(
            'apps' => array(),  // Empty array required by Apple
            'details' => array(
                array(
                    'appID' => 'TH87BB2YU9.com.bmnboston.app',
                    'paths' => array(
                        '/property/*',     // Property detail pages
                        '/properties/*',   // Property listing pages
                        '/listing/*',      // Alternative listing URLs
                    ),
                ),
            ),
        ),
        'webcredentials' => array(
            'apps' => array('TH87BB2YU9.com.bmnboston.app'),
        ),
    );

    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Send proper headers
    status_header(200);
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
    header('Access-Control-Allow-Origin: *');

    echo json_encode($aasa, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Flush rewrite rules on plugin activation or version change
 */
function mld_maybe_flush_aasa_rewrite_rules() {
    $version = get_option('mld_aasa_version', '0');
    if (version_compare($version, '6.68.18', '<')) {
        flush_rewrite_rules();
        update_option('mld_aasa_version', '6.68.18');
    }
}
add_action('init', 'mld_maybe_flush_aasa_rewrite_rules', 999);

/**
 * Initialize Instant Notifications System
 *
 * Must be loaded on every request so Bridge Extractor hooks are caught.
 * The system listens to bme_listing_imported, bme_listing_updated, etc.
 *
 * @since 6.66.3
 */
function mld_init_instant_notifications() {
    $init_path = MLD_PLUGIN_PATH . 'includes/instant-notifications/class-mld-instant-notifications-init.php';
    if (file_exists($init_path)) {
        require_once $init_path;
        if (class_exists('MLD_Instant_Notifications_Init')) {
            MLD_Instant_Notifications_Init::get_instance();
        }
    }
}
add_action('plugins_loaded', 'mld_init_instant_notifications', 15);

/**
 * Enqueue Design System CSS globally (priority 1 - loads first)
 * This provides CSS custom properties for the entire MLS Listings Display plugin.
 *
 * @since 6.11.13
 */
function mld_enqueue_design_system() {
    // Enqueue on frontend
    wp_enqueue_style(
        'mld-design-tokens',
        MLD_PLUGIN_URL . 'assets/css/design-system/mld-design-tokens.css',
        array(),
        MLD_VERSION
    );
}
add_action('wp_enqueue_scripts', 'mld_enqueue_design_system', 1);

/**
 * Add diagnostic console logging for debugging summary table issues
 *
 * Outputs inline JavaScript that logs diagnostic information from AJAX responses.
 * This helps identify when the summary table becomes empty or out of sync.
 *
 * @since 6.13.20
 */
function mld_add_diagnostic_logging() {
    // Only add on frontend pages that might have the map
    if (is_admin()) {
        return;
    }
    ?>
    <script type="text/javascript">
    (function() {
        // MLD Diagnostic Logger v6.13.20
        // Set enabled: true to debug summary table issues
        var MLD_DIAG = {
            version: '6.13.20',
            enabled: false,
            lastCheck: null,
            history: [],

            log: function(data, type) {
                if (!this.enabled) return;

                var prefix = '[MLD Diagnostics] ';
                var timestamp = new Date().toISOString();

                if (type === 'error') {
                    console.error(prefix + timestamp, data);
                } else if (type === 'warn') {
                    console.warn(prefix + timestamp, data);
                } else {
                    console.log(prefix + timestamp, data);
                }

                // Keep history for debugging
                this.history.push({time: timestamp, type: type || 'info', data: data});
                if (this.history.length > 50) this.history.shift();
            },

            processDiagnostics: function(diagnostics) {
                if (!diagnostics) return;

                this.lastCheck = diagnostics;

                // Log summary
                var status = '';
                if (diagnostics.table_empty) {
                    status = 'CRITICAL: Summary table is EMPTY!';
                    this.log({
                        status: status,
                        summary_count: diagnostics.summary_count,
                        active_count: diagnostics.active_count,
                        server_time: diagnostics.server_time,
                        is_kinsta: diagnostics.is_kinsta,
                        stored_proc_exists: diagnostics.stored_proc_exists
                    }, 'error');
                } else if (diagnostics.out_of_sync) {
                    status = 'WARNING: Summary table out of sync (' + diagnostics.sync_percent + '%)';
                    this.log({
                        status: status,
                        summary_active: diagnostics.summary_active,
                        active_count: diagnostics.active_count,
                        sync_percent: diagnostics.sync_percent,
                        sync_diff: diagnostics.sync_diff
                    }, 'warn');
                } else {
                    status = 'OK: Summary table in sync (' + diagnostics.sync_percent + '%)';
                    this.log({
                        status: status,
                        listings_returned: diagnostics.listings_returned,
                        summary_active: diagnostics.summary_active
                    });
                }
            },

            getStatus: function() {
                return {
                    version: this.version,
                    lastCheck: this.lastCheck,
                    historyCount: this.history.length
                };
            }
        };

        // Expose globally for debugging
        window.MLD_DIAG = MLD_DIAG;

        // Intercept jQuery AJAX to capture diagnostics
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ajaxComplete(function(event, xhr, settings) {
                try {
                    // Only process our AJAX calls
                    if (settings.url && settings.url.indexOf('admin-ajax.php') !== -1) {
                        var response = xhr.responseJSON;
                        if (response && response.success && response.data && response.data._diagnostics) {
                            MLD_DIAG.processDiagnostics(response.data._diagnostics);
                        }
                    }
                } catch(e) {
                    // Silently fail
                }
            });
        }

        // Log initialization
        MLD_DIAG.log('Diagnostic logger initialized. Type MLD_DIAG.getStatus() in console for current status.');
    })();
    </script>
    <?php
}
add_action('wp_footer', 'mld_add_diagnostic_logging', 999);

/**
 * Enqueue Design System CSS in admin area
 *
 * @since 6.11.13
 */
function mld_enqueue_admin_design_system() {
    wp_enqueue_style(
        'mld-design-tokens',
        MLD_PLUGIN_URL . 'assets/css/design-system/mld-design-tokens.css',
        array(),
        MLD_VERSION
    );
}
add_action('admin_enqueue_scripts', 'mld_enqueue_admin_design_system', 1);

// Include the files needed for activation/deactivation hooks.
// This must be done here because activation hooks run before 'plugins_loaded'.
require_once MLD_PLUGIN_PATH . 'includes/class-mld-activator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-deactivator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-rewrites.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-form-submissions.php';

// Load email utilities (centralized email from address and footer) - v6.63.0
require_once MLD_PLUGIN_PATH . 'includes/class-mld-email-utilities.php';

// Load email validator (bot registration prevention) - v6.73.1
require_once MLD_PLUGIN_PATH . 'includes/class-mld-email-validator.php';
MLD_Email_Validator::init(); // Hook into WordPress registration

// Load logger (required by data provider factory)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-logger.php';

// Load data provider system (required for BME integration)
require_once MLD_PLUGIN_PATH . 'includes/interface-mld-data-provider.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-data-provider-factory.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-bme-data-provider.php';

// Load upgrade system classes
require_once MLD_PLUGIN_PATH . 'includes/class-mld-database-migrator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-upgrader.php';

// Note: Legacy notification formatter removed - using simple notification system
require_once MLD_PLUGIN_PATH . 'includes/class-mld-image-optimizer.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-asset-optimizer.php';

// Load Phase 3 optimization classes
require_once MLD_PLUGIN_PATH . 'includes/class-mld-error-handler.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-query-optimizer.php';

// Note: Legacy BuddyBoss notification formatter removed - using simple notification system

// Load Neighborhood Analytics Feature (v5.2.0+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-neighborhood-analytics.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-activator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-shortcodes.php';

// Load Market Trends Analytics (v5.3.0+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-market-trends.php';

// Load Extended Analytics Engine (v6.12.0+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-extended-analytics.php';

// Load Property Page Analytics (v6.12.8+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-tabs.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-analytics-rest-api.php';

// Load Client Analytics Cron Jobs (v6.37.0+ - Sprint 5 analytics aggregation)
require_once MLD_PLUGIN_PATH . 'includes/analytics/class-mld-analytics-cron.php';
MLD_Analytics_Cron::init();

// Load Client Analytics Hooks (v6.41.0+ - Data integrity and real-time score updates)
require_once MLD_PLUGIN_PATH . 'includes/analytics/class-mld-client-analytics-hooks.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/class-mld-data-cleanup.php';
MLD_Client_Analytics_Hooks::init();
MLD_Data_Cleanup::register_admin_action();

// Load Public Site Analytics System (v6.39.0+ - Cross-platform visitor tracking)
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-public-analytics-database.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-device-detector.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-geolocation-service.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-public-analytics-tracker.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-public-analytics-rest-api.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/public/class-mld-public-analytics-aggregator.php';
require_once MLD_PLUGIN_PATH . 'includes/analytics/admin/class-mld-analytics-admin-dashboard.php';

// Initialize Public Analytics Tracker (enqueues JS for all visitors)
MLD_Public_Analytics_Tracker::init();
MLD_Public_Analytics_REST_API::init();
MLD_Public_Analytics_Aggregator::init();
MLD_Analytics_Admin_Dashboard::init();

// Load Recently Viewed Tracker (v6.57.0+ - tracks property views from web and iOS)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-recently-viewed-tracker.php';
MLD_Recently_Viewed_Tracker::init();

// Load App Store Banner (v6.61.0+ - shows iOS app promotion banner to mobile Safari users)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-app-store-banner.php';
MLD_App_Store_Banner::get_instance();

// Load BMN Schools Integration (v6.28.0+ - must load before Mobile REST API for school filters)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-bmn-schools-integration.php';

// Load Shared Query Builder (v6.30.20+ - unifies iOS REST API and Web AJAX filters)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-shared-query-builder.php';

// Load User Type Manager (v6.32.0+ - agent/client/admin user type system)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-user-type-manager.php';
MLD_User_Type_Manager::init();

// Load Saved Search Collaboration (v6.32.0+ - agent-client collaboration for saved searches)
require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-saved-search-collaboration.php';

// Load Email Template Engine and Digest Processor (v6.32.0+ - Phase 3 email system overhaul)
require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-email-template-engine.php';
require_once MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-digest-processor.php';

// Load Agent Referral System (v6.52.0+ - Agent referral links and auto-assignment)
require_once MLD_PLUGIN_PATH . 'includes/referrals/class-mld-referral-manager.php';

// Load Referral Signup Page (v6.52.0+ - Dedicated signup page for referral links)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-referral-signup.php';

// Load Shared JWT Handler (v6.58.4+ - Shared with SNAB and other plugins)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-jwt-handler.php';

// Load Mobile REST API (v6.27.4+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-mobile-rest-api.php';

// Load Open House REST API (v6.69.0+ - Agent open house sign-in system)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-open-house-rest-api.php';
MLD_Open_House_REST_API::init();

// Load Client Dashboard (v6.32.1+ - Phase 4 client web dashboard)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-client-dashboard.php';
MLD_Client_Dashboard::init();

// Load Comparable Sales Engine (v5.3.0+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-comparable-sales.php';
require_once MLD_PLUGIN_PATH . "includes/class-mld-single-comparable-ajax.php";
            require_once plugin_dir_path(__FILE__) . 'includes/class-mld-cma-confidence-calculator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-comparable-ajax.php';
require_once MLD_PLUGIN_PATH . 'includes/mld-comparable-sales-display.php';

// Load CMA Intelligence System (v5.2.0+)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-market-data-calculator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-market-forecasting.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-market-narrative.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-pdf-generator.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-email.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-ajax.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-session-database.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-sessions.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-standalone-cma-pages.php';
require_once MLD_PLUGIN_PATH . 'includes/class-mld-market-conditions.php'; // v6.18.0
require_once MLD_PLUGIN_PATH . 'includes/class-mld-cma-history.php'; // v6.20.0
require_once MLD_PLUGIN_PATH . 'includes/class-mld-property-data-ajax.php';

// Load SEO and Sitemap Generation (v5.3.0+)
require_once MLD_PLUGIN_PATH . "includes/class-mld-sitemap-generator.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-city-pages.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-state-pages.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-property-type-pages.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-sitemap-admin.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-incremental-sitemaps.php";
require_once MLD_PLUGIN_PATH . "includes/class-mld-indexnow.php";

// Load admin interfaces (classes will be instantiated in MLD_Main::init_classes())
if (is_admin()) {
    // Load main admin class first (creates parent menu)
    require_once MLD_PLUGIN_PATH . 'includes/class-mld-admin.php';

    // Load additional admin pages
    require_once MLD_PLUGIN_PATH . 'admin/mld-analytics-admin.php';
    require_once MLD_PLUGIN_PATH . 'includes/admin/class-mld-cma-admin.php';
    require_once MLD_PLUGIN_PATH . 'includes/admin/class-mld-cma-sessions-admin.php';

    // Load AI Chatbot settings page
    require_once MLD_PLUGIN_PATH . 'admin/chatbot/class-mld-chatbot-settings.php';

    // Load AI Chatbot training page (v6.8.0)
    require_once MLD_PLUGIN_PATH . 'admin/chatbot/class-mld-chatbot-training.php';

    // Load System Health Dashboard (v6.10.7)
    require_once MLD_PLUGIN_PATH . 'admin/class-mld-health-dashboard.php';

    // Load Health Monitoring System (v6.58.0)
    require_once MLD_PLUGIN_PATH . 'includes/health/class-mld-health-monitor.php';
    require_once MLD_PLUGIN_PATH . 'includes/health/class-mld-health-alerts.php';

    // Load Recently Viewed Properties Admin (v6.57.0)
    require_once MLD_PLUGIN_PATH . 'admin/class-mld-recently-viewed-admin.php';

    // Load Summary Sync Diagnostic & Self-Healing (v6.11.11)
    require_once MLD_PLUGIN_PATH . 'includes/class-mld-summary-sync-diagnostic.php';

    // Load Push Notification Settings (v6.31.0)
    require_once MLD_PLUGIN_PATH . 'includes/admin/class-mld-push-settings.php';

    // Load v6.32.0 Update Script (User Type System migration)
    if (file_exists(MLD_PLUGIN_PATH . 'updates/update-6.32.0.php')) {
        require_once MLD_PLUGIN_PATH . 'updates/update-6.32.0.php';
    }
}

// Load Agent Activity Notification System (v6.43.0) - Must be outside is_admin() for REST API
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-agent-notification-preferences.php';
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-agent-notification-log.php';
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-agent-notification-email.php';
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-agent-activity-notifier.php';
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-push-notifications.php';

// Load Property Change Detection System (v6.48.0) - Price/status change alerts for favorited properties
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-property-change-detector.php';
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-property-change-notifier.php';

// Load Open House Notification System (v6.48.0) - Open house alerts for favorited properties
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-open-house-notifier.php';

// Load Client Notification Preferences (v6.48.0) - Per-user notification settings with quiet hours
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-client-notification-preferences.php';

// Load Web Notification AJAX Handlers (v6.50.9) - AJAX endpoints for web notification center
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-notification-ajax.php';

// Load Web Notification Frontend (v6.50.9) - Bell icon, dropdown, asset enqueuing
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-notification-frontend.php';

// Load Notification Analytics (v6.48.0) - Track delivery and engagement metrics
require_once MLD_PLUGIN_PATH . 'includes/notifications/class-mld-notification-analytics.php';

// Load Notification Analytics Admin Dashboard (v6.48.0)
require_once MLD_PLUGIN_PATH . 'includes/notifications/admin/class-mld-notification-analytics-dashboard.php';

// Load Universal Contact Form System (v6.21.0, enhanced in v6.22.0, v6.23.0)
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-manager.php';
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-renderer.php';
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-validator.php';
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-notifications.php';
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-customizer.php';
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-conditional.php'; // v6.22.0
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-multistep.php'; // v6.23.0
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-upload.php'; // v6.24.0
require_once MLD_PLUGIN_PATH . 'includes/contact-forms/class-mld-contact-form-templates.php'; // v6.24.0

// Load SNAB (SN Appointment Booking) integration (v6.26.8)
require_once MLD_PLUGIN_PATH . 'includes/class-mld-snab-integration.php';

// Note: BMN Schools integration is loaded earlier (before Mobile REST API) for school filter support

// Initialize SNAB integration (registers AJAX handlers) - v6.26.8
add_action('init', function() {
    if (function_exists('mld_snab_integration')) {
        mld_snab_integration();
    }
}, 10);

// Initialize Contact Form Upload Handler (v6.24.0)
add_action('init', function() {
    if (class_exists('MLD_Contact_Form_Upload')) {
        MLD_Contact_Form_Upload::init();
    }
    if (class_exists('MLD_Contact_Form_Templates')) {
        MLD_Contact_Form_Templates::init();
    }
}, 15);

// Initialize Contact Form Customizer on theme customizer
add_action('init', function() {
    if (class_exists('MLD_Contact_Form_Customizer')) {
        MLD_Contact_Form_Customizer::get_instance();
    }
}, 20);

// Initialize Agent Activity Notifier (v6.43.0)
add_action('init', function() {
    if (class_exists('MLD_Agent_Activity_Notifier')) {
        MLD_Agent_Activity_Notifier::init();
    }
}, 25);

// Initialize Web Notification AJAX Handlers (v6.50.9)
add_action('init', function() {
    if (class_exists('MLD_Notification_Ajax')) {
        MLD_Notification_Ajax::init();
    }
}, 25);

// Initialize Web Notification Frontend (v6.50.9)
add_action('init', function() {
    if (class_exists('MLD_Notification_Frontend')) {
        MLD_Notification_Frontend::init();
    }
}, 25);

// Initialize Property Change Detection System (v6.48.0)
add_action('init', function() {
    if (class_exists('MLD_Property_Change_Detector')) {
        MLD_Property_Change_Detector::init();
    }
    if (class_exists('MLD_Property_Change_Notifier')) {
        MLD_Property_Change_Notifier::init();
    }
}, 26);

// Initialize Open House Notification System (v6.48.0)
add_action('init', function() {
    if (class_exists('MLD_Open_House_Notifier')) {
        MLD_Open_House_Notifier::init();
    }
}, 27);

// Initialize Client Notification Preferences (v6.48.0) - Create table on first use
add_action('init', function() {
    if (class_exists('MLD_Client_Notification_Preferences')) {
        MLD_Client_Notification_Preferences::maybe_create_table();
    }
}, 28);

// Initialize Notification Analytics (v6.48.0) - Track delivery and engagement
add_action('init', function() {
    if (class_exists('MLD_Notification_Analytics')) {
        MLD_Notification_Analytics::init();
    }
}, 29);

// Initialize Push Notification Retry Queue Cron (v6.48.6)
add_action('init', function() {
    // Add custom cron intervals if not exists
    add_filter('cron_schedules', function($schedules) {
        if (!isset($schedules['mld_every_five_minutes'])) {
            $schedules['mld_every_five_minutes'] = array(
                'interval' => 300,
                'display' => __('Every 5 Minutes (MLD)', 'mls-listings-display'),
            );
        }
        if (!isset($schedules['mld_fifteen_minutes'])) {
            $schedules['mld_fifteen_minutes'] = array(
                'interval' => 900,
                'display' => __('Every 15 Minutes (MLD)', 'mls-listings-display'),
            );
        }
        return $schedules;
    });

    // Schedule retry queue processing every 5 minutes
    if (!wp_next_scheduled('mld_process_push_retry_queue')) {
        wp_schedule_event(time(), 'mld_every_five_minutes', 'mld_process_push_retry_queue');
    }

    // Schedule daily cleanup
    if (!wp_next_scheduled('mld_cleanup_push_retry_queue')) {
        wp_schedule_event(strtotime('tomorrow 4:00am'), 'daily', 'mld_cleanup_push_retry_queue');
    }

    // Schedule log cleanup daily at 4:30am
    if (!wp_next_scheduled('mld_cleanup_push_notification_logs')) {
        wp_schedule_event(strtotime('tomorrow 4:30am'), 'daily', 'mld_cleanup_push_notification_logs');
    }

    // Schedule stale device token cleanup daily at 5:00am (v6.50.7)
    if (!wp_next_scheduled('mld_cleanup_stale_device_tokens')) {
        wp_schedule_event(strtotime('tomorrow 5:00am'), 'daily', 'mld_cleanup_stale_device_tokens');
    }

    // Schedule deferred notifications processing every 15 minutes (v6.50.7)
    // Processes notifications that were queued during quiet hours
    if (!wp_next_scheduled('mld_process_deferred_notifications')) {
        wp_schedule_event(time(), 'fifteen_min', 'mld_process_deferred_notifications');
    }

    // Schedule deferred notifications cleanup daily at 4:45am (v6.50.7)
    if (!wp_next_scheduled('mld_cleanup_deferred_notifications')) {
        wp_schedule_event(strtotime('tomorrow 4:45am'), 'daily', 'mld_cleanup_deferred_notifications');
    }

    // Schedule recently viewed properties cleanup daily at 5:15am (v6.57.0)
    // Removes view records older than 7 days
    if (!wp_next_scheduled('mld_cleanup_recently_viewed')) {
        wp_schedule_event(strtotime('tomorrow 5:15am'), 'daily', 'mld_cleanup_recently_viewed');
    }
}, 30);

// Push Retry Queue Processing Action (v6.48.6)
add_action('mld_process_push_retry_queue', function() {
    if (class_exists('MLD_Push_Notifications')) {
        // Expire stale items first
        MLD_Push_Notifications::expire_stale_retries();
        // Process the queue
        $result = MLD_Push_Notifications::process_retry_queue(50);
        if (defined('WP_DEBUG') && WP_DEBUG && $result['processed'] > 0) {
            error_log("MLD Push Retry Queue: Processed {$result['processed']} items - {$result['succeeded']} succeeded, {$result['requeued']} requeued, {$result['failed']} failed");
        }
    }
});

// Push Retry Queue Cleanup Action (v6.48.6)
add_action('mld_cleanup_push_retry_queue', function() {
    if (class_exists('MLD_Push_Notifications')) {
        $deleted = MLD_Push_Notifications::cleanup_retry_queue();
        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("MLD Push Retry Queue: Cleaned up {$deleted} old entries");
        }
    }
});

// Push Notification Log Cleanup Action (v6.48.6)
add_action('mld_cleanup_push_notification_logs', function() {
    if (class_exists('MLD_Push_Notifications')) {
        $deleted = MLD_Push_Notifications::cleanup_old_logs();
        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("MLD Push Notification Logs: Cleaned up {$deleted} old entries");
        }
    }
});

// Stale Device Token Cleanup Action (v6.50.7)
// Deactivates tokens not used in 90+ days, deletes inactive tokens older than 180 days
add_action('mld_cleanup_stale_device_tokens', function() {
    if (class_exists('MLD_Push_Notifications')) {
        $result = MLD_Push_Notifications::cleanup_stale_tokens(90);
        if (defined('WP_DEBUG') && WP_DEBUG && ($result['deactivated'] > 0 || $result['deleted'] > 0)) {
            error_log("MLD Stale Token Cleanup: Deactivated {$result['deactivated']}, Deleted {$result['deleted']}");
        }
    }
});

// Deferred Notifications Processing Action (v6.50.7)
// Processes notifications that were queued during quiet hours
add_action('mld_process_deferred_notifications', function() {
    if (class_exists('MLD_Push_Notifications')) {
        $result = MLD_Push_Notifications::process_deferred_notifications(50);
        if (defined('WP_DEBUG') && WP_DEBUG && $result['processed'] > 0) {
            error_log("MLD Deferred Notifications: Processed {$result['processed']} - Sent {$result['sent']}, Failed {$result['failed']}, Skipped {$result['skipped']}");
        }
    }
});

// Deferred Notifications Cleanup Action (v6.50.7)
// Cleans up old processed deferred notifications (older than 7 days)
add_action('mld_cleanup_deferred_notifications', function() {
    if (class_exists('MLD_Client_Notification_Preferences')) {
        $deleted = MLD_Client_Notification_Preferences::cleanup_deferred_notifications();
        if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
            error_log("MLD Deferred Notifications Cleanup: Deleted {$deleted} old records");
        }
    }
});

// Recently Viewed Properties Cleanup Action (v6.57.0)
// Removes view records older than 7 days to keep the table lean
add_action('mld_cleanup_recently_viewed', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'mld_recently_viewed_properties';

    // Use current_time() for WordPress timezone (Rule 13)
    $cutoff_date = wp_date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));

    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE viewed_at < %s",
        $cutoff_date
    ));

    if (defined('WP_DEBUG') && WP_DEBUG && $deleted > 0) {
        error_log("MLD Recently Viewed Cleanup: Deleted {$deleted} old records");
    }
});

// Initialize Notification Analytics Admin Dashboard (v6.48.0)
if (class_exists('MLD_Notification_Analytics_Dashboard')) {
    MLD_Notification_Analytics_Dashboard::init();
}

// Load AJAX handlers unconditionally (WordPress will only fire them during AJAX requests)
require_once MLD_PLUGIN_PATH . 'includes/admin/class-mld-cma-admin-ajax.php';
// Load enhanced chatbot AJAX handlers (v6.7.0)
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-ajax-enhanced.php';

// Load chatbot session manager
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-session-manager.php';

// Load admin notification manager
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-admin-notifier.php';

// Load user summary generator
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-summary-generator.php';

// Load FAQ manager
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-faq-manager.php';

// Load knowledge base scanner
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-knowledge-scanner.php';

// Load chatbot AI provider base classes
require_once MLD_PLUGIN_PATH . 'includes/chatbot/interface-mld-ai-provider.php';
require_once MLD_PLUGIN_PATH . 'includes/chatbot/abstract-mld-ai-provider.php';

// Load chatbot cron manager
require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-cron.php';

/**
 * Configure PHPMailer for chatbot emails
 * Only uses MailHog SMTP in development environment
 * Version 2: More reliable production detection
 */
function mld_configure_phpmailer($phpmailer) {
    // Get current hostname
    $hostname = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
    
    // Detect if we're in development environment
    // ONLY use MailHog if ALL of these are true:
    // 1. NOT a real domain (has .local or localhost)
    // 2. Docker environment file exists
    $is_development = (
        (strpos($hostname, '.local') !== false ||
         strpos($hostname, 'localhost') !== false) &&
        file_exists('/.dockerenv')
    );

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Email Debug] Hostname: ' . $hostname);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Email Debug] Is Development: ' . ($is_development ? 'YES' : 'NO'));
        }
    }

    if ($is_development) {
        // Development: Use MailHog for email testing
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD] Development environment - using MailHog SMTP');
        }
        $phpmailer->isSMTP();
        $phpmailer->Host = 'mailhog';
        $phpmailer->Port = 1025;
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPDebug = 0;
        $phpmailer->From = 'chatbot@mlslistings.local';
        $phpmailer->FromName = 'MLS Chatbot (Dev)';
        $phpmailer->Sender = 'chatbot@mlslistings.local';
    } else {
        // Production: Use WordPress default email configuration
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD] Production environment - using WordPress default email');
        }

        // Only set FROM if not already set by WordPress/host
        if (empty($phpmailer->From)) {
            $admin_email = get_option('admin_email');
            $site_name = get_option('blogname');
            $phpmailer->From = $admin_email;
            $phpmailer->FromName = $site_name . ' - Chatbot';
            $phpmailer->Sender = $admin_email;
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD] Email FROM: ' . $phpmailer->From . ' (' . $phpmailer->FromName . ')');
    }
}
add_action('phpmailer_init', 'mld_configure_phpmailer', 10, 1);

/**
 * Initialize enhanced chatbot system components (v6.7.0)
 */
function mld_init_chatbot_system() {
    // Load the enhanced chatbot initialization system
    $init_file = MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-init.php';

    if (file_exists($init_file)) {
        require_once $init_file;

        // Initialize the enhanced system
        // The singleton pattern in the file will auto-initialize
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD v6.7.0] Enhanced chatbot system loaded successfully');
        }
    } else {
        // Fallback to loading individual components if init file missing
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD v6.7.0] Loading chatbot components individually');
        }

        // Load new enhanced components
        $components = array(
            'class-mld-data-reference-mapper.php',
            'class-mld-unified-data-provider.php',
            'class-mld-conversation-state.php',
            'class-mld-response-engine.php',
            'class-mld-agent-handoff.php'
        );

        foreach ($components as $component) {
            $file = MLD_PLUGIN_PATH . 'includes/chatbot/' . $component;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}
add_action('init', 'mld_init_chatbot_system');

/**
 * Enqueue chatbot widget on frontend
 */
function mld_enqueue_chatbot_widget() {
    // Check if chatbot is enabled
    global $wpdb;
    $enabled = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
        'chatbot_enabled'
    ));

    if ($enabled !== '1') {
        return;
    }

    // Enqueue chatbot widget styles (source file with lead gate - v6.27.0)
    wp_enqueue_style(
        'mld-chatbot-widget',
        MLD_PLUGIN_URL . 'assets/js/chatbot-widget.css',
        array(),
        MLD_VERSION
    );

    // Enqueue chatbot widget script (source file with lead gate - v6.27.0)
    wp_enqueue_script(
        'mld-chatbot-widget',
        MLD_PLUGIN_URL . 'assets/js/chatbot-widget.js',
        array(),
        MLD_VERSION,
        true
    );

    // Enqueue session manager (enhances widget with persistence)
    wp_enqueue_script(
        'mld-chatbot-session-manager',
        MLD_PLUGIN_URL . 'assets/js/chatbot-session-manager.js',
        array('mld-chatbot-widget'),
        MLD_VERSION,
        true
    );

    // Get greeting from database
    global $wpdb;
    $greeting = $wpdb->get_var(
        "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = 'chatbot_greeting' LIMIT 1"
    );
    if (empty($greeting)) {
        $greeting = 'Hello! 👋 I\'m your AI property assistant. How can I help you today?';
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD Chatbot: Retrieved greeting = ' . $greeting);
    }

    // Get current user data for lead gate (v6.27.0)
    $current_user = wp_get_current_user();
    $user_phone = '';
    if ($current_user->ID > 0) {
        // Try multiple meta keys for phone
        $user_phone = get_user_meta($current_user->ID, 'phone', true);
        if (empty($user_phone)) {
            $user_phone = get_user_meta($current_user->ID, 'billing_phone', true);
        }
        if (empty($user_phone)) {
            $user_phone = get_user_meta($current_user->ID, 'user_phone', true);
        }
    }

    // Check if lead gate is enabled
    $lead_gate_enabled = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$wpdb->prefix}mld_chat_settings WHERE setting_key = %s",
        'lead_gate_enabled'
    ));
    // Default to enabled if setting doesn't exist
    $lead_gate_enabled = ($lead_gate_enabled !== '0');

    // Pass configuration to JavaScript
    wp_localize_script('mld-chatbot-widget', 'mldChatbot', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mld_chatbot_nonce'),
        'siteUrl' => home_url(),
        'pluginUrl' => MLD_PLUGIN_URL,
        'greeting' => $greeting,
        'user' => array(
            'id' => $current_user->ID,
            'name' => $current_user->display_name,
            'email' => $current_user->user_email,
            'phone' => $user_phone
        ),
        'lead_gate' => array(
            'enabled' => $lead_gate_enabled
        )
    ));
}
add_action('wp_enqueue_scripts', 'mld_enqueue_chatbot_widget');

/**
 * Enqueue mobile fullscreen handler for app-like experience
 *
 * Loads fullscreen assets on mobile property details and search pages
 * to create an immersive app-like experience by hiding browser chrome.
 *
 * @since 6.11.17
 */
function mld_enqueue_mobile_fullscreen() {
    // Only on mobile devices
    if (!wp_is_mobile()) {
        return;
    }

    // Check if we're on a property page
    $is_property_page = false;
    if (is_singular()) {
        global $post;
        // Check for MLS number in URL or post meta
        $mls_number = get_query_var('mls_number');
        if (!$mls_number && $post) {
            $mls_number = get_post_meta($post->ID, '_mls_number', true);
        }
        $is_property_page = !empty($mls_number);
    }

    // Check if we're on a search page (half-map or full-map)
    $is_search_page = false;
    $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : '';
    if (in_array($view, ['half-map', 'full-map'], true)) {
        $is_search_page = true;
    }

    // Only load on property or search pages
    if (!$is_property_page && !$is_search_page) {
        return;
    }

    wp_enqueue_style(
        'mld-mobile-fullscreen',
        MLD_PLUGIN_URL . 'assets/css/mobile-fullscreen.css',
        array(),
        MLD_VERSION
    );

    wp_enqueue_script(
        'mld-mobile-fullscreen',
        MLD_PLUGIN_URL . 'assets/js/mobile-fullscreen-handler.js',
        array(),
        MLD_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'mld_enqueue_mobile_fullscreen', 20);

// Initialize chatbot AJAX handlers
add_action('init', function() {
    $ajax_file = MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-ajax.php';
    if (file_exists($ajax_file)) {
        require_once MLD_PLUGIN_PATH . 'includes/chatbot/class-mld-chatbot-engine.php';
        require_once $ajax_file;
        if (class_exists('MLD_Chatbot_AJAX')) {
            new MLD_Chatbot_AJAX();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Chatbot] AJAX handlers initialized from main plugin file');
            }
        }
    }
}, 5);

// Initialize Property Page Analytics REST API (v6.12.8)
add_action('init', function() {
    if (class_exists('MLD_Analytics_REST_API')) {
        new MLD_Analytics_REST_API();
    }
}, 10);

// Initialize custom password change email (v6.36.8)
add_action('init', function() {
    $client_manager_file = MLD_PLUGIN_PATH . 'includes/saved-searches/class-mld-agent-client-manager.php';
    if (file_exists($client_manager_file)) {
        require_once $client_manager_file;
        if (class_exists('MLD_Agent_Client_Manager')) {
            MLD_Agent_Client_Manager::init_password_email_hooks();
        }
    }
}, 10);

/**
 * Enqueue Property Analytics assets
 *
 * Loads analytics CSS and JS on property detail pages.
 *
 * @since 6.12.8
 */
function mld_enqueue_property_analytics() {
    // Check if we're on a property page
    $is_property_page = false;
    $mls_number = get_query_var('mls_number');
    if ($mls_number) {
        $is_property_page = true;
    } elseif (is_singular()) {
        global $post;
        if ($post) {
            $mls_number = get_post_meta($post->ID, '_mls_number', true);
            $is_property_page = !empty($mls_number);
        }
    }

    if (!$is_property_page) {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'mld-property-analytics',
        MLD_PLUGIN_URL . 'assets/css/property-analytics.css',
        array('mld-design-tokens'),
        MLD_VERSION
    );

    // Enqueue JS (in footer)
    wp_enqueue_script(
        'mld-property-analytics',
        MLD_PLUGIN_URL . 'assets/js/property-analytics.js',
        array(),
        MLD_VERSION,
        true
    );

    // Pass REST API configuration
    wp_localize_script('mld-property-analytics', 'mldPropertyAnalytics', array(
        'restUrl' => rest_url('mld/v1/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'isAdmin' => current_user_can('manage_options'),
    ));
}
add_action('wp_enqueue_scripts', 'mld_enqueue_property_analytics', 15);

/**
 * Enqueue navigation drawer assets on map pages and property pages
 *
 * Loads the CSS and JavaScript for the slide-in navigation drawer
 * that provides site navigation on search pages and property detail pages
 * (which have no header).
 *
 * @since 6.25.0
 * @since 6.25.2 Also loads on property detail pages
 */
function mld_enqueue_nav_drawer() {
    global $post;

    $should_load = false;

    // Check 1: Property detail page (mls_number query var)
    $mls_number = get_query_var('mls_number');
    if ($mls_number) {
        $should_load = true;
    }

    // Check 2: Page with map shortcodes
    if (!$should_load && is_a($post, 'WP_Post')) {
        $has_map = has_shortcode($post->post_content, 'mld_full_map') ||
                   has_shortcode($post->post_content, 'mld_half_map') ||
                   has_shortcode($post->post_content, 'bme_full_map') ||
                   has_shortcode($post->post_content, 'bme_half_map') ||
                   has_shortcode($post->post_content, 'bme_listings_map_view') ||
                   has_shortcode($post->post_content, 'bme_listings_half_map_view');

        if ($has_map) {
            $should_load = true;
        }
    }

    if (!$should_load) {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'mld-nav-drawer',
        MLD_PLUGIN_URL . 'assets/css/mld-nav-drawer.css',
        array(),
        MLD_VERSION
    );

    // Enqueue JS
    wp_enqueue_script(
        'mld-nav-drawer',
        MLD_PLUGIN_URL . 'assets/js/mld-nav-drawer.js',
        array(),
        MLD_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'mld_enqueue_nav_drawer', 20);

/**
 * Enqueue client analytics tracker for logged-in users
 *
 * Sprint 5: Tracks user activity for agent analytics dashboard.
 * Only loads for logged-in users (agents need user_id for tracking).
 *
 * @since 6.37.0
 */
function mld_enqueue_analytics_tracker() {
    // Only track logged-in users
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return;
    }

    // Enqueue the analytics tracker script
    wp_enqueue_script(
        'mld-analytics-tracker',
        MLD_PLUGIN_URL . 'assets/js/mld-analytics-tracker.js',
        array(),
        MLD_VERSION,
        true
    );

    // Localize configuration for the script
    wp_localize_script('mld-analytics-tracker', 'mldAnalyticsConfig', array(
        'userId' => $user_id,
        'apiBase' => rest_url('mld-mobile/v1'),
        'nonce' => wp_create_nonce('wp_rest')
    ));
}
add_action('wp_enqueue_scripts', 'mld_enqueue_analytics_tracker', 25);

/**
 * Fallback menu for nav drawer when no primary menu is assigned
 *
 * Displays a simple menu with Home and common page links
 * when no WordPress menu is assigned to the primary location.
 *
 * @since 6.25.0
 */
function mld_nav_drawer_fallback_menu() {
    echo '<ul class="mld-nav-drawer__menu">';
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'mls-listings-display') . '</a></li>';

    // Add common real estate pages if they exist
    $pages = array('properties', 'search', 'listings', 'about', 'contact');
    foreach ($pages as $slug) {
        $page = get_page_by_path($slug);
        if ($page && $page->post_status === 'publish') {
            echo '<li><a href="' . esc_url(get_permalink($page)) . '">' . esc_html($page->post_title) . '</a></li>';
        }
    }

    echo '</ul>';
}

/**
 * Robust Content Security Policy Handler
 *
 * Ensures plugin works on servers with strict CSP policies (Kinsta, WP Engine, etc.)
 * Automatically handles CSP configuration without manual functions.php editing.
 *
 * @since 5.1.8
 */
class MLD_CSP_Handler {

    /**
     * Initialize CSP handler
     */
    public static function init() {
        // Add CSP headers at absolute highest priority to override server-level restrictions
        // PHP_INT_MAX ensures this runs last and overrides Kinsta/hosting security headers
        add_action('send_headers', [__CLASS__, 'add_csp_headers'], PHP_INT_MAX);

        // Also add as meta tag in wp_head as fallback
        add_action('wp_head', [__CLASS__, 'add_csp_meta_tag'], 1);

        // Register settings
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // Add admin notice if CSP handling is disabled
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    /**
     * Check if CSP handling is enabled
     *
     * @return bool
     */
    public static function is_enabled() {
        return get_option('mld_enable_csp_handling', true);
    }

    /**
     * Add CSP HTTP headers
     *
     * This method aggressively sets CSP headers to ensure Google Maps
     * and all plugin resources load correctly, even on strict CSP servers.
     */
    public static function add_csp_headers() {
        // Skip if disabled
        if (!self::is_enabled()) {
            return;
        }

        // Don't modify admin CSP
        if (is_admin()) {
            return;
        }

        // Remove any existing CSP header that might be too restrictive
        header_remove('Content-Security-Policy');
        header_remove('X-Content-Security-Policy');
        header_remove('X-WebKit-CSP');

        // Build comprehensive CSP that allows all plugin resources
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://maps.googleapis.com https://maps.gstatic.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https: http:",
            "connect-src 'self' https://*.googleapis.com https://maps.googleapis.com https://cdn.jsdelivr.net",
            "frame-src 'self' https://*.google.com https://www.google.com https://*.matterport.com https://my.matterport.com",
            "worker-src 'self' blob:",
            "child-src 'self' blob:"
        ];

        $csp = implode('; ', $csp_directives);

        // Set the CSP header
        header("Content-Security-Policy: {$csp}", true);

        // Also set for older browsers
        header("X-Content-Security-Policy: {$csp}", true);
        header("X-WebKit-CSP: {$csp}", true);

        // Fix Permissions-Policy to allow geolocation for Nearby feature
        // Remove any restrictive permissions-policy that may be set by security plugins
        header_remove('Permissions-Policy');

        // Set policy that allows geolocation for this site
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()', true);
    }

    /**
     * Add CSP meta tag as fallback
     *
     * If HTTP headers don't work (already sent), this provides a meta tag fallback.
     */
    public static function add_csp_meta_tag() {
        // Skip if disabled
        if (!self::is_enabled()) {
            return;
        }

        // Don't add in admin
        if (is_admin()) {
            return;
        }

        // Build the same CSP as headers
        $csp_directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://maps.googleapis.com https://maps.gstatic.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.googleapis.com https://cdn.jsdelivr.net",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https: http:",
            "connect-src 'self' https://*.googleapis.com https://maps.googleapis.com https://cdn.jsdelivr.net",
            "frame-src 'self' https://*.google.com https://www.google.com https://*.matterport.com https://my.matterport.com",
            "worker-src 'self' blob:",
            "child-src 'self' blob:"
        ];

        $csp = implode('; ', $csp_directives);

        // Output meta tag
        echo '<meta http-equiv="Content-Security-Policy" content="' . esc_attr($csp) . '">' . "\n";
        echo '<!-- MLS Listings Display: CSP configured for Google Maps compatibility -->' . "\n";
    }

    /**
     * Register admin settings
     */
    public static function register_settings() {
        register_setting('mld_settings_group', 'mld_enable_csp_handling', [
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);

        // Add setting to Map Settings section if it exists
        add_settings_field(
            'mld_enable_csp_handling',
            'Enable Automatic CSP Handling',
            [__CLASS__, 'render_csp_setting'],
            'mld_settings',
            'mld_map_settings_section'
        );
    }

    /**
     * Render CSP setting checkbox
     */
    public static function render_csp_setting() {
        $enabled = self::is_enabled();
        ?>
        <label>
            <input type="checkbox"
                   name="mld_enable_csp_handling"
                   value="1"
                   <?php checked($enabled, true); ?> />
            Automatically configure Content Security Policy for Google Maps
        </label>
        <p class="description">
            <strong>Recommended: Keep this enabled</strong><br>
            This ensures the map works on servers with strict security policies (Kinsta, WP Engine, etc.).<br>
            Disabling this may cause "CSP violation" errors and prevent maps from loading.<br>
            Only disable if you have custom CSP configuration in place.
        </p>
        <?php
    }

    /**
     * Show admin notice if CSP handling is disabled
     */
    public static function admin_notice() {
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show if disabled
        if (self::is_enabled()) {
            return;
        }

        // Only show on plugin settings or dashboard
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['dashboard', 'settings_page_mld-settings', 'toplevel_page_mld-settings'])) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong>MLS Listings Display:</strong>
                Automatic CSP handling is currently disabled.
                If you experience "Content Security Policy violation" errors or maps not loading,
                <a href="<?php echo admin_url('admin.php?page=mld-settings'); ?>">enable CSP handling</a>
                in plugin settings.
            </p>
        </div>
        <?php
    }
}

// Initialize CSP handler
MLD_CSP_Handler::init();

// Include the main plugin class to run the plugin.
require_once MLD_PLUGIN_PATH . 'includes/class-mld-main.php';

/**
 * Begins execution of the plugin.
 */
if (!function_exists('mld_run_plugin')) {
    function mld_run_plugin() {
        new MLD_Main();
    }
    add_action( 'plugins_loaded', 'mld_run_plugin' );
}

/**
 * The code that runs during plugin activation.
 * Uses the centralized activator class to ensure all tables are created properly.
 */
function mld_activate_plugin() {
    // Include database upgrader
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-mld-database-upgrader.php';

    MLD_Activator::activate();

    // Run database upgrades
    MLD_Database_Upgrader::activate();

    // Run legacy upgrade system for compatibility
    $upgrader = new MLD_Upgrader();
    if ($upgrader->needs_upgrade()) {
        $upgrader->run_upgrade();
    }

    // Create public analytics tables (v6.39.0+)
    if (class_exists('MLD_Public_Analytics_Database')) {
        $public_analytics_db = MLD_Public_Analytics_Database::get_instance();
        $public_analytics_db->create_tables();
    }
}
register_activation_hook( __FILE__, 'mld_activate_plugin' );

/**
 * Flush rewrite rules after all rules have been registered.
 * This runs on 'init' with late priority (999) to ensure MLD_Sitemap_Generator,
 * MLD_State_Pages, MLD_Property_Type_Pages, and all other classes have registered
 * their rewrite rules before we flush.
 */
function mld_maybe_flush_rewrite_rules() {
    if (get_transient('mld_flush_rewrite_rules')) {
        delete_transient('mld_flush_rewrite_rules');
        flush_rewrite_rules();
    }
}
add_action('init', 'mld_maybe_flush_rewrite_rules', 999);

/**
 * The code that runs during plugin deactivation.
 */
function mld_deactivate_plugin() {
    MLD_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'mld_deactivate_plugin' );

/**
 * Check for plugin upgrades on admin_init
 * This ensures upgrades are applied when the plugin is updated via WordPress admin
 */
function mld_check_upgrades() {
    // Only run on admin pages to avoid frontend performance impact
    if (!is_admin()) {
        return;
    }

    // Load performance dashboard
    if (file_exists(MLD_PLUGIN_PATH . "admin/mld-performance-dashboard.php")) {
        require_once MLD_PLUGIN_PATH . "admin/mld-performance-dashboard.php";
    }

    // Run database upgrades
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-mld-database-upgrader.php';
    MLD_Database_Upgrader::upgrade();

    // Use the legacy check_upgrades method for compatibility
    MLD_Upgrader::check_upgrades();

    // Create public analytics tables if they don't exist (v6.39.0+)
    if (class_exists('MLD_Public_Analytics_Database')) {
        $public_analytics_db = MLD_Public_Analytics_Database::get_instance();
        if (!$public_analytics_db->all_tables_exist()) {
            $public_analytics_db->create_tables();
        }
    }

    // Fire upgrade check completed action
    do_action('mld_upgrade_check_completed');
}
add_action('admin_init', 'mld_check_upgrades');

/**
 * Handle plugin update via WordPress upgrader
 * This hook fires after a plugin is updated
 */
function mld_handle_plugin_update($upgrader_object, $options) {
    // Check if this is a plugin update
    if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
        return;
    }

    // Check if our plugin was updated
    $our_plugin = plugin_basename(__FILE__);
    $updated_plugins = isset($options['plugins']) ? $options['plugins'] : array();

    if (!in_array($our_plugin, $updated_plugins)) {
        return;
    }

    // Plugin was updated - set flag for upgrade on next load
    update_option('mld_needs_upgrade', true);

    // Set transient to flush rewrite rules on next page load
    set_transient('mld_flush_rewrite_rules', true, 60);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('MLD: Plugin updated via WordPress upgrader - upgrade flag set');
    }
}
add_action('upgrader_process_complete', 'mld_handle_plugin_update', 10, 2);

/**
 * Check for pending upgrade flag on plugins_loaded
 * This catches updates that happened via FTP or other methods
 */
function mld_check_pending_upgrade() {
    // Check if upgrade is needed
    if (get_option('mld_needs_upgrade')) {
        delete_option('mld_needs_upgrade');

        // Set transient to flush rewrite rules
        set_transient('mld_flush_rewrite_rules', true, 60);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD: Pending upgrade detected - rewrite rules flush scheduled');
        }
    }

    // Also check if plugin version changed
    $stored_version = get_option('mld_plugin_version', '0.0.0');
    if (defined('MLD_VERSION') && version_compare(MLD_VERSION, $stored_version, '>')) {
        // Version mismatch detected - schedule flush
        set_transient('mld_flush_rewrite_rules', true, 60);
    }
}
add_action('plugins_loaded', 'mld_check_pending_upgrade', 1);

/**
 * Global helper function to get property URL
 *
 * @param mixed $listing_or_id Either a listing array or a listing ID string
 * @param bool $use_descriptive Whether to use descriptive URL format (default: true)
 * @return string The property URL
 */
function mld_get_property_url($listing_or_id, $use_descriptive = true) {
    // Ensure URL helper class is loaded
    if (!class_exists('MLD_URL_Helper')) {
        require_once MLD_PLUGIN_PATH . 'includes/class-mld-url-helper.php';
    }

    // If it's just an ID, create minimal listing array
    if (is_string($listing_or_id) || is_numeric($listing_or_id)) {
        return MLD_URL_Helper::get_property_url_by_id($listing_or_id);
    }

    // If it's an array, use the full URL helper
    if (is_array($listing_or_id)) {
        return MLD_URL_Helper::get_property_url($listing_or_id, $use_descriptive);
    }

    // Fallback to simple URL
    return home_url('/property/' . $listing_or_id . '/');
}

// Database Verification Tool - Added v5.2.9
add_action('admin_menu', function() {
    // Add submenu page for database verification
    add_submenu_page(
        'mls_listings_display',
        'Database Verification',
        'Database Verification',
        'manage_options',
        'mld_database_verify',
        'mld_render_database_verification_page'
    );
}, 20);

/**
 * Render the database verification page
 */
function mld_render_database_verification_page() {
    // Include the verification class if not already loaded
    if (!class_exists('MLD_Database_Verify')) {
        $class_file = plugin_dir_path(__FILE__) . 'includes/class-mld-database-verify.php';
        if (file_exists($class_file)) {
            require_once $class_file;
        } else {
            echo '<div class="wrap"><h1>Database Verification</h1><div class="error"><p>Database verification class not found.</p></div></div>';
            return;
        }
    }

    // Include the admin page template
    $page_file = plugin_dir_path(__FILE__) . 'admin/views/database-verification-page.php';
    if (file_exists($page_file)) {
        include $page_file;
    } else {
        echo '<div class="wrap"><h1>Database Verification</h1><div class="error"><p>Database verification page template not found.</p></div></div>';
    }
}

// WP-CLI Health Commands (v6.58.0)
if (defined('WP_CLI') && WP_CLI) {
    $cli_file = plugin_dir_path(__FILE__) . 'includes/health/class-mld-health-cli.php';
    if (file_exists($cli_file)) {
        require_once $cli_file;
    }
}