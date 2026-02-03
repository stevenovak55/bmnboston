<?php
/**
 * Plugin Upgrader
 *
 * Handles version checking and database migrations.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Upgrader class.
 *
 * @since 1.0.0
 */
class SNAB_Upgrader {

    /**
     * Current plugin version.
     * IMPORTANT: Keep in sync with SNAB_VERSION in main plugin file.
     */
    const CURRENT_VERSION = '1.9.5';

    /**
     * Current database version.
     * IMPORTANT: Keep in sync with SNAB_DB_VERSION in main plugin file.
     */
    const CURRENT_DB_VERSION = '1.9.5';

    /**
     * Check version and run upgrades if needed.
     *
     * This runs on plugins_loaded hook to ensure upgrades happen
     * even when plugin files are updated without deactivation/reactivation
     * (e.g., WordPress automatic updates).
     *
     * @since 1.0.0
     */
    public static function check_version() {
        $installed_db_version = get_option('snab_db_version', '0.0.0');
        $installed_plugin_version = get_option('snab_version', '0.0.0');

        // Run upgrades if DB version is behind
        if (version_compare($installed_db_version, self::CURRENT_DB_VERSION, '<')) {
            self::run_upgrades($installed_db_version);
        }

        // Also run database repair if plugin version changed (catches file-only updates)
        if (version_compare($installed_plugin_version, self::CURRENT_VERSION, '<')) {
            SNAB_Logger::info('Plugin version changed, running database verification', array(
                'from' => $installed_plugin_version,
                'to' => self::CURRENT_VERSION,
            ));

            // Verify and repair database structure
            SNAB_Activator::repair_database();

            // Update plugin version
            update_option('snab_version', self::CURRENT_VERSION);
        }
    }

    /**
     * Run all necessary upgrades.
     *
     * @since 1.0.0
     * @param string $from_version The version to upgrade from.
     */
    private static function run_upgrades($from_version) {
        SNAB_Logger::info('Starting upgrade', array(
            'from' => $from_version,
            'to' => self::CURRENT_DB_VERSION,
        ));

        // Run version-specific upgrades in order
        // Each upgrade function handles one version jump

        // Fresh install or version 0.0.0
        if (version_compare($from_version, '1.0.0', '<')) {
            self::upgrade_to_1_0_0();
        }

        // Version 1.1.0 - Add reschedule and manual appointment columns
        if (version_compare($from_version, '1.1.0', '<')) {
            self::upgrade_to_1_1_0();
        }

        // Version 1.2.0 - Add shortcode presets table
        if (version_compare($from_version, '1.2.0', '<')) {
            self::upgrade_to_1_2_0();
        }

        // Version 1.5.0 - Add client portal features
        if (version_compare($from_version, '1.5.0', '<')) {
            self::upgrade_to_1_5_0();
        }

        // Version 1.6.0 - Admin experience improvements (multi-staff, calendar preview)
        if (version_compare($from_version, '1.6.0', '<')) {
            self::upgrade_to_1_6_0();
        }

        // Version 1.6.1 - Per-staff Google Calendar connections
        if (version_compare($from_version, '1.6.1', '<')) {
            self::upgrade_to_1_6_1();
        }

        // Version 1.7.0 - Add UNIQUE constraint to prevent double-booking race condition
        if (version_compare($from_version, '1.7.0', '<')) {
            self::upgrade_to_1_7_0();
        }

        // Update stored version
        update_option('snab_db_version', self::CURRENT_DB_VERSION);
        update_option('snab_version', self::CURRENT_VERSION);

        SNAB_Logger::info('Upgrade complete', array(
            'version' => self::CURRENT_DB_VERSION,
        ));
    }

    /**
     * Upgrade to version 1.0.0.
     *
     * Initial installation - creates all tables.
     *
     * @since 1.0.0
     */
    private static function upgrade_to_1_0_0() {
        SNAB_Logger::info('Running upgrade to 1.0.0');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.0.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_0_0')) {
                $result = snab_update_to_1_0_0();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.0.0 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.0.0 failed');
                }
            }
        } else {
            // If no update file, run activator directly
            SNAB_Activator::create_tables();
            SNAB_Activator::create_default_data();
            SNAB_Activator::set_default_options();
        }
    }

    /**
     * Upgrade to version 1.1.0.
     *
     * Adds reschedule columns and manual appointment support.
     *
     * @since 1.1.0
     */
    private static function upgrade_to_1_1_0() {
        SNAB_Logger::info('Running upgrade to 1.1.0');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.1.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_1_0')) {
                $result = snab_update_to_1_1_0();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.1.0 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.1.0 failed');
                }
            }
        }
    }

    /**
     * Upgrade to version 1.2.0.
     *
     * Creates shortcode presets table.
     *
     * @since 1.2.0
     */
    private static function upgrade_to_1_2_0() {
        SNAB_Logger::info('Running upgrade to 1.2.0');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.2.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_2_0')) {
                $result = snab_update_to_1_2_0();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.2.0 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.2.0 failed');
                }
            }
        }
    }

    /**
     * Upgrade to version 1.5.0.
     *
     * Adds client portal features: cancelled_by column, default options, notification templates.
     *
     * @since 1.5.0
     */
    private static function upgrade_to_1_5_0() {
        SNAB_Logger::info('Running upgrade to 1.5.0');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.5.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_5_0')) {
                $result = snab_update_to_1_5_0();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.5.0 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.5.0 failed');
                }
            }
        }
    }

    /**
     * Upgrade to version 1.6.0.
     *
     * Admin experience improvements: staff_services table, notification preferences, calendar colors.
     *
     * @since 1.6.0
     */
    private static function upgrade_to_1_6_0() {
        SNAB_Logger::info('Running upgrade to 1.6.0');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.6.0.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_6_0')) {
                $result = snab_update_to_1_6_0();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.6.0 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.6.0 failed');
                }
            }
        }
    }

    /**
     * Upgrade to version 1.6.1.
     *
     * Per-staff Google Calendar connections: adds token and expires columns to staff table.
     *
     * @since 1.6.1
     */
    private static function upgrade_to_1_6_1() {
        SNAB_Logger::info('Running upgrade to 1.6.1');

        // Load and run the update file
        $update_file = SNAB_PLUGIN_DIR . 'updates/update-1.6.1.php';

        if (file_exists($update_file)) {
            require_once $update_file;

            if (function_exists('snab_update_to_1_6_1')) {
                $result = snab_update_to_1_6_1();

                if ($result) {
                    SNAB_Logger::info('Upgrade to 1.6.1 successful');
                } else {
                    SNAB_Logger::error('Upgrade to 1.6.1 failed');
                }
            }
        }
    }

    /**
     * Upgrade to version 1.7.0.
     *
     * Adds UNIQUE constraint on (staff_id, appointment_date, start_time) to prevent
     * double-booking race conditions at the database level.
     *
     * @since 1.7.0
     */
    private static function upgrade_to_1_7_0() {
        global $wpdb;

        SNAB_Logger::info('Running upgrade to 1.7.0 - Adding unique slot constraint');

        $appointments_table = $wpdb->prefix . 'snab_appointments';

        // Check if the unique key already exists
        $existing_keys = $wpdb->get_results("SHOW INDEX FROM {$appointments_table} WHERE Key_name = 'unique_slot'");

        if (empty($existing_keys)) {
            // Before adding the constraint, we need to handle any existing duplicates
            // Find and log duplicate slots (same staff, date, time)
            $duplicates = $wpdb->get_results("
                SELECT staff_id, appointment_date, start_time, COUNT(*) as count
                FROM {$appointments_table}
                WHERE status NOT IN ('cancelled')
                GROUP BY staff_id, appointment_date, start_time
                HAVING COUNT(*) > 1
            ");

            if (!empty($duplicates)) {
                SNAB_Logger::warning('Found duplicate booking slots before adding constraint', array(
                    'count' => count($duplicates),
                    'duplicates' => $duplicates,
                ));

                // Keep only the oldest booking for each duplicate slot, cancel newer ones
                foreach ($duplicates as $dup) {
                    // Get all appointments for this slot, ordered by creation date
                    $appointments = $wpdb->get_results($wpdb->prepare("
                        SELECT id, status, created_at
                        FROM {$appointments_table}
                        WHERE staff_id = %d
                        AND appointment_date = %s
                        AND start_time = %s
                        AND status NOT IN ('cancelled')
                        ORDER BY created_at ASC
                    ", $dup->staff_id, $dup->appointment_date, $dup->start_time));

                    // Skip the first (oldest) one, cancel the rest
                    $first = true;
                    foreach ($appointments as $appt) {
                        if ($first) {
                            $first = false;
                            continue;
                        }

                        $wpdb->update(
                            $appointments_table,
                            array(
                                'status' => 'cancelled',
                                'cancellation_reason' => 'Auto-cancelled: duplicate booking detected during database upgrade to v1.7.0',
                                'cancelled_at' => current_time('mysql'),
                            ),
                            array('id' => $appt->id),
                            array('%s', '%s', '%s'),
                            array('%d')
                        );

                        SNAB_Logger::info('Auto-cancelled duplicate appointment', array(
                            'appointment_id' => $appt->id,
                            'staff_id' => $dup->staff_id,
                            'date' => $dup->appointment_date,
                            'time' => $dup->start_time,
                        ));
                    }
                }
            }

            // Now add the unique constraint
            $result = $wpdb->query("
                ALTER TABLE {$appointments_table}
                ADD UNIQUE KEY unique_slot (staff_id, appointment_date, start_time)
            ");

            if ($result === false) {
                SNAB_Logger::error('Failed to add unique_slot constraint', array(
                    'error' => $wpdb->last_error,
                ));
            } else {
                SNAB_Logger::info('Successfully added unique_slot constraint to appointments table');
            }
        } else {
            SNAB_Logger::info('unique_slot constraint already exists, skipping');
        }
    }

    /**
     * Get current versions.
     *
     * @since 1.0.0
     * @return array Version information.
     */
    public static function get_versions() {
        return array(
            'plugin_version' => self::CURRENT_VERSION,
            'db_version' => self::CURRENT_DB_VERSION,
            'installed_version' => get_option('snab_version', 'not installed'),
            'installed_db_version' => get_option('snab_db_version', 'not installed'),
        );
    }

    /**
     * Check if upgrade is needed.
     *
     * @since 1.0.0
     * @return bool True if upgrade needed.
     */
    public static function needs_upgrade() {
        $installed_version = get_option('snab_db_version', '0.0.0');
        return version_compare($installed_version, self::CURRENT_DB_VERSION, '<');
    }

    /**
     * Force re-run of all upgrades.
     *
     * Use with caution - for debugging/repair only.
     *
     * @since 1.0.0
     */
    public static function force_upgrade() {
        delete_option('snab_db_version');
        self::check_version();
    }
}
