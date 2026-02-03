<?php
/**
 * Admin Class
 *
 * Handles admin menu registration and page rendering.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class.
 *
 * @since 1.0.0
 */
class SNAB_Admin {

    /**
     * Admin Types instance.
     *
     * @var SNAB_Admin_Types
     */
    private $admin_types;

    /**
     * Admin Availability instance.
     *
     * @var SNAB_Admin_Availability
     */
    private $admin_availability;

    /**
     * Admin Settings instance.
     *
     * @var SNAB_Admin_Settings
     */
    private $admin_settings;

    /**
     * Admin Appointments instance.
     *
     * @var SNAB_Admin_Appointments
     */
    private $admin_appointments;

    /**
     * Admin Presets instance.
     *
     * @var SNAB_Admin_Presets
     */
    private $admin_presets;

    /**
     * Analytics instance.
     *
     * @var SNAB_Analytics
     */
    private $analytics;

    /**
     * Admin Staff instance.
     *
     * @var SNAB_Admin_Staff
     * @since 1.6.0
     */
    private $admin_staff;

    /**
     * Admin Calendar instance.
     *
     * @var SNAB_Admin_Calendar
     * @since 1.6.0
     */
    private $admin_calendar;

    /**
     * Constructor.
     */
    public function __construct() {
        // Initialize admin sub-classes
        $this->admin_types = new SNAB_Admin_Types();
        $this->admin_availability = new SNAB_Admin_Availability();
        $this->admin_settings = new SNAB_Admin_Settings();
        $this->admin_appointments = new SNAB_Admin_Appointments();
        $this->admin_presets = new SNAB_Admin_Presets();
        $this->analytics = new SNAB_Analytics();
        $this->admin_staff = new SNAB_Admin_Staff();
        $this->admin_calendar = new SNAB_Admin_Calendar();

        // Initialize Google Calendar singleton
        snab_google_calendar();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }

    /**
     * Handle admin actions (form submissions).
     *
     * @since 1.4.1
     */
    public function handle_admin_actions() {
        // Handle database repair
        if (isset($_POST['snab_action']) && $_POST['snab_action'] === 'repair_database') {
            if (!isset($_POST['snab_repair_nonce']) || !wp_verify_nonce($_POST['snab_repair_nonce'], 'snab_repair_db')) {
                wp_die(__('Security check failed', 'sn-appointment-booking'));
            }

            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have permission to perform this action.', 'sn-appointment-booking'));
            }

            $results = SNAB_Activator::repair_database();

            // Store results in transient for display
            set_transient('snab_repair_results', $results, 60);

            // Redirect to avoid form resubmission
            wp_redirect(admin_url('admin.php?page=snab-dashboard&repaired=1'));
            exit;
        }
    }

    /**
     * Register admin menu.
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('SN Appointments', 'sn-appointment-booking'),
            __('SN Appointments', 'sn-appointment-booking'),
            'manage_options',
            'snab-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-calendar-alt',
            30
        );

        // Dashboard submenu (same as main)
        add_submenu_page(
            'snab-dashboard',
            __('Dashboard', 'sn-appointment-booking'),
            __('Dashboard', 'sn-appointment-booking'),
            'manage_options',
            'snab-dashboard',
            array($this, 'render_dashboard')
        );

        // Appointments
        add_submenu_page(
            'snab-dashboard',
            __('Appointments', 'sn-appointment-booking'),
            __('Appointments', 'sn-appointment-booking'),
            'manage_options',
            'snab-appointments',
            array($this, 'render_appointments')
        );

        // Calendar
        add_submenu_page(
            'snab-dashboard',
            __('Calendar', 'sn-appointment-booking'),
            __('Calendar', 'sn-appointment-booking'),
            'manage_options',
            'snab-calendar',
            array($this, 'render_calendar')
        );

        // Availability
        add_submenu_page(
            'snab-dashboard',
            __('Availability', 'sn-appointment-booking'),
            __('Availability', 'sn-appointment-booking'),
            'manage_options',
            'snab-availability',
            array($this, 'render_availability')
        );

        // Appointment Types
        add_submenu_page(
            'snab-dashboard',
            __('Appointment Types', 'sn-appointment-booking'),
            __('Appointment Types', 'sn-appointment-booking'),
            'manage_options',
            'snab-types',
            array($this, 'render_types')
        );

        // Staff Members
        add_submenu_page(
            'snab-dashboard',
            __('Staff', 'sn-appointment-booking'),
            __('Staff', 'sn-appointment-booking'),
            'manage_options',
            'snab-staff',
            array($this, 'render_staff')
        );

        // Shortcode Presets
        add_submenu_page(
            'snab-dashboard',
            __('Shortcode Presets', 'sn-appointment-booking'),
            __('Shortcode Presets', 'sn-appointment-booking'),
            'manage_options',
            'snab-presets',
            array($this, 'render_presets')
        );

        // Analytics
        add_submenu_page(
            'snab-dashboard',
            __('Analytics', 'sn-appointment-booking'),
            __('Analytics', 'sn-appointment-booking'),
            'manage_options',
            'snab-analytics',
            array($this, 'render_analytics')
        );

        // Settings
        add_submenu_page(
            'snab-dashboard',
            __('Settings', 'sn-appointment-booking'),
            __('Settings', 'sn-appointment-booking'),
            'manage_options',
            'snab-settings',
            array($this, 'render_settings')
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'snab-') === false && $hook !== 'toplevel_page_snab-dashboard') {
            return;
        }

        // Admin CSS
        wp_enqueue_style(
            'snab-admin',
            SNAB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SNAB_VERSION
        );

        // Load page-specific JavaScript
        if (strpos($hook, 'snab-types') !== false) {
            // jQuery UI Sortable for drag-and-drop ordering
            wp_enqueue_script('jquery-ui-sortable');

            // Admin Types JS
            wp_enqueue_script(
                'snab-admin-types',
                SNAB_PLUGIN_URL . 'assets/js/admin-types.js',
                array('jquery', 'jquery-ui-sortable'),
                SNAB_VERSION,
                true
            );

            // Localize script
            wp_localize_script('snab-admin-types', 'snabAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('snab_admin_nonce'),
                'i18n' => array(
                    'addType' => __('Add Appointment Type', 'sn-appointment-booking'),
                    'editType' => __('Edit Appointment Type', 'sn-appointment-booking'),
                    'saveType' => __('Save Type', 'sn-appointment-booking'),
                    'updateType' => __('Update Type', 'sn-appointment-booking'),
                    'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                    'noTypes' => __('No appointment types found. Click "Add New" to create one.', 'sn-appointment-booking'),
                    'autoGenerated' => __('Auto-generated from name', 'sn-appointment-booking'),
                    'clickToActivate' => __('Click to activate', 'sn-appointment-booking'),
                    'clickToDeactivate' => __('Click to deactivate', 'sn-appointment-booking'),
                ),
            ));
        }

        // Availability page JavaScript
        if (strpos($hook, 'snab-availability') !== false) {
            // Admin Availability JS
            wp_enqueue_script(
                'snab-admin-availability',
                SNAB_PLUGIN_URL . 'assets/js/admin-availability.js',
                array('jquery'),
                SNAB_VERSION,
                true
            );

            // Localize script
            wp_localize_script('snab-admin-availability', 'snabAvailability', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('snab_admin_nonce'),
                'i18n' => array(
                    'available' => __('Available', 'sn-appointment-booking'),
                    'unavailable' => __('Unavailable', 'sn-appointment-booking'),
                    'addOverride' => __('Add Date Override', 'sn-appointment-booking'),
                    'editOverride' => __('Edit Date Override', 'sn-appointment-booking'),
                    'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                    'noOverrides' => __('No date overrides set.', 'sn-appointment-booking'),
                    'noBlocked' => __('No blocked times.', 'sn-appointment-booking'),
                    'confirmDeleteOverride' => __('Are you sure you want to delete this date override?', 'sn-appointment-booking'),
                    'confirmDeleteBlocked' => __('Are you sure you want to delete this blocked time?', 'sn-appointment-booking'),
                ),
            ));
        }

        // Appointments page JavaScript
        if (strpos($hook, 'snab-appointments') !== false) {
            // Admin Appointments JS
            wp_enqueue_script(
                'snab-admin-appointments',
                SNAB_PLUGIN_URL . 'assets/js/admin-appointments.js',
                array('jquery'),
                SNAB_VERSION,
                true
            );

            // Localize script
            wp_localize_script('snab-admin-appointments', 'snabAppointments', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('snab_admin_nonce'),
                'i18n' => array(
                    'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                    'saving' => __('Saving...', 'sn-appointment-booking'),
                    'saveNotes' => __('Save Notes', 'sn-appointment-booking'),
                    'cancelling' => __('Cancelling...', 'sn-appointment-booking'),
                    'cancelAppointment' => __('Cancel Appointment', 'sn-appointment-booking'),
                    'cancelled' => __('Cancelled', 'sn-appointment-booking'),
                    'confirmCancel' => __('Are you sure you want to cancel this appointment?', 'sn-appointment-booking'),
                    // Create appointment
                    'selectDateFirst' => __('Select date first...', 'sn-appointment-booking'),
                    'selectTime' => __('Select a time...', 'sn-appointment-booking'),
                    'loading' => __('Loading...', 'sn-appointment-booking'),
                    'noSlotsAvailable' => __('No slots available', 'sn-appointment-booking'),
                    'fillRequired' => __('Please fill in all required fields.', 'sn-appointment-booking'),
                    'creating' => __('Creating...', 'sn-appointment-booking'),
                    'createAppointment' => __('Create Appointment', 'sn-appointment-booking'),
                    // Reschedule
                    'type' => __('Type', 'sn-appointment-booking'),
                    'date' => __('Date', 'sn-appointment-booking'),
                    'time' => __('Time', 'sn-appointment-booking'),
                    'client' => __('Client', 'sn-appointment-booking'),
                    'previouslyRescheduled' => __('Previously rescheduled %d time(s)', 'sn-appointment-booking'),
                    'selectDateAndTime' => __('Please select a date and time.', 'sn-appointment-booking'),
                    'rescheduling' => __('Rescheduling...', 'sn-appointment-booking'),
                    'reschedule' => __('Reschedule', 'sn-appointment-booking'),
                ),
            ));
        }

        // Presets page JavaScript
        if (strpos($hook, 'snab-presets') !== false) {
            // Admin Presets JS
            wp_enqueue_script(
                'snab-admin-presets',
                SNAB_PLUGIN_URL . 'assets/js/admin-presets.js',
                array('jquery'),
                SNAB_VERSION,
                true
            );

            // Localize script
            wp_localize_script('snab-admin-presets', 'snabPresets', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('snab_admin_nonce'),
                'i18n' => array(
                    'addPreset' => __('Add Shortcode Preset', 'sn-appointment-booking'),
                    'editPreset' => __('Edit Shortcode Preset', 'sn-appointment-booking'),
                    'savePreset' => __('Save Preset', 'sn-appointment-booking'),
                    'updatePreset' => __('Update Preset', 'sn-appointment-booking'),
                    'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                    'saving' => __('Saving...', 'sn-appointment-booking'),
                    'confirmDelete' => __('Are you sure you want to delete this preset?', 'sn-appointment-booking'),
                    'copied' => __('Copied to clipboard!', 'sn-appointment-booking'),
                    'autoGenerated' => __('Auto-generated from name', 'sn-appointment-booking'),
                ),
            ));
        }

        // Analytics page JavaScript
        if (strpos($hook, 'snab-analytics') !== false) {
            // Chart.js from CDN
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                array(),
                '4.4.1',
                true
            );

            // Admin Analytics JS
            wp_enqueue_script(
                'snab-admin-analytics',
                SNAB_PLUGIN_URL . 'assets/js/admin-analytics.js',
                array('jquery', 'chartjs'),
                SNAB_VERSION,
                true
            );

            // Localize script
            wp_localize_script('snab-admin-analytics', 'snabAnalytics', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('snab_admin_nonce'),
                'i18n' => array(
                    'loading' => __('Loading...', 'sn-appointment-booking'),
                    'error' => __('An error occurred. Please try again.', 'sn-appointment-booking'),
                    'noData' => __('No data available for the selected period.', 'sn-appointment-booking'),
                    'appointments' => __('Appointments', 'sn-appointment-booking'),
                    'completed' => __('Completed', 'sn-appointment-booking'),
                    'cancelled' => __('Cancelled', 'sn-appointment-booking'),
                    'noShow' => __('No-Show', 'sn-appointment-booking'),
                    'pending' => __('Pending', 'sn-appointment-booking'),
                ),
            ));
        }
    }

    /**
     * Display admin notices.
     */
    public function admin_notices() {
        // Check if tables are missing
        if (isset($_GET['page']) && strpos($_GET['page'], 'snab-') !== false) {
            // Show repair results
            if (isset($_GET['repaired']) && $_GET['repaired'] == '1') {
                $results = get_transient('snab_repair_results');
                if ($results) {
                    delete_transient('snab_repair_results');

                    $message = '';
                    if (!empty($results['tables_created'])) {
                        $message .= sprintf(
                            __('Tables created: %s. ', 'sn-appointment-booking'),
                            implode(', ', $results['tables_created'])
                        );
                    }
                    if (!empty($results['columns_added'])) {
                        $message .= sprintf(
                            __('Columns added: %s. ', 'sn-appointment-booking'),
                            implode(', ', $results['columns_added'])
                        );
                    }
                    if (empty($results['tables_created']) && empty($results['columns_added'])) {
                        $message = __('Database is already up to date. No repairs needed.', 'sn-appointment-booking');
                    }
                    if (!empty($results['errors'])) {
                        $message .= sprintf(
                            __('Errors: %s', 'sn-appointment-booking'),
                            implode(', ', $results['errors'])
                        );
                    }

                    $notice_class = empty($results['errors']) ? 'notice-success' : 'notice-warning';
                    echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>';
                    echo '<strong>' . esc_html__('SN Appointment Booking:', 'sn-appointment-booking') . '</strong> ';
                    echo esc_html($message);
                    echo '</p></div>';
                }
            }

            $tables = SNAB_Activator::verify_tables();
            $missing = array_filter($tables, function($exists) {
                return !$exists;
            });

            if (!empty($missing)) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('SN Appointment Booking:', 'sn-appointment-booking') . '</strong> ';
                echo esc_html__('Some database tables are missing. Use the "Repair Database" button on the Dashboard.', 'sn-appointment-booking');
                echo '</p></div>';
            }

            // Check if upgrade is needed
            if (SNAB_Upgrader::needs_upgrade()) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>' . esc_html__('SN Appointment Booking:', 'sn-appointment-booking') . '</strong> ';
                echo esc_html__('Database upgrade required. This will happen automatically.', 'sn-appointment-booking');
                echo '</p></div>';
            }
        }
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        global $wpdb;

        // Get stats
        $table_appointments = $wpdb->prefix . 'snab_appointments';
        $table_types = $wpdb->prefix . 'snab_appointment_types';

        $upcoming_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_appointments}
             WHERE status = 'confirmed'
             AND appointment_date >= %s",
            current_time('Y-m-d')
        ));

        $today_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_appointments}
             WHERE status = 'confirmed'
             AND appointment_date = %s",
            current_time('Y-m-d')
        ));

        $types_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_types} WHERE is_active = 1");

        // Get recent appointments
        $recent_appointments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.name as type_name, t.color
             FROM {$table_appointments} a
             JOIN {$table_types} t ON a.appointment_type_id = t.id
             WHERE a.appointment_date >= %s
             AND a.status = 'confirmed'
             ORDER BY a.appointment_date, a.start_time
             LIMIT 5",
            current_time('Y-m-d')
        ));

        // Get version info
        $versions = SNAB_Upgrader::get_versions();

        ?>
        <div class="wrap snab-admin-wrap">
            <h1><?php esc_html_e('SN Appointment Booking', 'sn-appointment-booking'); ?></h1>

            <div class="snab-dashboard">
                <!-- Stats Cards -->
                <div class="snab-stats-grid">
                    <div class="snab-stat-card">
                        <div class="snab-stat-number"><?php echo esc_html($today_count); ?></div>
                        <div class="snab-stat-label"><?php esc_html_e('Today\'s Appointments', 'sn-appointment-booking'); ?></div>
                    </div>
                    <div class="snab-stat-card">
                        <div class="snab-stat-number"><?php echo esc_html($upcoming_count); ?></div>
                        <div class="snab-stat-label"><?php esc_html_e('Upcoming Appointments', 'sn-appointment-booking'); ?></div>
                    </div>
                    <div class="snab-stat-card">
                        <div class="snab-stat-number"><?php echo esc_html($types_count); ?></div>
                        <div class="snab-stat-label"><?php esc_html_e('Active Appointment Types', 'sn-appointment-booking'); ?></div>
                    </div>
                </div>

                <!-- Recent Appointments -->
                <div class="snab-section">
                    <h2><?php esc_html_e('Upcoming Appointments', 'sn-appointment-booking'); ?></h2>
                    <?php if (empty($recent_appointments)): ?>
                        <p class="snab-no-data"><?php esc_html_e('No upcoming appointments.', 'sn-appointment-booking'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', 'sn-appointment-booking'); ?></th>
                                    <th><?php esc_html_e('Time', 'sn-appointment-booking'); ?></th>
                                    <th><?php esc_html_e('Type', 'sn-appointment-booking'); ?></th>
                                    <th><?php esc_html_e('Client', 'sn-appointment-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_appointments as $apt): ?>
                                    <tr>
                                        <td><?php echo esc_html(snab_format_date($apt->appointment_date)); ?></td>
                                        <td><?php echo esc_html(snab_format_time($apt->appointment_date, $apt->start_time)); ?></td>
                                        <td>
                                            <span class="snab-type-badge" style="background-color: <?php echo esc_attr($apt->color); ?>">
                                                <?php echo esc_html($apt->type_name); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($apt->client_name); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Google Calendar Status -->
                <?php
                $gcal = snab_google_calendar();
                $gcal_status = $gcal->get_connection_status();
                ?>
                <div class="snab-section snab-gcal-status-section">
                    <h2><?php esc_html_e('Google Calendar', 'sn-appointment-booking'); ?></h2>
                    <?php if ($gcal_status['connected']): ?>
                        <div class="snab-gcal-connected">
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <strong><?php esc_html_e('Connected', 'sn-appointment-booking'); ?></strong>
                            <?php if ($gcal_status['calendar_name']): ?>
                                <span style="margin-left: 10px; color: #666;">
                                    <?php echo esc_html($gcal_status['calendar_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($gcal_status['configured']): ?>
                        <div class="snab-gcal-not-connected">
                            <span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
                            <strong><?php esc_html_e('Not Connected', 'sn-appointment-booking'); ?></strong>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snab-settings')); ?>" class="button button-small" style="margin-left: 10px;">
                                <?php esc_html_e('Connect Now', 'sn-appointment-booking'); ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="snab-gcal-not-configured">
                            <span class="dashicons dashicons-info" style="color: #00a0d2;"></span>
                            <strong><?php esc_html_e('Not Configured', 'sn-appointment-booking'); ?></strong>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snab-settings')); ?>" class="button button-small" style="margin-left: 10px;">
                                <?php esc_html_e('Set Up', 'sn-appointment-booking'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Database Status -->
                <?php $db_status = SNAB_Activator::get_database_status(); ?>
                <div class="snab-section snab-db-status-section">
                    <h2><?php esc_html_e('Database Status', 'sn-appointment-booking'); ?></h2>
                    <?php
                    $all_tables_exist = !in_array(false, $db_status['tables'], true);
                    $has_missing_columns = !empty($db_status['missing_columns']);
                    $version_mismatch = $db_status['version']['installed_db'] !== $db_status['version']['db'];

                    if ($all_tables_exist && !$has_missing_columns && !$version_mismatch): ?>
                        <div class="snab-db-healthy">
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <strong><?php esc_html_e('All systems healthy', 'sn-appointment-booking'); ?></strong>
                            <span style="margin-left: 10px; color: #666;">
                                <?php echo esc_html(count($db_status['tables'])); ?> <?php esc_html_e('tables', 'sn-appointment-booking'); ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="snab-db-issues">
                            <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                            <strong><?php esc_html_e('Issues detected', 'sn-appointment-booking'); ?></strong>
                        </div>
                        <?php if (!$all_tables_exist): ?>
                            <p style="color: #dc3232; margin-left: 28px;">
                                <?php
                                $missing = array_keys(array_filter($db_status['tables'], function($v) { return !$v; }));
                                echo esc_html(sprintf(__('Missing tables: %s', 'sn-appointment-booking'), implode(', ', $missing)));
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($has_missing_columns): ?>
                            <p style="color: #dc3232; margin-left: 28px;">
                                <?php esc_html_e('Missing columns detected in some tables', 'sn-appointment-booking'); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($version_mismatch): ?>
                            <p style="color: #ffb900; margin-left: 28px;">
                                <?php echo esc_html(sprintf(
                                    __('DB version mismatch (installed: %s, expected: %s)', 'sn-appointment-booking'),
                                    $db_status['version']['installed_db'],
                                    $db_status['version']['db']
                                )); ?>
                            </p>
                        <?php endif; ?>
                        <form method="post" action="" style="margin-left: 28px; margin-top: 10px;">
                            <?php wp_nonce_field('snab_repair_db', 'snab_repair_nonce'); ?>
                            <input type="hidden" name="snab_action" value="repair_database" />
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e('Repair Database', 'sn-appointment-booking'); ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- Table row counts -->
                    <div style="margin-top: 15px;">
                        <table class="widefat" style="max-width: 400px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Table', 'sn-appointment-booking'); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e('Rows', 'sn-appointment-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($db_status['row_counts'] as $table => $count): ?>
                                    <tr>
                                        <td>
                                            <?php if ($db_status['tables'][$table]): ?>
                                                <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                                            <?php else: ?>
                                                <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                                            <?php endif; ?>
                                            <?php echo esc_html($table); ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php echo $count !== null ? esc_html(number_format($count)) : '—'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="snab-section">
                    <h2><?php esc_html_e('Quick Links', 'sn-appointment-booking'); ?></h2>
                    <div class="snab-quick-links">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=snab-availability')); ?>" class="button button-secondary">
                            <?php esc_html_e('Set Availability', 'sn-appointment-booking'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=snab-types')); ?>" class="button button-secondary">
                            <?php esc_html_e('Manage Types', 'sn-appointment-booking'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=snab-settings')); ?>" class="button button-secondary">
                            <?php esc_html_e('Settings', 'sn-appointment-booking'); ?>
                        </a>
                    </div>
                </div>

                <!-- Version Info -->
                <div class="snab-section snab-version-info">
                    <p>
                        <strong><?php esc_html_e('Version:', 'sn-appointment-booking'); ?></strong>
                        <?php echo esc_html($versions['plugin_version']); ?>
                        &nbsp;|&nbsp;
                        <strong><?php esc_html_e('DB Version:', 'sn-appointment-booking'); ?></strong>
                        <?php echo esc_html($versions['installed_db_version']); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render appointments page.
     */
    public function render_appointments() {
        $this->admin_appointments->render();
    }

    /**
     * Render calendar page.
     *
     * @since 1.6.0
     */
    public function render_calendar() {
        $this->admin_calendar->render();
    }

    /**
     * Render availability page.
     */
    public function render_availability() {
        $this->admin_availability->render();
    }

    /**
     * Render appointment types page.
     */
    public function render_types() {
        $this->admin_types->render();
    }

    /**
     * Render staff management page.
     *
     * @since 1.6.0
     */
    public function render_staff() {
        $this->admin_staff->render();
    }

    /**
     * Render settings page.
     */
    public function render_settings() {
        $this->admin_settings->render();
    }

    /**
     * Render shortcode presets page.
     */
    public function render_presets() {
        $this->admin_presets->render();
    }

    /**
     * Render analytics page.
     */
    public function render_analytics() {
        $this->analytics->render();
    }
}
