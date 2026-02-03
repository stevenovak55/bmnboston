<?php
/**
 * Theme Upgrader Class
 *
 * Handles theme activation, updates, and version migrations.
 *
 * @package flavor_flavor_flavor
 * @version 1.4.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Theme_Upgrader {

    /**
     * Current theme version
     */
    const CURRENT_VERSION = '1.4.3';

    /**
     * Option key for stored version
     */
    const VERSION_OPTION = 'bne_theme_version';

    /**
     * Initialize upgrader hooks
     */
    public static function init() {
        // Run on theme switch (activation)
        add_action('after_switch_theme', array(__CLASS__, 'on_theme_activation'));

        // Check for updates on every page load (admin only for performance)
        add_action('admin_init', array(__CLASS__, 'check_for_updates'));

        // Also check on frontend init but less frequently
        add_action('init', array(__CLASS__, 'maybe_check_updates'));
    }

    /**
     * Check for updates on frontend (throttled)
     */
    public static function maybe_check_updates() {
        // Skip if already checked recently (within last hour)
        $last_check = get_transient('bne_theme_update_check');
        if ($last_check) {
            return;
        }

        // Set transient to prevent checking too often
        set_transient('bne_theme_update_check', time(), HOUR_IN_SECONDS);

        self::check_for_updates();
    }

    /**
     * Run on theme activation
     */
    public static function on_theme_activation() {
        $installed_version = get_option(self::VERSION_OPTION, '0.0.0');

        // Log activation
        error_log('BNE Theme: Activated. Previous version: ' . $installed_version . ', Current version: ' . self::CURRENT_VERSION);

        // If fresh install, set defaults
        if ($installed_version === '0.0.0') {
            self::set_default_theme_mods();
        }

        // Run any needed upgrades
        self::run_upgrades($installed_version);

        // Update stored version
        update_option(self::VERSION_OPTION, self::CURRENT_VERSION);

        // Clear any caches
        self::clear_caches();
    }

    /**
     * Check if theme needs updates
     */
    public static function check_for_updates() {
        $installed_version = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($installed_version, self::CURRENT_VERSION, '<')) {
            error_log('BNE Theme: Update needed. From ' . $installed_version . ' to ' . self::CURRENT_VERSION);
            self::run_upgrades($installed_version);
            update_option(self::VERSION_OPTION, self::CURRENT_VERSION);
            self::clear_caches();
        }
    }

    /**
     * Run upgrade routines based on version
     */
    private static function run_upgrades($from_version) {
        // Upgrade to 1.0.10 - Hero section updates
        if (version_compare($from_version, '1.0.10', '<')) {
            self::upgrade_to_1_0_10();
        }

        // Upgrade to 1.0.12 - Header settings and hide site title
        if (version_compare($from_version, '1.0.12', '<')) {
            self::upgrade_to_1_0_12();
        }

        // Upgrade to 1.0.15 - Ensure all settings are present and clear caches
        if (version_compare($from_version, '1.0.15', '<')) {
            self::upgrade_to_1_0_15();
        }

        // Upgrade to 1.1.0 - Add mobile drawer, search form, analytics section
        if (version_compare($from_version, '1.1.0', '<')) {
            self::upgrade_to_1_1_0();
        }

        // Upgrade to 1.2.7 - New customizer fields for hero section
        if (version_compare($from_version, '1.2.7', '<')) {
            self::upgrade_to_1_2_7();
        }

        // Upgrade to 1.3.0 - Add lead generation sections to Section Manager
        if (version_compare($from_version, '1.3.0', '<')) {
            self::upgrade_to_1_3_0();
        }
    }

    /**
     * Upgrade to version 1.1.0
     * Adds new settings for hero search, analytics section, and mobile drawer
     */
    private static function upgrade_to_1_1_0() {
        error_log('BNE Theme: Running upgrade to 1.1.0');

        // Set new defaults for 1.1.0 features
        $new_settings = array(
            'bne_show_hero_search' => true,
            'bne_show_analytics_section' => true,
            'bne_analytics_title' => 'Explore Boston Neighborhoods',
            'bne_analytics_subtitle' => 'Discover market insights and find your perfect neighborhood',
            'bne_analytics_neighborhoods' => 'Back Bay, South End, Beacon Hill, Jamaica Plain, Charlestown, Cambridge',
        );

        foreach ($new_settings as $key => $value) {
            if (get_theme_mod($key) === false) {
                set_theme_mod($key, $value);
                error_log('BNE Theme: Set new 1.1.0 setting: ' . $key);
            }
        }
    }

    /**
     * Upgrade to version 1.2.7
     * Sets defaults for new customizer fields added in this version
     */
    private static function upgrade_to_1_2_7() {
        error_log('BNE Theme: Running upgrade to 1.2.7');

        // New customizer fields added in 1.2.7
        $new_settings = array(
            'bne_agent_email' => '',
            'bne_agent_address' => '',
            'bne_group_name' => '',
            'bne_group_url' => '',
        );

        foreach ($new_settings as $key => $value) {
            if (get_theme_mod($key) === false) {
                set_theme_mod($key, $value);
                error_log('BNE Theme: Set new 1.2.7 setting: ' . $key);
            }
        }
    }

    /**
     * Upgrade to version 1.3.0
     * Adds lead generation sections to Section Manager if they're missing
     */
    private static function upgrade_to_1_3_0() {
        error_log('BNE Theme: Running upgrade to 1.3.0');

        // Get current saved sections
        $sections = get_option('bne_homepage_sections', array());

        // If no sections saved, nothing to upgrade (defaults will be used)
        if (empty($sections)) {
            error_log('BNE Theme: No saved sections, skipping 1.3.0 upgrade');
            return;
        }

        // Get IDs of currently saved sections
        $existing_ids = array();
        foreach ($sections as $section) {
            $existing_ids[] = $section['id'];
        }

        // Lead gen sections that need to be added
        $lead_gen_sections = array(
            'cma-request' => array(
                'id' => 'cma-request',
                'type' => 'builtin',
                'name' => 'CMA Request Form',
                'enabled' => true,
                'override_html' => ''
            ),
            'property-alerts' => array(
                'id' => 'property-alerts',
                'type' => 'builtin',
                'name' => 'Property Alerts',
                'enabled' => true,
                'override_html' => ''
            ),
            'schedule-showing' => array(
                'id' => 'schedule-showing',
                'type' => 'builtin',
                'name' => 'Schedule Tour',
                'enabled' => true,
                'override_html' => ''
            ),
            'mortgage-calc' => array(
                'id' => 'mortgage-calc',
                'type' => 'builtin',
                'name' => 'Mortgage Calculator',
                'enabled' => true,
                'override_html' => ''
            ),
        );

        // Add any missing lead gen sections
        $added = false;
        foreach ($lead_gen_sections as $id => $section_data) {
            if (!in_array($id, $existing_ids)) {
                $sections[] = $section_data;
                $added = true;
                error_log('BNE Theme: Added missing section: ' . $id);
            }
        }

        // Save updated sections if we added any
        if ($added) {
            update_option('bne_homepage_sections', $sections);
            error_log('BNE Theme: Saved updated sections with lead gen tools');
        }

        // Set default theme mods for lead gen sections
        $lead_gen_settings = array(
            'bne_show_cma_section' => true,
            'bne_show_tour_section' => true,
            'bne_show_mortgage_section' => true,
            'bne_cma_title' => 'Get Your Free Home Valuation',
            'bne_cma_subtitle' => 'Request a Comparative Market Analysis to discover what your home is worth in today\'s market.',
            'bne_default_mortgage_rate' => 6.5,
            'bne_default_property_tax_rate' => 1.2,
            'bne_default_home_insurance' => 1200,
        );

        foreach ($lead_gen_settings as $key => $value) {
            if (get_theme_mod($key) === false) {
                set_theme_mod($key, $value);
                error_log('BNE Theme: Set lead gen setting: ' . $key);
            }
        }
    }

    /**
     * Upgrade to version 1.0.10
     * Adds hero section theme mods
     */
    private static function upgrade_to_1_0_10() {
        error_log('BNE Theme: Running upgrade to 1.0.10');

        // Set hero defaults if not already set
        $hero_defaults = array(
            'bne_agent_name' => 'Steven Novak',
            'bne_agent_title' => 'Licensed Real Estate Salesperson',
            'bne_license_number' => 'MA: 9517748',
            'bne_phone_number' => '(617) 955-2224',
            'bne_agent_email' => '',
            'bne_agent_address' => '',
            'bne_group_name' => '',
            'bne_group_url' => '',
        );

        foreach ($hero_defaults as $key => $default) {
            if (!get_theme_mod($key)) {
                set_theme_mod($key, $default);
            }
        }
    }

    /**
     * Upgrade to version 1.0.12
     * Adds header settings including hide site title
     */
    private static function upgrade_to_1_0_12() {
        error_log('BNE Theme: Running upgrade to 1.0.12');

        // Ensure hide site title setting exists
        // Note: We do NOT override if already set
        $current_value = get_theme_mod('bne_hide_site_title');
        if ($current_value === false) {
            set_theme_mod('bne_hide_site_title', 0);
        }
    }

    /**
     * Upgrade to version 1.0.15
     * Ensures all theme settings are properly initialized and clears URL caches
     */
    private static function upgrade_to_1_0_15() {
        error_log('BNE Theme: Running upgrade to 1.0.15');

        // Verify all default theme mods exist
        self::ensure_all_theme_mods();

        // Force refresh of customizer cache
        delete_transient('bne_theme_update_check');

        // Note: clear_caches() is called after all upgrades complete,
        // which will clear the BNE MLS transients with old URL formats
    }

    /**
     * Set default theme mods on fresh install
     */
    public static function set_default_theme_mods() {
        error_log('BNE Theme: Setting default theme mods for fresh install');

        $defaults = self::get_all_defaults();

        foreach ($defaults as $key => $value) {
            set_theme_mod($key, $value);
        }
    }

    /**
     * Ensure all theme mods exist (for upgrades)
     */
    private static function ensure_all_theme_mods() {
        $defaults = self::get_all_defaults();

        foreach ($defaults as $key => $value) {
            $current = get_theme_mod($key);
            // Only set if completely missing (false means not set)
            if ($current === false) {
                set_theme_mod($key, $value);
                error_log('BNE Theme: Set missing theme_mod: ' . $key);
            }
        }
    }

    /**
     * Get all default theme mod values
     */
    public static function get_all_defaults() {
        return array(
            // Header Settings
            'bne_hide_site_title' => 0,

            // Hero Section
            'bne_agent_name' => 'Steven Novak',
            'bne_agent_title' => 'Licensed Real Estate Salesperson',
            'bne_license_number' => 'MA: 9517748',
            'bne_phone_number' => '(617) 955-2224',
            'bne_agent_photo' => '',
            'bne_agent_email' => '',
            'bne_agent_address' => '',
            'bne_group_name' => '',
            'bne_group_url' => '',

            // Social Media
            'bne_social_instagram' => '',
            'bne_social_facebook' => '',
            'bne_social_youtube' => '',
            'bne_social_linkedin' => '',

            // About Section
            'bne_sales_amount' => '$500 Million',
            'bne_team_ranking' => 'Top 3 small teams at Douglas Elliman Real Estate',
            'bne_about_description' => 'With decades of combined experience, our team delivers exceptional results for buyers and sellers across Greater Boston.',

            // Featured Locations
            'bne_featured_neighborhoods' => 'Seaport District,South Boston,South End,Back Bay,East Boston,Jamaica Plain,Beacon Hill',
            'bne_featured_cities' => 'Boston,Newton,Cambridge,Somerville,Arlington,Needham',

            // Brokerage
            'bne_brokerage_logo' => '',
            'bne_brokerage_name' => 'Douglas Elliman Real Estate',

            // Hero Search (v1.1.0)
            'bne_show_hero_search' => true,

            // Analytics Section (v1.1.0)
            'bne_show_analytics_section' => true,
            'bne_analytics_title' => 'Explore Boston Neighborhoods',
            'bne_analytics_subtitle' => 'Discover market insights and find your perfect neighborhood',
            'bne_analytics_neighborhoods' => 'Back Bay, South End, Beacon Hill, Jamaica Plain, Charlestown, Cambridge',
        );
    }

    /**
     * Clear various caches after upgrade
     */
    private static function clear_caches() {
        global $wpdb;

        // Clear WordPress object cache
        wp_cache_flush();

        // Clear any transients we use
        delete_transient('bne_theme_update_check');

        // Clear customizer cache if it exists
        delete_option('theme_mods_' . get_option('stylesheet') . '_cache');

        // IMPORTANT: Clear ALL BNE MLS transients (contains cached URLs)
        // This ensures new URL format is used after upgrade
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bne_%'
             OR option_name LIKE '_transient_timeout_bne_%'"
        );

        // Also call MLS Helpers clear_caches if available
        if (class_exists('BNE_MLS_Helpers') && method_exists('BNE_MLS_Helpers', 'clear_caches')) {
            BNE_MLS_Helpers::clear_caches();
        }

        error_log('BNE Theme: All caches cleared including MLS transients');
    }

    /**
     * Get current installed version
     */
    public static function get_installed_version() {
        return get_option(self::VERSION_OPTION, '0.0.0');
    }

    /**
     * Get current theme version
     */
    public static function get_current_version() {
        return self::CURRENT_VERSION;
    }

    /**
     * Check if theme needs update
     */
    public static function needs_update() {
        return version_compare(self::get_installed_version(), self::CURRENT_VERSION, '<');
    }
}
