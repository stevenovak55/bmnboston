<?php
/**
 * MLS Listings Display Plugin Upgrader
 *
 * Handles all plugin upgrades including database migrations, cache clearing,
 * and version tracking. Ensures all changes are applied when the plugin
 * is updated on a live server through WordPress's plugin update mechanism.
 *
 * @package MLS_Listings_Display
 * @since 4.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Upgrader {

    /**
     * Current plugin version
     */
    const CURRENT_VERSION = '6.70.1';

    /**
     * Current database version (legacy compatibility)
     */
    const CURRENT_DB_VERSION = '6.70.1';

    /**
     * Option key for storing plugin version
     */
    const VERSION_OPTION = 'mld_plugin_version';

    /**
     * Option key for storing upgrade status
     */
    const UPGRADE_STATUS_OPTION = 'mld_upgrade_status';

    /**
     * Option key for storing migration history
     */
    const MIGRATION_HISTORY_OPTION = 'mld_migration_history';

    /**
     * Database migrator instance
     */
    private $database_migrator;

    /**
     * Upgrade results
     */
    private $results = array();

    /**
     * Constructor
     */
    public function __construct() {
        if (class_exists('MLD_Database_Migrator')) {
            $this->database_migrator = new MLD_Database_Migrator();
        }
    }

    /**
     * Check if plugin needs upgrade
     *
     * @return bool True if upgrade needed
     */
    public function needs_upgrade() {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');
        return version_compare($current_version, self::CURRENT_VERSION, '<');
    }

    /**
     * Get stored plugin version
     *
     * @return string Current stored version
     */
    public function get_stored_version() {
        return get_option(self::VERSION_OPTION, '0.0.0');
    }

    /**
     * Check and run necessary upgrades (legacy compatibility)
     */
    public static function check_upgrades() {
        $instance = new self();

        // Check both legacy DB version and new plugin version
        $current_db_version = get_option('mld_db_version', '0');
        $current_plugin_version = get_option(self::VERSION_OPTION, '0.0.0');

        // Run legacy upgrades first
        if (version_compare($current_db_version, self::CURRENT_DB_VERSION, '<')) {
            self::run_legacy_upgrades($current_db_version);
            update_option('mld_db_version', self::CURRENT_DB_VERSION);
        }

        // Run new upgrade system
        if ($instance->needs_upgrade()) {
            $instance->run_upgrade();
        }
    }

    /**
     * Run the complete upgrade process
     *
     * @return array Upgrade results
     */
    public function run_upgrade() {
        $start_time = microtime(true);
        $stored_version = $this->get_stored_version();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Upgrader: Starting upgrade from version {$stored_version} to " . self::CURRENT_VERSION);
        }

        // Set upgrade status
        update_option(self::UPGRADE_STATUS_OPTION, array(
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'from_version' => $stored_version,
            'to_version' => self::CURRENT_VERSION
        ));

        try {
            // Phase 1: Pre-upgrade checks
            $this->results['pre_checks'] = $this->run_pre_upgrade_checks();

            // Phase 2: Database migrations
            $this->results['database'] = $this->run_database_migrations($stored_version);

            // Phase 2.5: Verify and repair all database tables
            $this->results['table_verification'] = $this->verify_and_repair_tables();

            // Phase 3: Clear all caches
            $this->results['cache'] = $this->clear_all_caches();

            // Phase 4: Update options and settings
            $this->results['options'] = $this->update_plugin_options($stored_version);

            // Phase 5: Run compatibility fixes
            $this->results['compatibility'] = $this->run_compatibility_fixes($stored_version);

            // Phase 6: Update version number
            $this->results['version_update'] = $this->update_version_number();

            // Calculate upgrade duration
            $duration = round(microtime(true) - $start_time, 2);
            $this->results['duration'] = $duration;

            // Update upgrade status to completed
            update_option(self::UPGRADE_STATUS_OPTION, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'from_version' => $stored_version,
                'to_version' => self::CURRENT_VERSION,
                'duration' => $duration,
                'results' => $this->results
            ));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Upgrader: Upgrade completed successfully in {$duration} seconds");
            }

            // Store migration history
            $this->store_migration_history($stored_version, self::CURRENT_VERSION, $this->results);

            // Fire upgrade completed action for other components to hook into
            do_action('mld_upgrade_completed', $stored_version, self::CURRENT_VERSION, $this->results);

            // Set transient to ensure rewrite rules are flushed on next page load
            set_transient('mld_flush_rewrite_rules', true, 60);

            return $this->results;

        } catch (Exception $e) {
            $error_message = 'MLD Upgrade failed: ' . $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($error_message);
            }

            // Update upgrade status to failed
            update_option(self::UPGRADE_STATUS_OPTION, array(
                'status' => 'failed',
                'failed_at' => current_time('mysql'),
                'from_version' => $stored_version,
                'to_version' => self::CURRENT_VERSION,
                'error' => $error_message,
                'results' => $this->results
            ));

            $this->results['error'] = $error_message;
            return $this->results;
        }
    }

    /**
     * Run legacy upgrades (for backward compatibility)
     *
     * @param string $current_version Current database version
     */
    private static function run_legacy_upgrades($current_version) {
        // Upgrade to 4.4.6 - Add notification preference columns
        if (version_compare($current_version, '4.4.6', '<')) {
            self::upgrade_to_4_4_6();
        }

        // Upgrade to 4.4.7 - Add notification queue table
        if (version_compare($current_version, '4.4.7', '<')) {
            self::upgrade_to_4_4_7();
        }

        // Upgrade to 4.5.36 - Clear cities cache for pre-loaded optimization
        if (version_compare($current_version, '4.5.36', '<')) {
            self::upgrade_to_4_5_36();
        }

        // Upgrade to 4.5.37 - Clear cache again after data provider fix
        if (version_compare($current_version, '4.5.37', '<')) {
            self::upgrade_to_4_5_37();
        }

        // Upgrade to 4.5.47 - Ensure all cron jobs are scheduled
        if (version_compare($current_version, '4.5.47', '<')) {
            self::upgrade_to_4_5_47();
        }

        // Upgrade to 5.2.0 - CMA Intelligence Upgrade (Market Data, Forecasting, PDF, Email)
        if (version_compare($current_version, '5.2.0', '<')) {
            self::upgrade_to_5_2_0();
        }

        // Upgrade to 6.6.0 - AI Chatbot System with Multi-Provider Support
        if (version_compare($current_version, '6.6.0', '<')) {
            self::upgrade_to_6_6_0();
        }

        // Upgrade to 6.6.1 - Chatbot Database Tables Installation
        if (version_compare($current_version, '6.6.1', '<')) {
            self::upgrade_to_6_6_1();
        }

        // Upgrade to 6.7.0 - Enhanced Chatbot with Data References
        if (version_compare($current_version, '6.7.0', '<')) {
            self::upgrade_to_6_7_0();
        }

        // Upgrade to 6.8.0 - Training System and Custom System Prompts
        if (version_compare($current_version, '6.8.0', '<')) {
            self::upgrade_to_6_8_0();
        }

        // Upgrade to 6.9.0 - A/B Testing & Advanced Training Features
        if (version_compare($current_version, '6.9.0', '<')) {
            self::upgrade_to_6_9_0();
        }

        // Upgrade to 6.10.6 - CMA Calculation Refinements with FHA-Style Adjustments
        if (version_compare($current_version, '6.10.6', '<')) {
            self::upgrade_to_6_10_6();
        }

        // Upgrade to 6.10.7 - Code quality fixes (wpdb::prepare warnings)
        if (version_compare($current_version, '6.10.7', '<')) {
            self::upgrade_to_6_10_7();
        }

        // Upgrade to 6.11.7 - Map pin reliability improvements (JS/CSS changes)
        if (version_compare($current_version, '6.11.7', '<')) {
            self::upgrade_to_6_11_7();
        }

        // Upgrade to 6.11.8 - SavedSearchRepository column name fix
        if (version_compare($current_version, '6.11.8', '<')) {
            self::upgrade_to_6_11_8();
        }

        // Upgrade to 6.11.9 - Saved search notification system fix
        if (version_compare($current_version, '6.11.9', '<')) {
            self::upgrade_to_6_11_9();
        }

        // Upgrade to 6.11.10 - Cron job self-healing
        if (version_compare($current_version, '6.11.10', '<')) {
            self::upgrade_to_6_11_10();
        }

        // Upgrade to 6.11.11 - Summary table sync diagnostic & self-healing
        if (version_compare($current_version, '6.11.11', '<')) {
            self::upgrade_to_6_11_11();
        }

        // Upgrade to 6.11.12 - Change daily notifications to every 10 minutes
        if (version_compare($current_version, '6.11.12', '<')) {
            self::upgrade_to_6_11_12();
        }

        // Upgrade to 6.11.13 - Design System CSS Refresh
        if (version_compare($current_version, '6.11.13', '<')) {
            self::upgrade_to_6_11_13();
        }

        // Upgrade to 6.11.14 - Legacy Chatbot Removal & AI Chatbot Tools
        if (version_compare($current_version, '6.11.14', '<')) {
            self::upgrade_to_6_11_14();
        }

        // Upgrade to 6.11.15 - Desktop gallery viewport height fix
        if (version_compare($current_version, '6.11.15', '<')) {
            self::upgrade_to_6_11_15();
        }

        // Upgrade to 6.11.16 - Chatbot scroll triggers and sidebar removal
        if (version_compare($current_version, '6.11.16', '<')) {
            self::upgrade_to_6_11_16();
        }

        // Upgrade to 6.11.17 - Mobile fullscreen app-like experience
        if (version_compare($current_version, '6.11.17', '<')) {
            self::upgrade_to_6_11_17();
        }

        // Upgrade to 6.11.18 - Move fullscreen button to bottom-left
        if (version_compare($current_version, '6.11.18', '<')) {
            self::upgrade_to_6_11_18();
        }

        // Upgrade to 6.11.19 - Fix CSS fullscreen on page load
        if (version_compare($current_version, '6.11.19', '<')) {
            self::upgrade_to_6_11_19();
        }

        // Upgrade to 6.11.20 - Mobile chatbot improvements
        if (version_compare($current_version, '6.11.20', '<')) {
            self::upgrade_to_6_11_20();
        }

        // Upgrade to 6.11.44 - Fix chatbot settings table schema
        // Adds missing columns: setting_category, setting_type, is_encrypted, description
        if (version_compare($current_version, '6.11.44', '<')) {
            self::upgrade_to_6_11_44();
        }

        // Upgrade to 6.11.46 - Comprehensive fix for ALL chatbot tables
        // Adds missing columns to: chat_settings, chat_sessions, chat_messages
        if (version_compare($current_version, '6.11.46', '<')) {
            self::upgrade_to_6_11_46();
        }

        // Upgrade to 6.11.47 - COMPREHENSIVE FIX for ALL 8 chatbot tables
        // Ensures all columns exist in: conversations, messages, sessions, settings,
        // knowledge_base, faq_library, admin_notifications, email_summaries
        if (version_compare($current_version, '6.11.47', '<')) {
            self::upgrade_to_6_11_47();
        }

        // Upgrade to 6.11.48 - CRITICAL FIX for email notification columns
        // Adds missing columns that PHP code actually uses:
        // - notification_data in admin_notifications
        // - key_topics, properties_mentioned, ai_provider, ai_model, delivery_status in email_summaries
        if (version_compare($current_version, '6.11.48', '<')) {
            self::upgrade_to_6_11_48();
        }

        // Upgrade to 6.12.0 - Enhanced Market Analytics System
        // Creates 4 new tables for comprehensive market analytics:
        // - mld_market_stats_monthly: Pre-computed monthly statistics by city
        // - mld_city_market_summary: Current market state cache for fast dashboard loads
        // - mld_agent_performance: Agent/office ranking and performance metrics
        // - mld_feature_premiums: Property feature value analysis (waterfront, pool, etc.)
        if (version_compare($current_version, '6.12.0', '<')) {
            self::upgrade_to_6_12_0();
        }

        // Upgrade to 6.12.8 - Property Page Analytics Integration
        if (version_compare($current_version, '6.12.8', '<')) {
            self::upgrade_to_6_12_8();
        }

        // Upgrade to 6.13.0 - 15-Minute Saved Search Email Alert System
        // Enables comprehensive change detection (new listings, price changes, status changes)
        // with all 45+ half-map filters and change-type-specific email notifications
        if (version_compare($current_version, '6.13.0', '<')) {
            self::upgrade_to_6_13_0();
        }

        // Upgrade to 6.13.14 - Notification System Consolidation
        // Disables legacy Simple Notifications, enhances email template with market insights
        // and social media links, clears orphaned cron jobs
        if (version_compare($current_version, '6.13.14', '<')) {
            self::upgrade_to_6_13_14();
        }

        // Upgrade to 6.13.17 - Dark mode fixes, Analytics REST API, Mobile comparable sales
        if (version_compare($current_version, '6.13.17', '<')) {
            self::upgrade_to_6_13_17();
        }

        // Upgrade to 6.13.18 - Kinsta cache bypass fix (AJAX no-cache headers)
        if (version_compare($current_version, '6.13.18', '<')) {
            self::upgrade_to_6_13_18();
        }

        // Upgrade to 6.13.19 - Disable internal transient cache on Kinsta/Redis
        if (version_compare($current_version, '6.13.19', '<')) {
            self::upgrade_to_6_13_19();
        }

        // Upgrade to 6.13.20 - Summary table fallback refresh for Kinsta
        if (version_compare($current_version, '6.13.20', '<')) {
            self::upgrade_to_6_13_20();
        }

        // Upgrade to 6.14.0 - AI Chatbot Context Persistence & Comprehensive Property Data
        // Features:
        // - Search context persistence (remembers city, price, bedrooms between messages)
        // - Shown properties tracking (enables "show me #5" references)
        // - Active property storage (full property data for follow-up questions)
        // - Collected user info (name, phone, email - never ask twice)
        // - Returning visitor recognition
        // - Boston neighborhood mapping for accurate geographic filtering
        if (version_compare($current_version, '6.14.0', '<')) {
            self::upgrade_to_6_14_0();
        }

        // Upgrade to 6.14.2 - Contact/Tour Form Schema Update
        // Adds columns to mld_form_submissions for tour scheduling:
        // - property_address, tour_type, preferred_date, preferred_time
        if (version_compare($current_version, '6.14.2', '<')) {
            self::upgrade_to_6_14_2();
        }

        // Upgrade to 6.16.0 - CMA Enhancement Features
        // Creates mld_cma_saved_sessions table for:
        // - Save/load CMA sessions for logged-in users
        // - ARV (After Repair Value) adjustments tracking
        // - PDF generation path storage
        if (version_compare($current_version, '6.16.0', '<')) {
            self::upgrade_to_6_16_0();
        }

        // Upgrade to 6.17.0 - Standalone CMA Feature
        // Adds columns to mld_cma_saved_sessions for:
        // - is_standalone: Flag for CMAs without MLS listing
        // - standalone_slug: URL slug for standalone CMA pages
        if (version_compare($current_version, '6.17.0', '<')) {
            self::upgrade_to_6_17_0();
        }

        // Upgrade to 6.20.0 - Historical CMA Tracking
        // Creates mld_cma_value_history table for:
        // - Tracking property valuations over time
        // - Trend visualization on property pages
        // - Re-run CMA with previous parameters
        if (version_compare($current_version, '6.20.0', '<')) {
            self::upgrade_to_6_20_0();
        }

        // Upgrade to 6.20.3 - Market Conditions Fixes
        // - Clear market conditions cache for property type normalization fix
        // - Fix JS renderMarketConditions check
        if (version_compare($current_version, '6.20.3', '<')) {
            self::upgrade_to_6_20_3();
        }

        // Upgrade to 6.20.12 - Chatbot Notification Table Schema Fix
        // Ensures notification_data column exists in admin_notifications table
        // Defensive migration to fix production sites that may have missed earlier schema updates
        if (version_compare($current_version, '6.20.12', '<')) {
            self::upgrade_to_6_20_12();
        }

        // Upgrade to 6.21.0 - Universal Contact Form System
        // Creates mld_contact_forms table for:
        // - Custom field builder with drag-and-drop interface
        // - Multiple forms with unique shortcodes
        // - Per-form notification settings
        // - Global Customizer styling
        if (version_compare($current_version, '6.21.0', '<')) {
            self::upgrade_to_6_21_0();
        }

        // Upgrade to 6.24.0 - File Uploads + Form Templates
        // - Add wp_mld_form_uploads table for file upload tracking
        // - Add wp_mld_form_templates table for pre-built form templates
        // - Insert 6 default real estate form templates
        if (version_compare($current_version, '6.24.0', '<')) {
            self::upgrade_to_6_24_0();
        }

        // Upgrade to 6.27.0 - Lead Capture Gate Form for Chatbot
        // Adds lead_gate_enabled setting to require user info before chatting
        if (version_compare($current_version, '6.27.0', '<')) {
            self::upgrade_to_6_27_0();
        }

        // Upgrade to 6.69.0 - Open House Sign-In System
        // Creates wp_mld_open_houses and wp_mld_open_house_attendees tables
        if (version_compare($current_version, '6.69.0', '<')) {
            self::upgrade_to_6_69_0();
        }

        // Upgrade to 6.70.0 - Open House Agent Detection & CRM Integration
        // Adds agent visitor fields and priority scoring to attendees table
        if (version_compare($current_version, '6.70.0', '<')) {
            self::upgrade_to_6_70_0();
        }

        // Upgrade to 6.70.1 - Open House Schema Fix
        // Adds missing updated_at column to attendees table
        if (version_compare($current_version, '6.70.1', '<')) {
            self::upgrade_to_6_70_1();
        }
    }

    /**
     * Upgrade to version 6.14.0 - AI Chatbot Context Persistence
     * Adds columns to mld_chat_conversations table:
     * - collected_info, search_context, shown_properties, active_property_id
     * - conversation_state, agent_assigned_id, agent_assigned_at, agent_connected_at
     * - property_interest, lead_score
     * Also adds settings for context features.
     *
     * @since 6.14.0
     */
    private static function upgrade_to_6_14_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.14.0 upgrade - AI Chatbot Context Persistence');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.14.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_14_0')) {
                $result = mld_update_to_6_14_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.14.0 upgrade completed - context persistence enabled');
                    } else {
                        error_log('MLD Upgrader: 6.14.0 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.14.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.14.2 - Contact/Tour Form Schema Update
     * Adds columns to mld_form_submissions table for tour scheduling:
     * - property_address, tour_type, preferred_date, preferred_time
     *
     * @since 6.14.2
     */
    private static function upgrade_to_6_14_2() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.14.2 upgrade - Contact/Tour Form Schema Update');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.14.2.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_14_2')) {
                $result = mld_update_to_6_14_2();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.14.2 upgrade completed - form_submissions table updated');
                    } else {
                        error_log('MLD Upgrader: 6.14.2 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.14.2 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.16.0 - CMA Enhancement Features
     * Creates mld_cma_saved_sessions table for:
     * - Save/load CMA sessions for logged-in users
     * - ARV (After Repair Value) adjustments tracking
     * - PDF generation path storage
     *
     * @since 6.16.0
     */
    private static function upgrade_to_6_16_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.16.0 upgrade - CMA Enhancement Features');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.16.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_16_0')) {
                $result = mld_update_to_6_16_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.16.0 upgrade completed - CMA saved sessions table created');
                    } else {
                        error_log('MLD Upgrader: 6.16.0 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.16.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.17.0 - Standalone CMA Feature
     * Adds columns to mld_cma_saved_sessions for standalone CMAs:
     * - is_standalone: Flag for CMAs created without MLS listing
     * - standalone_slug: URL slug for standalone CMA pages (/cma/{slug}/)
     *
     * @since 6.17.0
     */
    private static function upgrade_to_6_17_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.17.0 upgrade - Standalone CMA Feature');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.17.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_17_0')) {
                $result = mld_update_to_6_17_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.17.0 upgrade completed - Standalone CMA columns added');
                    } else {
                        error_log('MLD Upgrader: 6.17.0 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.17.0 update file not found at ' . $update_file);
            }
        }

        // Also run the CMA Session Database upgrade method for schema changes
        if (class_exists('MLD_CMA_Session_Database')) {
            MLD_CMA_Session_Database::check_and_upgrade();
        }

        // Schedule rewrite rules flush for next page load
        // Do NOT call flush_rewrite_rules() directly here as $wp_rewrite is not initialized yet
        // The flush will happen on the next page load via mld_maybe_flush_rewrite_rules() on 'init' hook
        set_transient('mld_flush_rewrite_rules', true, 60);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.17.0 scheduled rewrite rules flush for /cma/ routes');
        }
    }

    /**
     * Upgrade to version 6.20.0 - Historical CMA Tracking
     * Creates mld_cma_value_history table for:
     * - Tracking property valuations over time
     * - Trend visualization on property pages
     * - Re-run CMA with previous parameters
     *
     * @since 6.20.0
     */
    private static function upgrade_to_6_20_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.20.0 upgrade - Historical CMA Tracking');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.20.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_20_0')) {
                $result = mld_update_to_6_20_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.20.0 upgrade completed - CMA value history table created');
                    } else {
                        error_log('MLD Upgrader: 6.20.0 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.20.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.20.3 - Market Conditions Fixes
     *
     * Fixes applied:
     * - Property type normalization (Single Family Residence -> Residential)
     * - JavaScript renderMarketConditions success check fix
     * - Clear market conditions cache for fresh data
     *
     * @since 6.20.3
     */
    private static function upgrade_to_6_20_3() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.20.3 upgrade - Market Conditions Fixes');
        }

        // Clear market conditions transient cache so property type normalization takes effect
        global $wpdb;

        // Delete all market conditions transients
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_market_conditions_%'
             OR option_name LIKE '_transient_timeout_mld_market_conditions_%'"
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Upgrader: 6.20.3 cleared {$deleted} market conditions transients");
        }

        // Clear WordPress object cache
        wp_cache_flush();

        // Delete any mld_mc_ transients as well (alternate cache key format)
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_mc_%'
             OR option_name LIKE '_transient_timeout_mld_mc_%'"
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.20.3 upgrade completed - Market conditions cache cleared');
        }
    }

    /**
     * Upgrade to version 6.20.12 - Chatbot Notification Table Schema Fix
     *
     * Ensures chatbot notification table has all required columns.
     * Fixes missing notification_data column that caused email notifications to fail.
     * This is a defensive migration for production sites that may have missed earlier updates.
     *
     * @since 6.20.12
     */
    private static function upgrade_to_6_20_12() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.20.12] Starting chatbot table schema verification');
        }

        // Load the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.20.12.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_20_12')) {
                $result = mld_update_to_6_20_12();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.20.12] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.20.12] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.21.0 - Universal Contact Form System
     * Creates mld_contact_forms table for the new contact form builder:
     * - Custom field builder with drag-and-drop interface
     * - Multiple forms with unique shortcodes [mld_contact_form id="X"]
     * - Per-form notification settings (admin, user confirmation, additional recipients)
     * - Global Customizer styling via WordPress Customizer
     * - Adds form_id and form_data columns to mld_form_submissions table
     *
     * @since 6.21.0
     */
    private static function upgrade_to_6_21_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.21.0] Starting Universal Contact Form System migration');
        }

        // Load the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.21.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_21_0')) {
                $result = mld_update_to_6_21_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.21.0] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.21.0] Update file not found: ' . $update_file);
            }
        }

        // Initialize Customizer if not already loaded
        if (class_exists('MLD_Contact_Form_Customizer')) {
            MLD_Contact_Form_Customizer::get_instance();
        }
    }

    /**
     * Upgrade to version 6.24.0 - File Uploads + Form Templates
     * Adds new tables:
     * - wp_mld_form_uploads for file upload tracking
     * - wp_mld_form_templates for pre-built form templates
     * Inserts 6 default real estate form templates
     *
     * @since 6.24.0
     */
    private static function upgrade_to_6_24_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.24.0] Starting File Uploads + Form Templates migration');
        }

        // Load the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.24.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_24_0')) {
                $result = mld_update_to_6_24_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.24.0] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.24.0] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.27.0 - Lead Capture Gate Form for Chatbot
     * Adds lead_gate_enabled setting to require user info before chatting
     *
     * @since 6.27.0
     */
    private static function upgrade_to_6_27_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.27.0] Starting Lead Capture Gate Form migration');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.27.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_27_0')) {
                $result = mld_update_to_6_27_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.27.0] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.27.0] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.69.0 - Open House Sign-In System
     * Creates database tables for agent open house management and attendee sign-ins.
     *
     * @since 6.69.0
     */
    private static function upgrade_to_6_69_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.69.0] Starting Open House Sign-In System migration');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.69.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_69_0')) {
                $result = mld_update_to_6_69_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.69.0] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.69.0] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.70.0 - Open House Agent Detection & CRM Integration
     * Adds agent visitor detection fields and priority scoring to attendees table.
     *
     * @since 6.70.0
     */
    private static function upgrade_to_6_70_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.70.0] Starting Open House Agent Detection & CRM Integration migration');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.70.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_70_0')) {
                $result = mld_update_to_6_70_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.70.0] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.70.0] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.70.1 - Open House Schema Fix
     * Adds missing updated_at column to attendees table that dbDelta may have skipped.
     *
     * @since 6.70.1
     */
    private static function upgrade_to_6_70_1() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Upgrade 6.70.1] Starting Open House Schema Fix migration');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.70.1.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_70_1')) {
                $result = mld_update_to_6_70_1();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Upgrade 6.70.1] Update function result: ' . ($result ? 'success' : 'failed'));
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Upgrade 6.70.1] Update file not found: ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.11.46 - Comprehensive Chatbot Tables Fix
     * Adds missing columns to ALL chatbot tables (settings, sessions, messages)
     *
     * @since 6.11.46
     */
    private static function upgrade_to_6_11_46() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.46 upgrade - Comprehensive Chatbot Tables Fix');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.11.46.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_11_46')) {
                $result = mld_update_to_6_11_46();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.11.46 upgrade completed - all chatbot tables fixed');
                    } else {
                        error_log('MLD Upgrader: 6.11.46 upgrade encountered issues');
                    }
                }
            }
        }
    }

    /**
     * Upgrade to version 6.11.47 - COMPREHENSIVE FIX for ALL 8 Chatbot Tables
     * Ensures all columns exist in all chatbot tables:
     * 1. mld_chat_conversations (18 columns)
     * 2. mld_chat_messages (15 columns)
     * 3. mld_chat_sessions (13 columns)
     * 4. mld_chat_settings (9 columns)
     * 5. mld_chat_knowledge_base (14 columns)
     * 6. mld_chat_faq_library (12 columns)
     * 7. mld_chat_admin_notifications (10 columns)
     * 8. mld_chat_email_summaries (13 columns)
     *
     * @since 6.11.47
     */
    private static function upgrade_to_6_11_47() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.47 upgrade - COMPREHENSIVE FIX for ALL 8 Chatbot Tables');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.11.47.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_11_47')) {
                $result = mld_update_to_6_11_47();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.11.47 upgrade completed - ALL 8 chatbot tables verified/fixed');
                    } else {
                        error_log('MLD Upgrader: 6.11.47 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.11.47 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.11.48 - CRITICAL FIX for Email Notification Columns
     * Adds missing columns that the PHP code actually uses but were missing from schema:
     * - notification_data in mld_chat_admin_notifications
     * - key_topics, properties_mentioned, ai_provider, ai_model, delivery_status in mld_chat_email_summaries
     *
     * @since 6.11.48
     */
    private static function upgrade_to_6_11_48() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.48 upgrade - CRITICAL FIX for Email Notification Columns');
        }

        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.11.48.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_11_48')) {
                $result = mld_update_to_6_11_48();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.11.48 upgrade completed - Email notification columns added');
                    } else {
                        error_log('MLD Upgrader: 6.11.48 upgrade encountered issues');
                    }
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.11.48 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.11.44 - Fix Chatbot Settings Table Schema
     * Adds missing columns to wp_mld_chat_settings table that were
     * not present in the ensure_chatbot_tables() fallback function.
     *
     * @since 6.11.44
     */
    private static function upgrade_to_6_11_44() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.44 upgrade - Fix Chatbot Settings Table Schema');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.11.44.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_11_44')) {
                $result = mld_update_to_6_11_44();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.11.44 upgrade completed successfully - chatbot settings schema fixed');
                    } else {
                        error_log('MLD Upgrader: 6.11.44 upgrade encountered issues');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.11.44 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.11.44 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 5.2.0 - CMA Intelligence System
     * Creates new tables for CMA tracking and initializes default settings
     */
    private static function upgrade_to_5_2_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 5.2.0 upgrade - CMA Intelligence System');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-5.2.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_5_2_0')) {
                $result = mld_update_to_5_2_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 5.2.0 upgrade completed successfully');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 5.2.0 upgrade encountered issues');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 5.2.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 5.2.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.6.0 - AI Chatbot System
     * Creates 8 new tables for chatbot, conversations, sessions, notifications,
     * knowledge base, and FAQ management with multi-AI provider support
     */
    private static function upgrade_to_6_6_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.6.0 upgrade - AI Chatbot System');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.6.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_6_0')) {
                $result = mld_update_to_6_6_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.6.0 upgrade completed successfully');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.6.0 upgrade encountered issues');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.6.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.6.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.6.1 - Chatbot Database Tables
     * Creates all chatbot database tables for installations/upgrades
     */
    private static function upgrade_to_6_6_1() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.6.1 upgrade - Chatbot Database Tables');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.6.1.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_6_1')) {
                $result = mld_update_to_6_6_1();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.6.1 upgrade completed successfully - All chatbot tables created');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.6.1 upgrade encountered issues creating chatbot tables');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.6.1 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.6.1 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.7.0 - Enhanced Chatbot with Data References
     * Adds conversation states, data references, and response caching
     */
    private static function upgrade_to_6_7_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.7.0 upgrade - Enhanced Chatbot System');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.7.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_7_0')) {
                $result = mld_update_to_6_7_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.7.0 upgrade completed successfully - Enhanced chatbot features installed');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.7.0 upgrade encountered issues');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.7.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.7.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Run pre-upgrade checks
     *
     * @return array Check results
     */
    private function run_pre_upgrade_checks() {
        $checks = array();

        // Check WordPress version
        $wp_version = get_bloginfo('version');
        $checks['wordpress_version'] = array(
            'required' => '5.0',
            'current' => $wp_version,
            'passed' => version_compare($wp_version, '5.0', '>=')
        );

        // Check PHP version
        $php_version = PHP_VERSION;
        $checks['php_version'] = array(
            'required' => '7.4',
            'current' => $php_version,
            'passed' => version_compare($php_version, '7.4', '>=')
        );

        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);
        $required_bytes = 128 * 1024 * 1024; // 128MB
        $checks['memory_limit'] = array(
            'required' => '128M',
            'current' => $memory_limit,
            'passed' => $memory_bytes >= $required_bytes
        );

        // Check if Bridge MLS plugin is active
        $checks['bridge_mls_active'] = array(
            'required' => true,
            'current' => $this->is_bridge_mls_active(),
            'passed' => $this->is_bridge_mls_active()
        );

        // Check database permissions
        $checks['database_permissions'] = array(
            'required' => true,
            'current' => $this->check_database_permissions(),
            'passed' => $this->check_database_permissions()
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Pre-upgrade checks completed');
        }
        return $checks;
    }

    /**
     * Run database migrations
     *
     * @param string $from_version Previous version
     * @return array Migration results
     */
    private function run_database_migrations($from_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Starting database migrations');
        }

        $migration_results = array();

        try {
            // Run performance indexes migration
            $migration_results['performance_indexes'] = $this->run_performance_indexes_migration();

            // Run all database migrations via migrator if available
            if ($this->database_migrator) {
                $migration_results['migrator'] = $this->database_migrator->run_all_migrations($from_version, self::CURRENT_VERSION);
            }

            // Run table structure updates
            $migration_results['table_updates'] = $this->run_table_structure_updates($from_version);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Database migrations completed successfully');
            }
            return $migration_results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Database migration failed - ' . $e->getMessage());
            }
            throw new Exception('Database migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Run performance indexes migration
     *
     * @return array Migration results
     */
    private function run_performance_indexes_migration() {
        $results = array();

        try {
            // Load and run MLS Display performance indexes
            if (file_exists(MLD_PLUGIN_PATH . '../../../database/migrations/mls_display_performance_indexes.php')) {
                require_once MLD_PLUGIN_PATH . '../../../database/migrations/mls_display_performance_indexes.php';
                if (class_exists('MLS_Display_Performance_Indexes')) {
                    MLS_Display_Performance_Indexes::run();
                    $results['mls_display_indexes'] = 'created';
                }
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Performance indexes migration completed');
            }
            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Performance indexes migration failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Verify and repair all database tables
     * Uses the database verification tool to ensure all required tables exist
     *
     * @return array Verification and repair results
     */
    private function verify_and_repair_tables() {
        $results = array(
            'status' => 'success',
            'tables_checked' => 0,
            'tables_created' => 0,
            'tables_existing' => 0,
            'errors' => array()
        );

        try {
            // Load the database verification class
            $verify_file = MLD_PLUGIN_PATH . 'includes/class-mld-database-verify.php';
            if (file_exists($verify_file)) {
                require_once $verify_file;

                if (class_exists('MLD_Database_Verify')) {
                    $verifier = MLD_Database_Verify::get_instance();
                    $repair_results = $verifier->repair_tables();

                    foreach ($repair_results as $table => $result) {
                        $results['tables_checked']++;
                        if ($result['status'] === 'created') {
                            $results['tables_created']++;
                        } elseif ($result['status'] === 'already_exists') {
                            $results['tables_existing']++;
                        } elseif ($result['status'] === 'failed') {
                            $results['errors'][] = "Failed to create table: {$table}";
                        }
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("MLD Upgrader: Table verification complete - {$results['tables_created']} created, {$results['tables_existing']} existing");
                    }
                }
            } else {
                $results['status'] = 'skipped';
                $results['message'] = 'Database verification tool not found';
            }

        } catch (Exception $e) {
            $results['status'] = 'error';
            $results['errors'][] = $e->getMessage();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Table verification failed - ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Run table structure updates
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function run_table_structure_updates($from_version) {
        global $wpdb;
        $results = array();

        try {
            // Check and create any missing tables
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();

            // Ensure notification queue table exists with all features
            $table_name = $wpdb->prefix . 'mld_notification_queue';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

            if (!$table_exists) {
                $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    saved_search_id BIGINT UNSIGNED NOT NULL,
                    listing_id VARCHAR(50) NOT NULL,
                    match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL,
                    listing_data JSON,
                    reason_blocked ENUM('quiet_hours', 'daily_limit', 'rate_limited', 'bulk_import', 'system') DEFAULT 'system',
                    retry_after DATETIME NOT NULL,
                    retry_attempts INT DEFAULT 0,
                    max_attempts INT DEFAULT 3,
                    status ENUM('queued', 'processing', 'sent', 'failed', 'expired') DEFAULT 'queued',
                    processed_at DATETIME,
                    error_message TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_retry (retry_after, status),
                    KEY idx_user (user_id),
                    KEY idx_search (saved_search_id),
                    KEY idx_listing (listing_id),
                    KEY idx_status (status),
                    KEY idx_created (created_at)
                ) $charset_collate";

                dbDelta($sql);
                $results['notification_queue'] = 'created';
            }

            return $results;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Table structure update failed - ' . $e->getMessage());
            }
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Clear all caches
     *
     * @return array Cache clearing results
     */
    private function clear_all_caches() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Clearing all caches');
        }

        $cache_results = array();

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            $cache_results['object_cache'] = wp_cache_flush();
        }

        // Clear transients
        $this->clear_plugin_transients();
        $cache_results['transients'] = true;

        // Clear rewrite rules - use transient approach to ensure proper timing
        // This ensures all rules from init hooks are registered before flushing
        // The flush will happen on the next page load via mld_maybe_flush_rewrite_rules()
        set_transient('mld_flush_rewrite_rules', true, 60);
        $cache_results['rewrite_rules'] = true;

        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $cache_results['opcache'] = true;
        }

        // Clear plugin-specific caches
        $cache_results['plugin_cache'] = $this->clear_plugin_cache();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Cache clearing completed');
        }
        return $cache_results;
    }

    /**
     * Update plugin options
     *
     * @param string $from_version Previous version
     * @return array Update results
     */
    private function update_plugin_options($from_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Updating plugin options');
        }

        $option_results = array();

        // Update default settings for new features
        $option_results['settings_update'] = $this->update_default_settings($from_version);

        // Migrate old option names if needed
        $option_results['option_migration'] = $this->migrate_legacy_options($from_version);

        // Update URL configurations
        $option_results['url_update'] = $this->update_url_configurations();

        // Update notification settings
        $option_results['notification_update'] = $this->update_notification_settings();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Plugin options updated');
        }
        return $option_results;
    }

    /**
     * Run compatibility fixes
     *
     * @param string $from_version Previous version
     * @return array Compatibility results
     */
    private function run_compatibility_fixes($from_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running compatibility fixes');
        }

        $compatibility_results = array();

        // Fix data structure changes
        $compatibility_results['data_fixes'] = $this->fix_data_structures($from_version);

        // Update template compatibility
        $compatibility_results['template_fixes'] = $this->fix_template_compatibility($from_version);

        // Fix JavaScript compatibility
        $compatibility_results['js_fixes'] = $this->fix_javascript_compatibility($from_version);

        // Fix CSS compatibility
        $compatibility_results['css_fixes'] = $this->fix_css_compatibility($from_version);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Compatibility fixes completed');
        }
        return $compatibility_results;
    }

    /**
     * Update version number
     *
     * @return bool Success status
     */
    private function update_version_number() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Updating version number to ' . self::CURRENT_VERSION);
        }

        $result = update_option(self::VERSION_OPTION, self::CURRENT_VERSION);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: Version number updated successfully');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: Failed to update version number');
                }
            }
        }

        return $result;
    }

    /**
     * Store migration history
     *
     * @param string $from_version Previous version
     * @param string $to_version New version
     * @param array $results Migration results
     */
    private function store_migration_history($from_version, $to_version, $results) {
        $history = get_option(self::MIGRATION_HISTORY_OPTION, array());

        $migration_record = array(
            'from_version' => $from_version,
            'to_version' => $to_version,
            'migrated_at' => current_time('mysql'),
            'duration' => $results['duration'] ?? 0,
            'success' => !isset($results['error']),
            'results' => $results
        );

        array_unshift($history, $migration_record);

        // Keep only last 10 migration records
        $history = array_slice($history, 0, 10);

        update_option(self::MIGRATION_HISTORY_OPTION, $history);
    }

    /**
     * Check if Bridge MLS plugin is active
     *
     * @return bool True if active
     */
    private function is_bridge_mls_active() {
        return is_plugin_active('bridge-mls-extractor-pro/bridge-mls-extractor-pro.php') ||
               class_exists('Bridge_MLS_Extractor_Pro');
    }

    /**
     * Check database permissions
     *
     * @return bool True if permissions are adequate
     */
    private function check_database_permissions() {
        global $wpdb;

        try {
            // Test if we can create/modify tables
            $test_table = $wpdb->prefix . 'mld_upgrade_test';

            $wpdb->query("CREATE TEMPORARY TABLE {$test_table} (id INT AUTO_INCREMENT PRIMARY KEY, test_col VARCHAR(50))");
            $wpdb->query("INSERT INTO {$test_table} (test_col) VALUES ('test')");
            $wpdb->query("ALTER TABLE {$test_table} ADD COLUMN test_col2 VARCHAR(50)");
            $wpdb->query("DROP TEMPORARY TABLE {$test_table}");

            return true;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Database permission check failed - ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Convert memory limit to bytes
     *
     * @param string $limit Memory limit string
     * @return int Bytes
     */
    private function convert_to_bytes($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $number = (int) $limit;

        switch ($last) {
            case 'g':
                $number *= 1024;
            case 'm':
                $number *= 1024;
            case 'k':
                $number *= 1024;
        }

        return $number;
    }

    /**
     * Clear plugin transients
     */
    private function clear_plugin_transients() {
        global $wpdb;

        // Delete all MLD transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%' OR option_name LIKE '_transient_timeout_mld_%'");
    }

    /**
     * Clear plugin-specific cache
     *
     * @return bool Success status
     */
    private function clear_plugin_cache() {
        // Clear any plugin-specific cache here
        delete_option('mld_performance_cache');
        delete_option('mld_query_cache');
        delete_option('mld_asset_cache');

        return true;
    }

    /**
     * Update default settings
     *
     * @param string $from_version Previous version
     * @return bool Success status
     */
    private function update_default_settings($from_version) {
        // Add any new default settings based on version changes
        $current_settings = get_option('mld_general_settings', array());

        // Version-specific setting updates
        if (version_compare($from_version, '4.8.0', '<')) {
            // Add new settings for version 4.8.0
            $current_settings['upgrade_auto_apply'] = true;
            $current_settings['cache_clear_on_upgrade'] = true;
        }

        return update_option('mld_general_settings', $current_settings);
    }

    /**
     * Migrate legacy options
     *
     * @param string $from_version Previous version
     * @return array Migration results
     */
    private function migrate_legacy_options($from_version) {
        $migration_results = array();

        // Add option migration logic here based on version changes
        if (version_compare($from_version, '4.0.0', '<')) {
            // Migrate old option names
            $old_value = get_option('mld_old_option_name');
            if ($old_value !== false) {
                update_option('mld_new_option_name', $old_value);
                delete_option('mld_old_option_name');
                $migration_results['old_option_name'] = 'migrated';
            }
        }

        return $migration_results;
    }

    /**
     * Update URL configurations
     *
     * @return bool Success status
     */
    private function update_url_configurations() {
        // Ensure proper URL configurations are set
        $url_settings = get_option('mld_url_settings', array());

        // Set defaults if not already configured
        if (empty($url_settings['search_page_slug'])) {
            $url_settings['search_page_slug'] = 'search';
        }

        if (empty($url_settings['property_page_slug'])) {
            $url_settings['property_page_slug'] = 'property';
        }

        return update_option('mld_url_settings', $url_settings);
    }

    /**
     * Update notification settings
     *
     * @return bool Success status
     */
    private function update_notification_settings() {
        // Ensure notification settings are properly configured
        $notification_settings = get_option('mld_notification_settings', array());

        // Set defaults for new notification features
        if (!isset($notification_settings['queue_enabled'])) {
            $notification_settings['queue_enabled'] = true;
        }

        if (!isset($notification_settings['retry_failed'])) {
            $notification_settings['retry_failed'] = true;
        }

        return update_option('mld_notification_settings', $notification_settings);
    }

    /**
     * Fix data structures
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_data_structures($from_version) {
        $fix_results = array();

        // Add data structure fixes based on version changes
        if (version_compare($from_version, '4.5.0', '<')) {
            // Fix saved search data structure
            $fix_results['saved_searches'] = $this->fix_saved_search_structure();
        }

        return $fix_results;
    }

    /**
     * Fix template compatibility
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_template_compatibility($from_version) {
        // Template compatibility fixes can be added here
        return array('templates' => 'no_fixes_needed');
    }

    /**
     * Fix JavaScript compatibility
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_javascript_compatibility($from_version) {
        // JavaScript compatibility fixes can be added here
        return array('javascript' => 'no_fixes_needed');
    }

    /**
     * Fix CSS compatibility
     *
     * @param string $from_version Previous version
     * @return array Fix results
     */
    private function fix_css_compatibility($from_version) {
        // CSS compatibility fixes can be added here
        return array('css' => 'no_fixes_needed');
    }

    /**
     * Fix saved search structure
     *
     * @return bool Success status
     */
    private function fix_saved_search_structure() {
        // Add saved search structure fixes here
        return true;
    }

    /**
     * Get upgrade status
     *
     * @return array Upgrade status
     */
    public function get_upgrade_status() {
        return get_option(self::UPGRADE_STATUS_OPTION, array('status' => 'none'));
    }

    /**
     * Get migration history
     *
     * @return array Migration history
     */
    public function get_migration_history() {
        return get_option(self::MIGRATION_HISTORY_OPTION, array());
    }

    /**
     * Force upgrade (for manual execution)
     *
     * @return array Upgrade results
     */
    public function force_upgrade() {
        // Remove version check for force upgrade
        return $this->run_upgrade();
    }

    /**
     * Rollback to previous version (emergency use)
     *
     * @param string $target_version Version to rollback to
     * @return array Rollback results
     */
    public function rollback($target_version) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Upgrader: Starting rollback to version {$target_version}");
        }

        try {
            // Update version number
            update_option(self::VERSION_OPTION, $target_version);

            // Clear caches
            $this->clear_all_caches();

            // Log rollback
            $history = get_option(self::MIGRATION_HISTORY_OPTION, array());
            array_unshift($history, array(
                'from_version' => self::CURRENT_VERSION,
                'to_version' => $target_version,
                'migrated_at' => current_time('mysql'),
                'type' => 'rollback',
                'success' => true
            ));
            update_option(self::MIGRATION_HISTORY_OPTION, array_slice($history, 0, 10));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Upgrader: Rollback to {$target_version} completed");
            }

            return array(
                'success' => true,
                'version' => $target_version,
                'message' => "Rolled back to version {$target_version}"
            );

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Rollback failed - ' . $e->getMessage());
            }
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Upgrade to version 4.4.6
     * Adds new columns to notification preferences table
     */
    private static function upgrade_to_4_4_6() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'mld_notification_preferences';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

        if (!$table_exists) {
            // Table doesn't exist, create it with all columns
            require_once plugin_dir_path(__FILE__) . 'instant-notifications/class-mld-database-installer.php';
            MLD_Database_Installer::install();
            return;
        }

        // Table exists, check for new columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");
        $existing_columns = [];
        foreach ($columns as $column) {
            $existing_columns[] = $column->Field;
        }

        // Add missing columns
        $columns_to_add = [
            'quiet_hours_enabled' => "ADD COLUMN quiet_hours_enabled BOOLEAN DEFAULT TRUE AFTER instant_sms_notifications",
            'quiet_hours_start' => "ADD COLUMN quiet_hours_start TIME DEFAULT '22:00:00' AFTER quiet_hours_enabled",
            'quiet_hours_end' => "ADD COLUMN quiet_hours_end TIME DEFAULT '08:00:00' AFTER quiet_hours_start",
            'throttling_enabled' => "ADD COLUMN throttling_enabled BOOLEAN DEFAULT TRUE AFTER quiet_hours_end"
        ];

        foreach ($columns_to_add as $column_name => $sql_fragment) {
            if (!in_array($column_name, $existing_columns)) {
                $sql = "ALTER TABLE $table_name $sql_fragment";
                $wpdb->query($sql);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($wpdb->last_error) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD Upgrader: Failed to add column '$column_name': " . $wpdb->last_error);
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("MLD Upgrader: Successfully added column '$column_name'");
                        }
                    }
                }
            }
        }

        // Update default value for instant_email_notifications to TRUE
        $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN instant_email_notifications BOOLEAN DEFAULT TRUE");

        // Update existing NULL values to TRUE for email notifications
        $wpdb->query("UPDATE $table_name SET instant_email_notifications = TRUE WHERE instant_email_notifications IS NULL");

        // Initialize global settings if they don't exist
        $global_settings = [
            'mld_global_quiet_hours_enabled' => true,
            'mld_global_throttling_enabled' => true,
            'mld_override_user_preferences' => false,
            'mld_instant_bulk_threshold' => 100,
            'mld_instant_quiet_hours_start' => '22:00',
            'mld_instant_quiet_hours_end' => '06:00',
            'mld_default_quiet_start' => '22:00',
            'mld_default_quiet_end' => '06:00',
            'mld_default_daily_limit' => 50,
            'mld_throttle_window_minutes' => 60,
            'mld_max_notifications_per_window' => 10,
            'mld_enable_bulk_import_throttle' => true,
            'mld_bulk_import_threshold' => 50
        ];

        foreach ($global_settings as $option_name => $default_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $default_value);
            }
        }

        // Update instant notifications database version
        update_option('mld_instant_notifications_db_version', '1.1.0');
    }

    /**
     * Upgrade to version 4.4.7
     * Adds notification queue table
     */
    private static function upgrade_to_4_4_7() {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        // Create notification queue table
        $table_name = $wpdb->prefix . 'mld_notification_queue';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            saved_search_id BIGINT UNSIGNED NOT NULL,
            listing_id VARCHAR(50) NOT NULL,
            match_type ENUM('new_listing', 'price_drop', 'price_reduced', 'price_increase', 'price_increased', 'status_change', 'back_on_market', 'open_house', 'sold', 'coming_soon', 'property_updated', 'daily_digest', 'weekly_digest', 'hourly_digest') NOT NULL,
            listing_data JSON,
            reason_blocked ENUM('quiet_hours', 'daily_limit', 'rate_limited', 'bulk_import', 'system') DEFAULT 'system',
            retry_after DATETIME NOT NULL,
            retry_attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            status ENUM('queued', 'processing', 'sent', 'failed', 'expired') DEFAULT 'queued',
            processed_at DATETIME,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_retry (retry_after, status),
            KEY idx_user (user_id),
            KEY idx_search (saved_search_id),
            KEY idx_listing (listing_id),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) $charset_collate";

        dbDelta($sql);

        // Schedule new cron jobs if not already scheduled
        if (!wp_next_scheduled('mld_process_notification_queue')) {
            wp_schedule_event(time(), 'mld_every_15_minutes', 'mld_process_notification_queue');
        }

        if (!wp_next_scheduled('mld_cleanup_notification_queue')) {
            wp_schedule_event(time(), 'daily', 'mld_cleanup_notification_queue');
        }

        // Update instant notifications database version
        update_option('mld_instant_notifications_db_version', '1.2.0');
    }

    /**
     * Upgrade to version 4.5.36
     * Clear cities cache and force regeneration for pre-loaded optimization
     */
    private static function upgrade_to_4_5_36() {
        // Clear the cities cache transient to force regeneration
        delete_transient('mld_available_cities_list');

        // Log the upgrade
        if (class_exists('MLD_Logger')) {
            MLD_Logger::info('Upgraded to version 4.5.36 - Cities cache cleared for pre-loaded optimization');
        }

        // Clear any browser session storage keys (will be regenerated on next page load)
        // This is handled client-side automatically when cities list changes

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Version 4.5.36 - Cleared cities cache for pre-loaded optimization');
        }
    }

    /**
     * Upgrade to version 4.5.37
     * Clear cache after data provider instantiation fix
     */
    private static function upgrade_to_4_5_37() {
        // Clear the cities cache transient to force regeneration with fixed data provider
        delete_transient('mld_available_cities_list');

        // Log the upgrade
        if (class_exists('MLD_Logger')) {
            MLD_Logger::info('Upgraded to version 4.5.37 - Data provider instantiation fixed');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Version 4.5.37 - Fixed data provider instantiation, cache cleared');
        }
    }

    /**
     * Upgrade to version 4.5.47
     * Ensure all notification cron jobs are properly scheduled
     */
    private static function upgrade_to_4_5_47() {
        // Schedule saved search cron jobs if not already scheduled
        $init_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-saved-search-init.php';
        if (file_exists($init_path)) {
            require_once $init_path;

            // Load the cron class
            $cron_path = plugin_dir_path(__FILE__) . 'saved-searches/class-mld-saved-search-cron.php';
            if (file_exists($cron_path)) {
                require_once $cron_path;

                // Schedule all cron events
                if (class_exists('MLD_Saved_Search_Cron')) {
                    MLD_Saved_Search_Cron::schedule_events();
                }
            }
        }

        // Create notification analytics table if missing
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_notification_analytics';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

        if (!$table_exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                notification_type VARCHAR(50) NOT NULL,
                channel VARCHAR(20) NOT NULL,
                listing_id VARCHAR(50),
                sent_at DATETIME NOT NULL,
                delivery_status ENUM('sent', 'failed', 'bounced') DEFAULT 'sent',
                error_message TEXT,
                response_time_ms INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user (user_id),
                KEY idx_type (notification_type),
                KEY idx_channel (channel),
                KEY idx_sent_at (sent_at),
                KEY idx_listing (listing_id)
            ) $charset_collate";

            dbDelta($sql);
        }

        // Log the upgrade
        if (class_exists('MLD_Logger')) {
            MLD_Logger::info('Upgraded to version 4.5.47 - All cron jobs scheduled and analytics table created');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Version 4.5.47 - Ensured all notification cron jobs are scheduled');
        }
    }

    /**
     * Upgrade to version 6.8.0 - Training System and Custom System Prompts
     * Creates training examples table and adds system prompt customization
     */
    private static function upgrade_to_6_8_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.8.0 upgrade - Training System and Custom System Prompts');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.8.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_8_0')) {
                $result = mld_update_to_6_8_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.8.0 upgrade completed successfully');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.8.0 upgrade encountered issues');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.8.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.8.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.9.0 - A/B Testing & Advanced Training Features
     * Creates prompt variants tables and adds extended prompt variables
     */
    private static function upgrade_to_6_9_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.9.0 upgrade - A/B Testing & Advanced Training Features');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.9.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_9_0')) {
                $result = mld_update_to_6_9_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.9.0 upgrade completed successfully');
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('MLD Upgrader: 6.9.0 upgrade encountered issues');
                        }
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.9.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.9.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.10.6 - CMA Calculation Refinements
     *
     * This upgrade implements FHA/Fannie Mae style adjustment calculations:
     * - Percentage-based adjustments that scale with property value
     * - FHA-style warning thresholds (10% individual, 15% net, 25% gross)
     * - Hard caps on individual adjustments (20% max)
     * - Year-built adjustment capped at 20 years
     * - Diminishing returns for square footage adjustments
     * - Reduced road type premium (25% -> 5%)
     * - Improved bounds on garage, bedroom, bathroom adjustments
     *
     * @since 6.10.6
     */
    private static function upgrade_to_6_10_6() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.10.6 upgrade - CMA Calculation Refinements');
        }

        global $wpdb;

        // Clear all CMA-related caches to ensure new calculations take effect
        $cache_cleared = 0;

        // Clear market data transients
        $cache_cleared += $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_market_%'
             OR option_name LIKE '_transient_timeout_mld_market_%'"
        );

        // Clear CMA comparable cache table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mld_cma_comparable_cache'")) {
            $cache_cleared += $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mld_cma_comparable_cache");
        }

        // Clear CMA comparables cache table
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mld_cma_comparables_cache'")) {
            $cache_cleared += $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mld_cma_comparables_cache");
        }

        // Clear search cache
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mld_search_cache'")) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}mld_search_cache WHERE expiration < NOW()");
        }

        // Update road type discount to new default (5% instead of 25%)
        $current_road_discount = get_option('mld_cma_road_type_discount');
        if ($current_road_discount === false || $current_road_discount == 25) {
            update_option('mld_cma_road_type_discount', 5);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: Updated road type discount from 25% to 5%');
            }
        }

        // Clear any CMA override options that might be using old fixed values
        // Only clear if they match the old extreme defaults
        $old_defaults = array(
            'mld_cma_override_garage_first' => 100000,
            'mld_cma_override_garage_additional' => 50000,
            'mld_cma_override_bedroom' => 75000,
            'mld_cma_override_bathroom' => 25000,
            'mld_cma_override_year_built_rate' => 25000
        );

        foreach ($old_defaults as $option_name => $old_value) {
            $current_value = get_option($option_name);
            if ($current_value !== false && floatval($current_value) == $old_value) {
                delete_option($option_name);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Upgrader: Cleared old default override for {$option_name}");
                }
            }
        }

        // Flush WordPress object cache
        wp_cache_flush();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MLD Upgrader: 6.10.6 upgrade completed - CMA caches cleared ({$cache_cleared} items), road type discount updated");
        }
    }

    /**
     * Upgrade to version 6.10.7 - Code Quality Fixes
     *
     * This is a code-only update with no database changes:
     * - Fixed wpdb::prepare() warnings in 8 chatbot-related files
     * - 13 instances of missing placeholders corrected
     *
     * @since 6.10.7
     */
    private static function upgrade_to_6_10_7() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.10.7 upgrade - Code quality fixes');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.10.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_10_0')) {
                $result = mld_update_to_6_10_0();

                if ($result && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.10.7 upgrade completed successfully');
                }
            }
        }

        // Update version tracking
        update_option('mld_db_version', '6.10.7');
    }

    /**
     * Upgrade to version 6.11.7 - Map Pin Reliability Improvements
     *
     * This upgrade includes JavaScript/CSS changes for map reliability:
     * - Request queue system to prevent lost filter changes during rapid interactions
     * - Adaptive throttling based on device/connection (200ms desktop, 300ms mobile, 500ms slow)
     * - Loading state indicators (spinner and error messages)
     * - Network retry with exponential backoff
     * - Geolocation timeout recovery (5s fallback)
     * - Adaptive debounce (400ms mobile / 250ms desktop)
     *
     * @since 6.11.7
     */
    private static function upgrade_to_6_11_7() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.7 upgrade - Map pin reliability improvements');
        }

        // Clear all caches to ensure new JavaScript/CSS takes effect
        global $wpdb;

        // Clear transients related to map/listings
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear search cache
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}mld_search_cache'")) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}mld_search_cache WHERE expiration < NOW()");
        }

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Set transient to flush rewrite rules on next page load
        set_transient('mld_flush_rewrite_rules', true, 60);

        // Update version tracking
        update_option('mld_db_version', '6.11.7');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.7 upgrade completed - Caches cleared for map reliability changes');
        }
    }

    /**
     * Upgrade to version 6.11.8 - SavedSearchRepository Column Name Fix
     *
     * This upgrade fixes a bug in SavedSearchRepository where incorrect column names
     * were used that didn't match the database schema:
     * - Changed `last_notification_sent` to `last_notified_at`
     * - Changed `notifications_enabled` to `notification_frequency IS NOT NULL`
     * - Added proper CASE statement for notification frequency intervals
     *
     * @since 6.11.8
     */
    private static function upgrade_to_6_11_8() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.8 upgrade - SavedSearchRepository column name fix');
        }

        // This is a code-only fix, no database changes needed
        // Clear any cached saved search queries
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_saved_search%'
             OR option_name LIKE '_transient_timeout_mld_saved_search%'"
        );

        // Update version tracking
        update_option('mld_db_version', '6.11.8');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.8 upgrade completed - SavedSearchRepository column names fixed');
        }
    }

    /**
     * Upgrade to version 6.11.9 - Saved Search Notification System Fix
     *
     * This upgrade adds the missing MLD_Saved_Search_Notifications class that:
     * - Bridges the cron job system with actual email sending
     * - Implements frequency-based notifications (instant, hourly, daily, weekly)
     * - Properly matches listings against search criteria
     * - Tracks sent notifications to prevent duplicates
     * - Updates last_notified_at timestamp after sending
     *
     * @since 6.11.9
     */
    private static function upgrade_to_6_11_9() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.9 upgrade - Saved search notification system fix');
        }

        // Clear any stale notification caches
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_notification%'
             OR option_name LIKE '_transient_timeout_mld_notification%'"
        );

        // Reschedule cron jobs to ensure they use new class
        // Remove old schedules
        $hooks_to_reschedule = [
            'mld_saved_search_instant',
            'mld_saved_search_hourly',
            'mld_saved_search_daily',
            'mld_saved_search_weekly'
        ];

        foreach ($hooks_to_reschedule as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }

        // Schedule fresh cron events
        if (class_exists('MLD_Saved_Search_Cron')) {
            MLD_Saved_Search_Cron::schedule_events();
        }

        // Update version tracking
        update_option('mld_db_version', '6.11.9');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.9 upgrade completed - Notification system repaired and cron jobs rescheduled');
        }
    }

    /**
     * Upgrade to version 6.11.10 - Cron Job Self-Healing
     *
     * This upgrade ensures all cron jobs are properly scheduled on existing installations:
     * - Saved search notifications (instant, hourly, daily, weekly)
     * - Chatbot cache cleanup
     * - Chatbot knowledge scan
     * - Adds self-healing mechanism that checks cron status daily
     *
     * @since 6.11.10
     */
    private static function upgrade_to_6_11_10() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.10 upgrade - Cron job self-healing');
        }

        // Clear the cron check transient to force immediate check
        delete_transient('mld_cron_schedule_check');

        // Schedule saved search cron jobs
        $saved_search_hooks = [
            'mld_saved_search_instant' => 'mld_five_minutes',
            'mld_saved_search_hourly' => 'hourly',
            'mld_saved_search_daily' => 'daily',
            'mld_saved_search_weekly' => 'weekly'
        ];

        foreach ($saved_search_hooks as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                if ($schedule === 'mld_five_minutes') {
                    // Register the custom schedule if not exists
                    add_filter('cron_schedules', function($schedules) {
                        if (!isset($schedules['mld_five_minutes'])) {
                            $schedules['mld_five_minutes'] = [
                                'interval' => 300,
                                'display' => 'Every 5 Minutes'
                            ];
                        }
                        return $schedules;
                    });
                }
                wp_schedule_event(time(), $schedule, $hook);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Upgrader: Scheduled {$hook} ({$schedule})");
                }
            }
        }

        // Schedule chatbot cron jobs
        $chatbot_hooks = [
            'mld_chatbot_cache_cleanup' => 'hourly',
            'mld_chatbot_knowledge_scan' => 'daily',
            'mld_chatbot_agent_performance' => 'weekly'
        ];

        foreach ($chatbot_hooks as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $schedule, $hook);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Upgrader: Scheduled {$hook} ({$schedule})");
                }
            }
        }

        // Update version tracking
        update_option('mld_db_version', '6.11.10');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.10 upgrade completed - All cron jobs scheduled');
        }
    }

    /**
     * Upgrade to version 6.11.11 - Summary table sync diagnostic & self-healing
     * Adds diagnostic tool and auto-healing for summary table sync issues
     */
    private static function upgrade_to_6_11_11() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.11 upgrade - Summary sync diagnostic');
        }

        // Clear the auto-heal transient to allow immediate check
        delete_transient('mld_summary_auto_heal');

        // Run an immediate diagnostic check
        if (class_exists('MLD_Summary_Sync_Diagnostic')) {
            $diagnostic = MLD_Summary_Sync_Diagnostic::get_instance();
            $sync_status = $diagnostic->get_sync_status();

            // If out of sync, trigger auto-heal
            if (!$sync_status['is_synced'] && $sync_status['difference'] > 0) {
                $diagnostic->heal_summary_sync();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        'MLD Upgrader: Summary sync healed on upgrade - %d listings fixed',
                        $sync_status['difference']
                    ));
                }
            }
        }

        // Update version tracking
        update_option('mld_db_version', '6.11.11');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.11 upgrade completed - Summary sync diagnostic installed');
        }
    }

    /**
     * Upgrade to version 6.11.12 - Daily notifications now run every 10 minutes
     * Reschedules the daily cron job to use 10-minute interval instead of once daily
     */
    private static function upgrade_to_6_11_12() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.12 upgrade - 10-minute daily notifications');
        }

        // Clear the old daily schedule (was running once per day at 9 AM)
        $daily_hook = 'mld_saved_search_daily';
        wp_clear_scheduled_hook($daily_hook);

        // Register the 10-minute schedule if not exists
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['mld_ten_minutes'])) {
                $schedules['mld_ten_minutes'] = [
                    'interval' => 600, // 10 minutes in seconds
                    'display' => 'Every 10 Minutes'
                ];
            }
            return $schedules;
        }, 5); // Priority 5 to run before cron check

        // Schedule with new 10-minute interval
        if (!wp_next_scheduled($daily_hook)) {
            wp_schedule_event(time(), 'mld_ten_minutes', $daily_hook);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("MLD Upgrader: Rescheduled {$daily_hook} to run every 10 minutes");
            }
        }

        // Clear the cron check transient to ensure self-healing doesn't override
        delete_transient('mld_cron_schedule_check');

        // Update version tracking
        update_option('mld_db_version', '6.11.12');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.12 upgrade completed - Daily notifications now every 10 minutes');
        }
    }

    /**
     * Upgrade to version 6.11.13 - Design System CSS Refresh
     *
     * This upgrade applies a comprehensive CSS redesign with:
     * - New design system tokens (Ocean Teal #0891B2, Dark Gray #1f2937, Red #DC2626)
     * - Removed WordPress blue (#0073aa) throughout
     * - Modern glass/frosted effects with backdrop-filter
     * - Updated all 25+ CSS files with consistent design tokens
     * - Enhanced mobile responsive styles
     *
     * @since 6.11.13
     */
    private static function upgrade_to_6_11_13() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.13 upgrade - Design System CSS Refresh');
        }

        global $wpdb;

        // Clear all caches to ensure new CSS takes effect
        // Clear WordPress transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear opcache if available to ensure PHP serves fresh CSS
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Delete any cached asset versions
        delete_option('mld_asset_version');
        delete_option('mld_css_version');

        // Force browser cache refresh by updating asset version
        update_option('mld_asset_version', '6.11.13.' . time());

        // Set transient to flush rewrite rules on next page load
        set_transient('mld_flush_rewrite_rules', true, 60);

        // Update version tracking
        update_option('mld_db_version', '6.11.13');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.13 upgrade completed - Design system CSS refreshed, caches cleared');
        }
    }

    /**
     * Upgrade to version 6.11.14 - Legacy Chatbot Removal & AI Chatbot Tools
     * Removes legacy chatbot settings and ensures form_submissions table exists
     */
    private static function upgrade_to_6_11_14() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.14 upgrade - Legacy Chatbot Removal & AI Chatbot Tools');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.11.14.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_11_14')) {
                $result = mld_update_to_6_11_14();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.11.14 upgrade completed successfully');
                    } else {
                        error_log('MLD Upgrader: 6.11.14 upgrade encountered issues');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.11.14 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.11.14 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Upgrade to version 6.11.15 - Desktop gallery viewport height fix
     *
     * This upgrade changes the gallery height from fixed 684px to viewport-relative
     * calc(100vh - var(--v3-nav-height)) for consistent nav bar alignment.
     *
     * @since 6.11.15
     */
    private static function upgrade_to_6_11_15() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.15 upgrade - Desktop gallery viewport height fix');
        }

        // Clear CSS caches to ensure new styles take effect
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force browser cache refresh
        update_option('mld_asset_version', '6.11.15.' . time());

        // Update version tracking
        update_option('mld_db_version', '6.11.15');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.15 upgrade completed - Gallery viewport height fix applied');
        }
    }

    /**
     * Upgrade to version 6.11.16 - Chatbot scroll triggers and sidebar removal
     *
     * This upgrade includes:
     * - Chatbot auto-open when gallery scrolled halfway
     * - Chatbot auto-close at Comparable Properties section
     * - Contact Agent sidebar section removed
     * - Horizontal scroll fix (100vw -> 100%)
     *
     * @since 6.11.16
     */
    private static function upgrade_to_6_11_16() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.16 upgrade - Chatbot scroll triggers and sidebar removal');
        }

        // Clear all caches for CSS/JS changes
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Force browser cache refresh
        update_option('mld_asset_version', '6.11.16.' . time());

        // Update version tracking
        update_option('mld_db_version', '6.11.16');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.16 upgrade completed - Chatbot scroll triggers and sidebar removal applied');
        }
    }

    /**
     * Upgrade to version 6.11.17 - Mobile fullscreen app-like experience
     *
     * This upgrade adds:
     * - Native Fullscreen API support for Android devices
     * - CSS pseudo-fullscreen for iOS Safari (which doesn't support JS fullscreen)
     * - Auto-activation on mobile property details and search pages
     * - Exit/enable buttons with localStorage preference persistence
     * - Safe area handling for iOS notch and home indicator
     *
     * @since 6.11.17
     */
    private static function upgrade_to_6_11_17() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.17 upgrade - Mobile fullscreen app-like experience');
        }

        // Clear all caches for new CSS/JS files
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Clear opcache if available to ensure new files are served
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Force browser cache refresh for new assets
        update_option('mld_asset_version', '6.11.17.' . time());

        // Set transient to flush rewrite rules on next page load
        set_transient('mld_flush_rewrite_rules', true, 60);

        // Update version tracking
        update_option('mld_db_version', '6.11.17');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.17 upgrade completed - Mobile fullscreen feature installed');
        }
    }

    /**
     * Upgrade to version 6.11.18 - Move fullscreen button to bottom-left
     *
     * This upgrade moves the fullscreen toggle button from top-right to bottom-left
     * to avoid overlapping with the property status label.
     *
     * @since 6.11.18
     */
    private static function upgrade_to_6_11_18() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.18 upgrade - Move fullscreen button to bottom-left');
        }

        // Clear CSS caches to ensure new styles take effect
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force browser cache refresh for updated CSS
        update_option('mld_asset_version', '6.11.18.' . time());

        // Update version tracking
        update_option('mld_db_version', '6.11.18');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.18 upgrade completed - Fullscreen button moved to bottom-left');
        }
    }

    /**
     * Upgrade to version 6.11.19 - Fix CSS fullscreen on page load
     *
     * This upgrade fixes the fullscreen not activating on initial page load.
     * Now CSS fullscreen is always applied immediately, and native fullscreen
     * is only attempted on user gesture (tap enable button).
     *
     * @since 6.11.19
     */
    private static function upgrade_to_6_11_19() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.19 upgrade - Fix CSS fullscreen on page load');
        }

        // Clear JS caches to ensure new script takes effect
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force browser cache refresh for updated JS
        update_option('mld_asset_version', '6.11.19.' . time());

        // Update version tracking
        update_option('mld_db_version', '6.11.19');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.19 upgrade completed - CSS fullscreen now activates on page load');
        }
    }

    /**
     * Upgrade to version 6.11.20 - Mobile chatbot improvements
     *
     * This upgrade improves the mobile chatbot experience:
     * - Reduced chatbot size (50vh / 400px max instead of 90vh / 600px)
     * - Keyboard handling - chat moves up when keyboard opens
     * - 16px input font size prevents iOS auto-zoom on focus
     * - touch-action: manipulation prevents double-tap zoom
     * - Compact header/messages/input for more content space
     *
     * @since 6.11.20
     */
    private static function upgrade_to_6_11_20() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.11.20 upgrade - Mobile chatbot improvements');
        }

        // Clear CSS/JS caches to ensure new styles take effect
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Force browser cache refresh for updated CSS/JS
        update_option('mld_asset_version', '6.11.20.' . time());

        // Update version tracking
        update_option('mld_db_version', '6.11.20');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.11.20 upgrade completed - Mobile chatbot improvements applied');
        }
    }

    /**
     * Upgrade to version 6.12.0 - Enhanced Market Analytics System
     *
     * This upgrade creates 4 new database tables for comprehensive market analytics:
     * - mld_market_stats_monthly: Pre-computed monthly statistics by city
     * - mld_city_market_summary: Current market state cache for fast dashboard loads
     * - mld_agent_performance: Agent/office ranking and performance metrics
     * - mld_feature_premiums: Property feature value analysis (waterfront, pool, etc.)
     *
     * Also adds indexes to archive tables and schedules analytics cron jobs.
     *
     * @since 6.12.0
     */
    private static function upgrade_to_6_12_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.12.0 upgrade - Enhanced Market Analytics System');
        }

        // Include and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.12.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('mld_update_to_6_12_0')) {
                $result = mld_update_to_6_12_0();

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.12.0 upgrade completed successfully - 4 analytics tables created');
                    } else {
                        error_log('MLD Upgrader: 6.12.0 upgrade encountered issues creating analytics tables');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.12.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.12.0 update file not found at ' . $update_file);
            }
        }
    }

    /**
     * Run this on admin_init to check for upgrades
     */
    public static function init() {
        self::check_upgrades();
    }

    /**
     * Upgrade to version 6.12.8 - Property Page Analytics Integration
     *
     * Adds:
     * - Property page market analytics REST API
     * - Lazy-loaded analytics tabs
     * - Mobile lite mode support
     *
     * @since 6.12.8
     */
    private static function upgrade_to_6_12_8() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.12.8 upgrade - Property Page Analytics Integration');
        }

        // Load and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.12.8.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_12_8')) {
                $result = mld_update_to_6_12_8();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.12.8 upgrade completed successfully');
                    } else {
                        error_log('MLD Upgrader: 6.12.8 upgrade encountered issues');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.12.8 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.12.8 update file not found');
            }
        }
    }

    /**
     * Upgrade to version 6.13.0 - 15-Minute Saved Search Email Alert System
     *
     * Adds:
     * - 15-minute cron schedule for saved search alerts
     * - Enhanced change detection using wp_bme_property_history table
     * - Comprehensive filter matching for all 45+ half-map filters
     * - Change-type-specific email notifications (new, price, status)
     * - 25-listing limit per email with batch processing
     *
     * @since 6.13.0
     */
    private static function upgrade_to_6_13_0() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.0 upgrade - 15-Minute Saved Search Email Alert System');
        }

        // Load and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.13.0.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_13_0')) {
                $result = mld_update_to_6_13_0();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.13.0 upgrade completed successfully - 15-minute saved search alerts enabled');
                    } else {
                        error_log('MLD Upgrader: 6.13.0 upgrade encountered issues');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.13.0 update function not found');
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.13.0 update file not found');
            }
        }
    }

    /**
     * Upgrade to version 6.13.14 - Notification System Consolidation
     *
     * This upgrade:
     * - Disables legacy Simple Notifications system (was causing duplicate emails)
     * - Clears orphaned cron jobs (mld_simple_notifications_check, mld_process_* hooks)
     * - Adds social media settings for email footer
     * - Enhances email template with market insights, property metrics
     * - Clears caches to ensure new email templates load
     *
     * @since 6.13.14
     */
    private static function upgrade_to_6_13_14() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.14 upgrade - Notification System Consolidation');
        }

        // 1. Clear legacy cron jobs that are no longer needed
        $legacy_cron_hooks = array(
            'mld_simple_notifications_check',
            'mld_process_instant_searches',
            'mld_process_hourly_searches',
            'mld_process_daily_searches',
            'mld_process_weekly_searches'
        );

        foreach ($legacy_cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Upgrader: Cleared legacy cron job: {$hook}");
                }
            }
            // Also clear any remaining scheduled events
            wp_clear_scheduled_hook($hook);
        }

        // 2. Ensure new saved search cron jobs are properly scheduled
        $new_cron_hooks = array(
            'mld_saved_search_instant' => 'mld_five_minutes',
            'mld_saved_search_fifteen_min' => 'mld_fifteen_minutes',
            'mld_saved_search_hourly' => 'hourly',
            'mld_saved_search_daily' => 'daily',
            'mld_saved_search_weekly' => 'weekly'
        );

        foreach ($new_cron_hooks as $hook => $schedule) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), $schedule, $hook);
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("MLD Upgrader: Scheduled cron job: {$hook} ({$schedule})");
                }
            }
        }

        // 3. Initialize social media settings with empty defaults
        $social_settings = array(
            'mld_social_facebook',
            'mld_social_twitter',
            'mld_social_instagram',
            'mld_social_linkedin'
        );

        foreach ($social_settings as $setting) {
            if (get_option($setting) === false) {
                add_option($setting, '');
            }
        }

        // 4. Clear all caches to ensure new email templates are used
        wp_cache_flush();

        // Clear transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mld_%'");

        // Clear search cache table
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}mld_search_cache");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.13.14 upgrade completed successfully - notification system consolidated');
        }
    }


    /**
     * Upgrade to version 6.13.15 - Dark mode fixes, Analytics REST API, Mobile comparable sales
     *
     * This upgrade includes:
     * - Dark mode CSS fixes (removed prefers-color-scheme media queries)
     * - Comparable sales script enqueue fix for mobile
     * - Analytics REST API fix (was empty file)
     * - Market Velocity DOM bars calculation fix
     * - Property Analytics parseFloat fixes for string values
     *
     * @since 6.13.15
     */
    private static function upgrade_to_6_13_17() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.17 upgrade - Dark mode fixes, Analytics REST API, Mobile comparable sales');
        }

        // Load and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.13.15.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_13_16')) {
                $result = mld_update_to_6_13_16();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.13.17 upgrade completed successfully');
                    } else {
                        error_log('MLD Upgrader: 6.13.17 upgrade encountered issues');
                    }
                }
            } else {
                // Run inline upgrade if update file function not found
                global $wpdb;
                
                // Clear transients
                $wpdb->query(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name LIKE '_transient_mld_%'
                     OR option_name LIKE '_transient_timeout_mld_%'"
                );
                
                // Clear WordPress cache
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
                
                // Force asset version update
                update_option('mld_asset_version', '6.13.15.' . time());
                
                // Flush rewrite rules
                set_transient('mld_flush_rewrite_rules', true, 60);
                
                // Update version
                update_option('mld_db_version', '6.13.16');
                update_option('mld_plugin_version', '6.13.16');
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MLD Upgrader: 6.13.15 inline upgrade completed');
                }
            }
        } else {
            // Run inline upgrade if update file not found
            global $wpdb;
            
            // Clear transients
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_mld_%'
                 OR option_name LIKE '_transient_timeout_mld_%'"
            );
            
            // Clear WordPress cache
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            // Force asset version update
            update_option('mld_asset_version', '6.13.15.' . time());
            
            // Flush rewrite rules
            set_transient('mld_flush_rewrite_rules', true, 60);
            
            // Update version
            update_option('mld_db_version', '6.13.16');
            update_option('mld_plugin_version', '6.13.16');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.13.15 inline upgrade completed (no update file)');
            }
        }
    }

    /**
     * Upgrade to version 6.13.18 - Kinsta cache bypass fix
     * Adds no-cache headers to all AJAX responses to prevent stale data
     *
     * @since 6.13.18
     */
    private static function upgrade_to_6_13_18() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.18 upgrade - Kinsta cache bypass (AJAX no-cache headers)');
        }

        // Load and run the update file
        $update_file = dirname(MLD_PLUGIN_FILE) . '/updates/update-6.13.18.php';
        if (file_exists($update_file)) {
            require_once $update_file;
            if (function_exists('mld_update_to_6_13_18')) {
                $result = mld_update_to_6_13_18();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result) {
                        error_log('MLD Upgrader: 6.13.18 upgrade completed successfully');
                    } else {
                        error_log('MLD Upgrader: 6.13.18 upgrade encountered issues');
                    }
                }
            }
        } else {
            // Run inline upgrade if update file not found
            global $wpdb;

            // Clear transients to ensure fresh AJAX responses
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_mld_%'
                 OR option_name LIKE '_transient_timeout_mld_%'"
            );

            // Clear WordPress cache
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // Update version
            update_option('mld_db_version', '6.13.18');
            update_option('mld_plugin_version', '6.13.18');

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Upgrader: 6.13.18 inline upgrade completed (no update file)');
            }
        }
    }

    /**
     * Upgrade to version 6.13.19 - Kinsta/Redis cache fix
     * Disables internal transient cache on Kinsta and other hosts with Redis
     *
     * @since 6.13.19
     */
    private static function upgrade_to_6_13_19() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.19 upgrade - Kinsta/Redis transient cache fix');
        }

        global $wpdb;

        // Clear ALL MLD transients to ensure fresh data
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_mld_%'
             OR option_name LIKE '_transient_timeout_mld_%'"
        );

        // Clear WordPress object cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Update version
        update_option('mld_db_version', '6.13.19');
        update_option('mld_plugin_version', '6.13.19');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.13.19 upgrade completed - transient cache will be disabled on Kinsta/Redis hosts');
        }
    }

    /**
     * Upgrade to version 6.13.20 - Summary table fallback for Kinsta
     * Adds fallback method to refresh summary table without stored procedures
     *
     * @since 6.13.20
     */
    private static function upgrade_to_6_13_20() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: Running 6.13.20 upgrade - Summary table fallback for Kinsta');
        }

        global $wpdb;
        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $listings_table = $wpdb->prefix . 'bme_listings';

        // Check if summary table needs refresh
        $summary_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");
        $active_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$listings_table} WHERE standard_status = 'Active'");

        error_log("[MLD Upgrader 6.13.20] Summary: {$summary_count}, Active: {$active_count}");

        // If summary table is significantly out of sync, try to refresh it
        if ($summary_count < ($active_count * 0.5)) {
            error_log('[MLD Upgrader 6.13.20] Summary table significantly out of sync, triggering refresh...');

            // Try BME's refresh method first
            if (function_exists('bme_pro')) {
                $plugin = bme_pro();
                $db = $plugin ? $plugin->get('db') : null;

                if ($db && method_exists($db, 'refresh_listing_summary')) {
                    $result = $db->refresh_listing_summary();
                    error_log("[MLD Upgrader 6.13.20] BME refresh returned: " . ($result !== false ? $result : 'false'));
                }
            }

            // Check result
            $new_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$summary_table}");
            if ($new_count < ($active_count * 0.5)) {
                error_log('[MLD Upgrader 6.13.20] BME refresh failed or incomplete. Admin can use Health Dashboard fallback.');
            }
        }

        // Update version
        update_option('mld_db_version', '6.13.20');
        update_option('mld_plugin_version', '6.13.20');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Upgrader: 6.13.20 upgrade completed');
        }
    }
}
