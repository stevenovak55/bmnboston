<?php
/**
 * Plugin Name: SN Appointment Booking
 * Plugin URI: https://steve-novak.com
 * Description: Google Calendar-integrated appointment booking system for real estate professionals. Allows clients to book showings, consultations, and other appointments directly from your website.
 * Version: 1.10.2
 * Author: Steve Novak
 * Author URI: https://steve-novak.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: sn-appointment-booking
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 *
 * == Changelog ==
 *
 * = 1.10.0 (2026-02-03) =
 * * FEATURE: Multi-attendee appointment support
 * * New wp_snab_appointment_attendees table for storing multiple clients per appointment
 * * Attendee types: primary, additional, cc (email notifications only)
 * * API accepts additional_clients and cc_emails parameters when booking
 * * All attendees receive email confirmations and reminders
 * * Appointment responses include attendees array and attendee_count
 * * iOS app supports multi-select client picker and CC email input
 * * Backward compatible - single client appointments work unchanged
 *
 * = 1.9.4 (2026-01-13) =
 * * Push notifications now respect MLD user preferences
 * * If user disables "Appointment Reminders" in notification settings, push reminders are skipped
 * * Preference check uses shared MLD_Client_Notification_Preferences class
 *
 * = 1.9.2 (2026-01-12) =
 * * BUGFIX: Staff can now reschedule appointments booked WITH them via web portal
 * * Fixed client portal AJAX handlers to check staff_id in addition to user_id
 * * Staff now bypass time restrictions and reschedule limits (same as REST API fix in 1.9.0)
 * * Affects: get_user_appointments, get_user_appointment, reschedule slots
 *
 * = 1.9.1 (2026-01-12) =
 * * REFACTOR: JWT authentication now uses shared MLD_JWT_Handler class
 * * Removed 150 lines of duplicate JWT code (now shared with MLD plugin)
 * * No functional changes - same authentication behavior
 *
 * = 1.9.0 (2026-01-11) =
 * * Staff can now view/cancel/reschedule appointments booked with them
 * * Fixed 5 endpoints that only checked user_id, now also check staff_id
 * * Affects: GET single appt, DELETE cancel, PATCH reschedule, GET reschedule-slots, GET ICS
 *
 * = 1.8.8 (2026-01-11) =
 * * SECURITY: Added UNIQUE constraint to prevent double-booking race conditions
 * * Constraint on (staff_id, appointment_date, start_time) enforces at DB level
 * * Transaction handling ensures atomic booking operations
 * * Upgrade auto-cancels any existing duplicate bookings before adding constraint
 *
 * = 1.8.7 (2026-01-09) =
 * * Rich content for appointment reminder push notifications
 * * Notifications now include listing_id and listing_key for deep linking
 * * Property images fetched from MLD summary tables for rich notifications
 * * Property address included in notification data payload
 * * Added notification_type field for iOS parsing consistency
 *
 * = 1.8.6 (2026-01-08) =
 * * Push Notifications admin tab with independent credential configuration
 * * Shows credential status (SNAB-specific, MLD fallback, or not configured)
 * * Test notification button for verifying APNs setup
 * * Setup instructions for Apple Developer Portal
 *
 * = 1.8.5 (2026-01-08) =
 * * Enhanced APNs push notifications:
 * * - Added apns-expiration header for 24-hour retry on offline devices
 * * - Added thread-id for notification grouping in iOS
 * * - Added mutable-content flag for rich notifications
 * * - Added JWT token caching (50-minute cache, improves efficiency)
 *
 * = 1.8.3 (2026-01-04) =
 * * WordPress User field now required when adding staff members
 * * Prevents staff from being created without linked user account
 *
 * = 1.8.2 (2026-01-04) =
 * * FIX: Staff members now see appointments booked with them (not just by them)
 * * GET /appointments endpoint now returns both user-booked AND staff-assigned appointments
 *
 * = 1.8.1 (2026-01-03) =
 * * Staff pre-selection via URL parameter: /book/?staff=ID
 * * Booking form auto-selects agent when linked from client dashboard
 * * Skips staff selection step when staff is pre-selected
 *
 * = 1.8.0 (2025-12-28) =
 * * HTML email templates with BMN Boston branding
 * * ICS calendar file attachments for confirmation and reminder emails
 * * REST endpoint for ICS download: GET /appointments/{id}/ics
 * * Push notification reminders (24h and 1h before appointments)
 * * Device token registration and management via REST API
 * * APNs integration for iOS push notifications
 * * Improved email formatting with responsive HTML design
 * * Auto-converts plain text templates to styled HTML
 *
 * = 1.7.0 (2025-12-27) =
 * * Phase 15: Cross-Platform REST API
 * * Added REST API namespace snab/v1 for iOS and web integration
 * * JWT authentication support (reuses MLD's JWT secret for unified auth)
 * * Dual auth: JWT (mobile) + WordPress session (web)
 * * Guest booking support (captures name/email/phone)
 * * Endpoints: appointment-types, staff, availability, appointments, portal/policy
 * * Full CRUD: create, view, cancel, reschedule appointments
 * * Rate limiting for booking attempts (5 per minute per IP)
 *
 * = 1.6.3 (2025-12-17) =
 * * Comprehensive database verification and repair system
 * * Fixed fresh install to include all tables (including staff_services)
 * * Fixed fresh install to include all columns (title, bio, avatar_url, google_access_token, etc.)
 * * Enhanced repair_database() to fix all missing tables, columns, and options
 * * Ensures upgrade hooks fire properly on plugin update (not just activation)
 *
 * = 1.6.2 (2025-12-15) =
 * * Per-staff availability management in admin
 * * Staff selector dropdown on Availability admin page
 * * Each staff member can have their own weekly schedule, date overrides, and blocked times
 *
 * = 1.6.1 (2025-12-15) =
 * * Per-staff Google Calendar connections
 * * Staff selector step in booking widget (optional/required mode)
 * * Added google_access_token, google_token_expires, title columns to staff table
 * * Staff filtered by appointment type during booking
 * * Dynamic back button navigation for staff step
 *
 * = 1.6.0 (2025-12-15) =
 * * Phase 14: Admin Experience Improvements
 * * Added Staff management page with CRUD operations
 * * Staff members can be linked to specific appointment types
 * * Added staff_services table for staff-to-service linking
 * * Added color column to appointment types for calendar display
 * * Added bio and avatar_url columns to staff table
 * * Admin notification preferences (per-type enable/disable)
 * * Secondary notification email address option
 * * Notification frequency setting (instant/daily digest)
 * * Staff selection mode for booking widget
 *
 * = 1.5.0 (2025-12-14) =
 * * Phase 13: Client Self-Service Portal
 * * New [snab_my_appointments] shortcode for logged-in users
 * * Clients can view, reschedule, and cancel their own appointments
 * * Configurable time limits for cancellation and rescheduling
 * * Maximum reschedule limits per appointment
 * * Optional cancellation reason requirement
 * * Admin notifications for client-initiated changes
 * * Client Portal settings tab in admin
 * * New client portal email notification templates
 * * Added cancelled_by column to appointments table
 *
 * = 1.4.4 (2025-12-14) =
 * * Comprehensive timezone fix across entire plugin
 * * Added snab_datetime_to_timestamp(), snab_format_date(), snab_format_time() helper functions
 * * Fixed admin dashboard time display (class-snab-admin.php)
 * * Fixed admin appointments reschedule slot labels (class-snab-admin-appointments.php)
 * * Fixed notifications old_date/old_time formatting (class-snab-notifications.php)
 * * Fixed analytics CSV export time formatting (class-snab-analytics.php)
 * * Fixed availability override/blocked time display (class-snab-admin-availability.php)
 * * All strtotime() calls now use proper WordPress timezone context
 *
 * = 1.4.3 (2025-12-14) =
 * * Fixed email notification timezone issue (9am displayed as 4am)
 * * parse_template() now uses DateTime with wp_timezone() instead of strtotime()
 *
 * = 1.4.2 (2025-12-14) =
 * * Fixed analytics default date range to include upcoming appointments (+30 days)
 * * Added "Upcoming" and "All Time" quick range buttons
 * * Added Database Status section to admin dashboard
 * * Added database repair functionality
 *
 * = 1.4.1 (2025-12-14) =
 * * Fixed analytics SQL queries to use correct column names (appointment_date, start_time)
 * * Fixed JavaScript chart canvas IDs and metric display IDs
 * * Fixed date range selector to use date inputs instead of dropdown
 *
 * = 1.4.0 (2025-12-14) =
 * * Phase 12: Analytics & Reporting Dashboard
 * * Added Analytics submenu with comprehensive metrics
 * * Key metrics: completion rate, no-show rate, cancellation rate, avg lead time
 * * Charts: appointments by day, by hour, by type, by status, trend over time
 * * Top clients and popular time slots tables
 * * CSV export functionality
 * * Chart.js integration for data visualization
 *
 * = 1.3.1 (2025-12-14) =
 * * Fixed time slot legibility - dark text on light background
 * * Comprehensive frontend redesign with gradients and shadows
 * * Widget now has card-style container with large shadow
 * * Appointment type cards with hover animations
 * * Calendar days with "Today" badge
 * * Time slots with proper contrast and hover states
 * * High-specificity CSS to override theme conflicts
 *
 * = 1.3.0 (2025-12-14) =
 * * Phase 11: Theme Styling Integration
 * * Added CSS custom properties for easy theming
 * * Redesigned booking widget with modern styling
 * * Added Appearance settings tab with color pickers
 * * Live preview for appearance customization
 * * Improved mobile responsiveness
 * * Better theme compatibility with CSS specificity
 *
 * = 1.2.4 (2025-12-14) =
 * * Fixed preset edit not showing saved selections - PHP returns arrays, JS was expecting strings
 *
 * = 1.2.3 (2025-12-14) =
 * * Fixed preset form data not being collected - changed $(this) to direct form selector
 * * Added debug logging to browser console for troubleshooting
 *
 * = 1.2.2 (2025-12-14) =
 * * Fixed preset save button not working - added click handler for save button outside form
 * * Fixed hidden input name mismatch (id vs preset_id)
 *
 * = 1.2.1 (2025-12-14) =
 * * Fixed presets modal not opening - corrected JavaScript element IDs
 * * Fixed reschedule time slots error - corrected slot data format handling
 * * Fixed AJAX parameter names for preset operations
 * * Fixed status badge CSS class names
 *
 * = 1.2.0 (2025-12-14) =
 * * Added custom shortcode presets feature
 * * Created shortcode presets admin page with CRUD operations
 * * Added preset attribute to [snab_booking_form] shortcode
 * * Added hour/day filtering to availability service
 * * Shortcode attributes: preset, types, days, start_hour, end_hour, location, title, class
 *
 * = 1.1.0 (2025-12-14) =
 * * Added manual appointment creation from admin
 * * Added reschedule functionality with notifications
 * * Added reschedule tracking (count, original datetime, reason)
 * * Added reschedule email template
 *
 * = 1.0.0 (2025-12-14) =
 * * Initial release
 * * Core plugin structure
 * * Database tables for staff, appointment types, availability rules, appointments, notifications
 * * Basic admin menu structure
 * * Google Calendar integration foundation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin version.
 * Update this when releasing new versions.
 * Also update in: class-snab-upgrader.php, version.json, .context/SESSION_RESUME.md
 */
define('SNAB_VERSION', '1.10.2');

/**
 * Database version.
 * Increment when database schema changes.
 */
define('SNAB_DB_VERSION', '1.10.0');

/**
 * Plugin file path.
 */
define('SNAB_PLUGIN_FILE', __FILE__);

/**
 * Plugin directory path.
 */
define('SNAB_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Plugin directory URL.
 */
define('SNAB_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Plugin basename.
 */
define('SNAB_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Convert date and/or time strings to Unix timestamp using WordPress timezone.
 *
 * IMPORTANT: This function fixes timezone issues that occur when using strtotime()
 * directly on date/time strings. strtotime() uses PHP's default timezone (usually UTC),
 * not WordPress timezone, causing times to display incorrectly (e.g., 9am becomes 4am).
 *
 * @since 1.4.4
 * @param string $date Date string (Y-m-d format) or empty for current date.
 * @param string $time Time string (H:i or H:i:s format) or empty for midnight.
 * @return int Unix timestamp in WordPress timezone.
 */
function snab_datetime_to_timestamp($date = '', $time = '') {
    $timezone = wp_timezone();

    // If no date, use today
    if (empty($date)) {
        $date = wp_date('Y-m-d');
    }

    // If no time, use midnight
    if (empty($time)) {
        $time = '00:00:00';
    }

    // Ensure time has seconds
    if (strlen($time) === 5) {
        $time .= ':00';
    }

    // Create DateTime with WordPress timezone
    $dt = new DateTime($date . ' ' . $time, $timezone);
    return $dt->getTimestamp();
}

/**
 * Format a date string for display using WordPress timezone.
 *
 * @since 1.4.4
 * @param string $date Date string (Y-m-d format).
 * @param string $format Optional format, defaults to WordPress date_format.
 * @return string Formatted date.
 */
function snab_format_date($date, $format = '') {
    if (empty($format)) {
        $format = get_option('date_format');
    }
    return wp_date($format, snab_datetime_to_timestamp($date));
}

/**
 * Format a time string for display using WordPress timezone.
 *
 * @since 1.4.4
 * @param string $date Date string (Y-m-d format) - required for proper timezone context.
 * @param string $time Time string (H:i or H:i:s format).
 * @param string $format Optional format, defaults to WordPress time_format.
 * @return string Formatted time.
 */
function snab_format_time($date, $time, $format = '') {
    if (empty($format)) {
        $format = get_option('time_format');
    }
    return wp_date($format, snab_datetime_to_timestamp($date, $time));
}

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class SN_Appointment_Booking {

    /**
     * Single instance of the class.
     *
     * @var SN_Appointment_Booking
     */
    private static $instance = null;

    /**
     * Plugin components.
     *
     * @var array
     */
    private $components = array();

    /**
     * Get single instance of the class.
     *
     * @return SN_Appointment_Booking
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files.
     */
    private function load_dependencies() {
        // Core classes
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-logger.php';
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-activator.php';
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-deactivator.php';
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-upgrader.php';

        // Google Calendar integration (needed for both admin and frontend)
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-google-calendar.php';

        // Availability service (needed for both admin and frontend)
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-availability-service.php';

        // Admin classes (only load in admin)
        if (is_admin()) {
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-types.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-availability.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-appearance.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-settings.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-appointments.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-presets.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-analytics.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-staff.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin-calendar.php';
            require_once SNAB_PLUGIN_DIR . 'includes/class-snab-admin.php';
        }

        // Frontend classes
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-shortcodes.php';
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-frontend-ajax.php';

        // Notifications
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-notifications.php';

        // Client portal (for logged-in users)
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-client-portal.php';
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-client-portal-ajax.php';

        // REST API (for iOS/mobile and web)
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-rest-api.php';

        // Push Notifications
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-push-notifications.php';

        // ICS Generator
        require_once SNAB_PLUGIN_DIR . 'includes/class-snab-ics-generator.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Run upgrade check on plugins_loaded
        add_action('plugins_loaded', array($this, 'check_version'));

        // Initialize admin
        if (is_admin()) {
            add_action('plugins_loaded', array($this, 'init_admin'));
        }

        // Initialize frontend
        add_action('init', array($this, 'init_frontend'));

        // Initialize REST API
        add_action('init', array($this, 'init_rest_api'));

        // Initialize Push Notifications
        add_action('init', array($this, 'init_push_notifications'));

        // Load textdomain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    /**
     * Check version and run upgrades if needed.
     */
    public function check_version() {
        SNAB_Upgrader::check_version();
    }

    /**
     * Initialize admin functionality.
     */
    public function init_admin() {
        $this->components['admin'] = new SNAB_Admin();
    }

    /**
     * Initialize frontend functionality.
     */
    public function init_frontend() {
        $this->components['shortcodes'] = new SNAB_Shortcodes();
        $this->components['frontend_ajax'] = new SNAB_Frontend_Ajax();
        $this->components['notifications'] = snab_notifications();
        $this->components['client_portal'] = snab_client_portal();
        $this->components['client_portal_ajax'] = new SNAB_Client_Portal_Ajax();
    }

    /**
     * Initialize REST API for iOS/mobile and web clients.
     *
     * @since 1.7.0
     */
    public function init_rest_api() {
        SNAB_REST_API::init();
    }

    /**
     * Initialize push notifications for appointment reminders.
     *
     * @since 1.8.0
     */
    public function init_push_notifications() {
        if (class_exists('SNAB_Push_Notifications')) {
            $this->components['push_notifications'] = SNAB_Push_Notifications::instance();
        }
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'sn-appointment-booking',
            false,
            dirname(SNAB_PLUGIN_BASENAME) . '/languages/'
        );
    }

    /**
     * Get a component instance.
     *
     * @param string $component Component name.
     * @return mixed|null Component instance or null.
     */
    public function get_component($component) {
        return isset($this->components[$component]) ? $this->components[$component] : null;
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserializing.
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Get the main plugin instance.
 *
 * @return SN_Appointment_Booking
 */
function snab() {
    return SN_Appointment_Booking::instance();
}

/**
 * Activation hook.
 */
function snab_activate() {
    require_once SNAB_PLUGIN_DIR . 'includes/class-snab-activator.php';
    SNAB_Activator::activate();
}
register_activation_hook(__FILE__, 'snab_activate');

/**
 * Deactivation hook.
 */
function snab_deactivate() {
    require_once SNAB_PLUGIN_DIR . 'includes/class-snab-deactivator.php';
    SNAB_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'snab_deactivate');

// Initialize the plugin
snab();
