<?php
/**
 * Plugin Activator
 *
 * Handles plugin activation including database table creation
 * and initial data population.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activator class.
 *
 * @since 1.0.0
 */
class SNAB_Activator {

    /**
     * Activate the plugin.
     *
     * Creates database tables and populates default data.
     *
     * @since 1.0.0
     */
    public static function activate() {
        self::create_tables();
        self::create_default_data();
        self::set_default_options();

        // Store the version
        update_option('snab_db_version', SNAB_DB_VERSION);
        update_option('snab_version', SNAB_VERSION);

        // Log activation
        if (class_exists('SNAB_Logger')) {
            SNAB_Logger::info('Plugin activated', array(
                'version' => SNAB_VERSION,
                'db_version' => SNAB_DB_VERSION,
            ));
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables.
     *
     * @since 1.0.0
     */
    public static function create_tables() {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Table: Staff members (includes columns from v1.6.0 and v1.6.1)
        $table_staff = $wpdb->prefix . 'snab_staff';
        $sql_staff = "CREATE TABLE {$table_staff} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            title VARCHAR(100) DEFAULT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            bio TEXT DEFAULT NULL,
            avatar_url VARCHAR(255) DEFAULT NULL,
            google_calendar_id VARCHAR(255) DEFAULT NULL,
            google_refresh_token TEXT DEFAULT NULL,
            google_access_token TEXT DEFAULT NULL,
            google_token_expires INT DEFAULT NULL,
            is_primary TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            KEY idx_user (user_id),
            KEY idx_primary (is_primary),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        dbDelta($sql_staff);

        // Table: Appointment types
        $table_types = $wpdb->prefix . 'snab_appointment_types';
        $sql_types = "CREATE TABLE {$table_types} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            duration_minutes INT NOT NULL DEFAULT 60,
            buffer_before_minutes INT DEFAULT 0,
            buffer_after_minutes INT DEFAULT 15,
            color VARCHAR(7) DEFAULT '#3788d8',
            is_active TINYINT(1) DEFAULT 1,
            requires_approval TINYINT(1) DEFAULT 0,
            requires_login TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            custom_fields JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY idx_slug (slug),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        dbDelta($sql_types);

        // Table: Availability rules
        $table_rules = $wpdb->prefix . 'snab_availability_rules';
        $sql_rules = "CREATE TABLE {$table_rules} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id BIGINT UNSIGNED NOT NULL,
            rule_type ENUM('recurring', 'specific_date', 'blocked') NOT NULL,
            day_of_week TINYINT DEFAULT NULL,
            specific_date DATE DEFAULT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            appointment_type_id BIGINT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            KEY idx_staff (staff_id),
            KEY idx_day (day_of_week),
            KEY idx_date (specific_date),
            KEY idx_type (appointment_type_id)
        ) {$charset_collate};";

        dbDelta($sql_rules);

        // Table: Appointments (includes columns from v1.1.0 and v1.5.0)
        $table_appointments = $wpdb->prefix . 'snab_appointments';
        $sql_appointments = "CREATE TABLE {$table_appointments} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id BIGINT UNSIGNED NOT NULL,
            appointment_type_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pending', 'confirmed', 'cancelled', 'completed', 'no_show') DEFAULT 'confirmed',
            appointment_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            client_name VARCHAR(100) NOT NULL,
            client_email VARCHAR(100) NOT NULL,
            client_phone VARCHAR(20) DEFAULT NULL,
            listing_id VARCHAR(50) DEFAULT NULL,
            property_address TEXT DEFAULT NULL,
            google_event_id VARCHAR(255) DEFAULT NULL,
            google_calendar_synced TINYINT(1) DEFAULT 0,
            client_notes TEXT DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            cancellation_reason TEXT DEFAULT NULL,
            cancelled_by VARCHAR(50) DEFAULT NULL,
            reminder_24h_sent TINYINT(1) DEFAULT 0,
            reminder_1h_sent TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            reschedule_count INT DEFAULT 0,
            original_datetime DATETIME DEFAULT NULL,
            rescheduled_by VARCHAR(50) DEFAULT NULL,
            reschedule_reason TEXT DEFAULT NULL,
            created_by VARCHAR(50) DEFAULT 'client',
            KEY idx_staff (staff_id),
            KEY idx_date (appointment_date),
            KEY idx_status (status),
            KEY idx_user (user_id),
            KEY idx_type (appointment_type_id),
            KEY idx_google (google_event_id),
            UNIQUE KEY unique_slot (staff_id, appointment_date, start_time)
        ) {$charset_collate};";

        dbDelta($sql_appointments);

        // Table: Shortcode Presets (added in v1.2.0)
        $table_presets = $wpdb->prefix . 'snab_shortcode_presets';
        $sql_presets = "CREATE TABLE {$table_presets} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            appointment_types TEXT DEFAULT NULL,
            allowed_days VARCHAR(50) DEFAULT NULL,
            start_hour TINYINT UNSIGNED DEFAULT NULL,
            end_hour TINYINT UNSIGNED DEFAULT NULL,
            weeks_to_show TINYINT UNSIGNED DEFAULT 2,
            default_location VARCHAR(255) DEFAULT NULL,
            custom_title VARCHAR(255) DEFAULT NULL,
            css_class VARCHAR(100) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY slug (slug),
            KEY is_active (is_active)
        ) {$charset_collate};";

        dbDelta($sql_presets);

        // Table: Notifications log
        $table_notifications = $wpdb->prefix . 'snab_notifications_log';
        $sql_notifications = "CREATE TABLE {$table_notifications} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            appointment_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            recipient_type ENUM('client', 'admin') NOT NULL,
            recipient_email VARCHAR(100) NOT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            sent_at DATETIME NOT NULL,
            status ENUM('sent', 'failed') NOT NULL,
            error_message TEXT DEFAULT NULL,
            KEY idx_appointment (appointment_id),
            KEY idx_type (notification_type)
        ) {$charset_collate};";

        dbDelta($sql_notifications);

        // Table: Staff Services (added in v1.6.0 - links staff to appointment types)
        $table_staff_services = $wpdb->prefix . 'snab_staff_services';
        $sql_staff_services = "CREATE TABLE {$table_staff_services} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id BIGINT UNSIGNED NOT NULL,
            appointment_type_id BIGINT UNSIGNED NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            UNIQUE KEY staff_type (staff_id, appointment_type_id),
            KEY staff_id (staff_id),
            KEY appointment_type_id (appointment_type_id)
        ) {$charset_collate};";

        dbDelta($sql_staff_services);

        // Table: Appointment Attendees (added in v1.10.0 - multi-attendee support)
        $table_attendees = $wpdb->prefix . 'snab_appointment_attendees';
        $sql_attendees = "CREATE TABLE {$table_attendees} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            appointment_id BIGINT UNSIGNED NOT NULL,
            attendee_type ENUM('primary', 'additional', 'cc') DEFAULT 'additional',
            user_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            reminder_24h_sent TINYINT(1) DEFAULT 0,
            reminder_1h_sent TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_appointment (appointment_id),
            KEY idx_email (email),
            KEY idx_user (user_id)
        ) {$charset_collate};";

        dbDelta($sql_attendees);

        // Log table creation
        if (class_exists('SNAB_Logger')) {
            SNAB_Logger::info('Database tables created/updated');
        }
    }

    /**
     * Create default data.
     *
     * Populates initial staff record and appointment types.
     *
     * @since 1.0.0
     */
    public static function create_default_data() {
        global $wpdb;

        $now = current_time('mysql');

        // Create default staff member (use site admin)
        $table_staff = $wpdb->prefix . 'snab_staff';
        $staff_exists = $wpdb->get_var("SELECT COUNT(*) FROM {$table_staff}");

        if (!$staff_exists) {
            $admin_user = get_user_by('email', get_option('admin_email'));
            $admin_name = $admin_user ? $admin_user->display_name : 'Site Admin';

            $wpdb->insert(
                $table_staff,
                array(
                    'user_id' => $admin_user ? $admin_user->ID : null,
                    'name' => $admin_name,
                    'email' => get_option('admin_email'),
                    'is_primary' => 1,
                    'is_active' => 1,
                    'created_at' => $now,
                ),
                array('%d', '%s', '%s', '%d', '%d', '%s')
            );

            if (class_exists('SNAB_Logger')) {
                SNAB_Logger::info('Default staff member created', array('email' => get_option('admin_email')));
            }
        }

        // Create default appointment types
        $table_types = $wpdb->prefix . 'snab_appointment_types';
        $types_exist = $wpdb->get_var("SELECT COUNT(*) FROM {$table_types}");

        if (!$types_exist) {
            $default_types = array(
                array(
                    'name' => 'Property Showing',
                    'slug' => 'property-showing',
                    'description' => 'Schedule a showing for a property you are interested in.',
                    'duration_minutes' => 30,
                    'buffer_after_minutes' => 15,
                    'color' => '#4CAF50',
                    'sort_order' => 1,
                ),
                array(
                    'name' => 'Buyer Consultation',
                    'slug' => 'buyer-consultation',
                    'description' => 'Meet to discuss your home buying needs and preferences.',
                    'duration_minutes' => 60,
                    'buffer_after_minutes' => 15,
                    'color' => '#2196F3',
                    'sort_order' => 2,
                ),
                array(
                    'name' => 'Seller Consultation',
                    'slug' => 'seller-consultation',
                    'description' => 'Discuss selling your home and get a market analysis.',
                    'duration_minutes' => 60,
                    'buffer_after_minutes' => 15,
                    'color' => '#9C27B0',
                    'sort_order' => 3,
                ),
                array(
                    'name' => 'Listing Presentation',
                    'slug' => 'listing-presentation',
                    'description' => 'Full listing presentation for your property.',
                    'duration_minutes' => 90,
                    'buffer_after_minutes' => 30,
                    'color' => '#FF9800',
                    'sort_order' => 4,
                ),
                array(
                    'name' => 'Home Valuation',
                    'slug' => 'home-valuation',
                    'description' => 'Get a professional valuation of your home.',
                    'duration_minutes' => 45,
                    'buffer_after_minutes' => 15,
                    'color' => '#00BCD4',
                    'sort_order' => 5,
                ),
                array(
                    'name' => 'General Consultation',
                    'slug' => 'general-consultation',
                    'description' => 'General real estate consultation and questions.',
                    'duration_minutes' => 30,
                    'buffer_after_minutes' => 15,
                    'color' => '#607D8B',
                    'sort_order' => 6,
                ),
            );

            foreach ($default_types as $type) {
                $wpdb->insert(
                    $table_types,
                    array(
                        'name' => $type['name'],
                        'slug' => $type['slug'],
                        'description' => $type['description'],
                        'duration_minutes' => $type['duration_minutes'],
                        'buffer_before_minutes' => 0,
                        'buffer_after_minutes' => $type['buffer_after_minutes'],
                        'color' => $type['color'],
                        'is_active' => 1,
                        'requires_approval' => 0,
                        'requires_login' => 0,
                        'sort_order' => $type['sort_order'],
                        'created_at' => $now,
                    ),
                    array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s')
                );
            }

            if (class_exists('SNAB_Logger')) {
                SNAB_Logger::info('Default appointment types created', array('count' => count($default_types)));
            }
        }

        // Create default availability rules (Mon-Fri 9am-5pm)
        $table_rules = $wpdb->prefix . 'snab_availability_rules';
        $rules_exist = $wpdb->get_var("SELECT COUNT(*) FROM {$table_rules}");

        if (!$rules_exist) {
            // Get the primary staff ID
            $staff_id = $wpdb->get_var("SELECT id FROM {$table_staff} WHERE is_primary = 1 LIMIT 1");

            if ($staff_id) {
                // Monday through Friday, 9am to 5pm
                for ($day = 1; $day <= 5; $day++) {
                    $wpdb->insert(
                        $table_rules,
                        array(
                            'staff_id' => $staff_id,
                            'rule_type' => 'recurring',
                            'day_of_week' => $day,
                            'start_time' => '09:00:00',
                            'end_time' => '17:00:00',
                            'is_active' => 1,
                            'created_at' => $now,
                        ),
                        array('%d', '%s', '%d', '%s', '%s', '%d', '%s')
                    );
                }

                if (class_exists('SNAB_Logger')) {
                    SNAB_Logger::info('Default availability rules created (Mon-Fri 9am-5pm)');
                }
            }
        }

        // Link staff to appointment types (v1.6.0 feature)
        $table_staff_services = $wpdb->prefix . 'snab_staff_services';
        $staff_services_exist = $wpdb->get_var("SELECT COUNT(*) FROM {$table_staff_services}");

        if (!$staff_services_exist) {
            $staff = $wpdb->get_results("SELECT id FROM {$table_staff} WHERE is_active = 1");
            $types = $wpdb->get_results("SELECT id FROM {$table_types}");

            if ($staff && $types) {
                foreach ($staff as $s) {
                    foreach ($types as $t) {
                        $wpdb->replace(
                            $table_staff_services,
                            array(
                                'staff_id' => $s->id,
                                'appointment_type_id' => $t->id,
                                'is_active' => 1,
                                'created_at' => $now,
                            ),
                            array('%d', '%d', '%d', '%s')
                        );
                    }
                }

                if (class_exists('SNAB_Logger')) {
                    SNAB_Logger::info('Staff linked to appointment types');
                }
            }
        }
    }

    /**
     * Set default options.
     *
     * @since 1.0.0
     */
    public static function set_default_options() {
        $default_settings = array(
            'business_name' => get_bloginfo('name'),
            'admin_email' => get_option('admin_email'),
            'timezone' => wp_timezone_string(),
            'default_buffer_minutes' => 15,
            'max_advance_days' => 60,
            'min_advance_hours' => 2,
            'require_phone' => true,
            'terms_enabled' => false,
            'terms_text' => '',
        );

        // Only set if not already exists
        if (!get_option('snab_settings')) {
            add_option('snab_settings', $default_settings);
        }

        // Email templates (Phase 6)
        if (!get_option('snab_email_templates')) {
            add_option('snab_email_templates', array());
        }

        // Client portal options (v1.5.0)
        $portal_options = array(
            'snab_enable_client_portal' => '1',
            'snab_cancellation_hours_before' => '24',
            'snab_reschedule_hours_before' => '24',
            'snab_max_reschedules_per_appointment' => '2',
            'snab_require_cancel_reason' => '1',
            'snab_notify_admin_on_client_changes' => '1',
        );

        foreach ($portal_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }

        // Notification preferences (v1.6.0)
        $notification_options = array(
            'snab_notify_new_booking' => true,
            'snab_notify_cancellation' => true,
            'snab_notify_reschedule' => true,
            'snab_notify_reminder' => false,
            'snab_notification_email' => get_option('admin_email'),
            'snab_notification_frequency' => 'instant',
            'snab_secondary_notification_email' => '',
            'snab_staff_selection_mode' => 'auto',
        );

        foreach ($notification_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }

        // Notification templates for client portal (v1.5.0)
        $notification_templates = array(
            'snab_client_cancel_admin_subject' => 'Client Cancelled Appointment: {appointment_type}',
            'snab_client_cancel_admin_body' => "Hello,\n\nA client has cancelled their appointment.\n\n<strong>Details:</strong>\n- Client: {client_name}\n- Email: {client_email}\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\n<strong>Cancellation Reason:</strong>\n{cancellation_reason}\n\nThis appointment has been removed from your calendar.\n\nBest regards,\n{site_name}",
            'snab_client_reschedule_admin_subject' => 'Client Rescheduled Appointment: {appointment_type}',
            'snab_client_reschedule_admin_body' => "Hello,\n\nA client has rescheduled their appointment.\n\n<strong>Client:</strong> {client_name} ({client_email})\n\n<strong>Original:</strong>\n- Date: {old_date}\n- Time: {old_time}\n\n<strong>New:</strong>\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\nYour calendar has been updated automatically.\n\nBest regards,\n{site_name}",
            'snab_client_cancel_client_subject' => 'Appointment Cancelled: {appointment_type}',
            'snab_client_cancel_client_body' => "Hello {client_name},\n\nYour appointment has been successfully cancelled.\n\n<strong>Cancelled Appointment:</strong>\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\nIf you would like to book a new appointment, please visit our website.\n\nBest regards,\n{site_name}",
            'snab_client_reschedule_client_subject' => 'Appointment Rescheduled: {appointment_type}',
            'snab_client_reschedule_client_body' => "Hello {client_name},\n\nYour appointment has been successfully rescheduled.\n\n<strong>New Appointment Details:</strong>\n- Type: {appointment_type}\n- Date: {appointment_date}\n- Time: {start_time} - {end_time}\n\n<strong>Previous Time:</strong>\n- Date: {old_date}\n- Time: {old_time}\n\nWe look forward to seeing you!\n\nBest regards,\n{site_name}",
        );

        foreach ($notification_templates as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    /**
     * Verify tables exist.
     *
     * @since 1.0.0
     * @return array Array of table statuses.
     */
    public static function verify_tables() {
        global $wpdb;

        $tables = array(
            'snab_staff',
            'snab_appointment_types',
            'snab_availability_rules',
            'snab_appointments',
            'snab_notifications_log',
            'snab_shortcode_presets',
            'snab_staff_services',
            'snab_appointment_attendees',
        );

        $status = array();

        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'") === $full_table;
            $status[$table] = $exists;
        }

        return $status;
    }

    /**
     * Verify all required columns exist in tables.
     *
     * @since 1.4.1
     * @return array Array of missing columns by table.
     */
    public static function verify_columns() {
        global $wpdb;

        $missing = array();

        // Required columns for staff table (includes v1.6.0 and v1.6.1 additions)
        $staff_table = $wpdb->prefix . 'snab_staff';
        $required_staff_cols = array(
            'id', 'user_id', 'name', 'title', 'email', 'phone', 'bio', 'avatar_url',
            'google_calendar_id', 'google_refresh_token', 'google_access_token',
            'google_token_expires', 'is_primary', 'is_active', 'created_at', 'updated_at'
        );

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$staff_table}'");
        if ($table_exists) {
            $existing_cols = $wpdb->get_col("DESCRIBE {$staff_table}");
            $missing_cols = array_diff($required_staff_cols, $existing_cols);
            if (!empty($missing_cols)) {
                $missing['snab_staff'] = $missing_cols;
            }
        }

        // Required columns for appointment_types table (includes v1.6.0 color column)
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $required_type_cols = array(
            'id', 'name', 'slug', 'description', 'duration_minutes', 'buffer_before_minutes',
            'buffer_after_minutes', 'color', 'is_active', 'requires_approval', 'requires_login',
            'sort_order', 'custom_fields', 'created_at', 'updated_at'
        );

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$types_table}'");
        if ($table_exists) {
            $existing_cols = $wpdb->get_col("DESCRIBE {$types_table}");
            $missing_cols = array_diff($required_type_cols, $existing_cols);
            if (!empty($missing_cols)) {
                $missing['snab_appointment_types'] = $missing_cols;
            }
        }

        // Required columns for appointments table (includes v1.1.0 and v1.5.0 additions)
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $required_appointment_cols = array(
            'id', 'staff_id', 'appointment_type_id', 'status', 'appointment_date',
            'start_time', 'end_time', 'user_id', 'client_name', 'client_email',
            'client_phone', 'listing_id', 'property_address', 'google_event_id',
            'google_calendar_synced', 'client_notes', 'admin_notes', 'cancellation_reason',
            'cancelled_by', 'reminder_24h_sent', 'reminder_1h_sent', 'created_at', 'updated_at',
            'cancelled_at', 'reschedule_count', 'original_datetime', 'rescheduled_by',
            'reschedule_reason', 'created_by'
        );

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$appointments_table}'");
        if ($table_exists) {
            $existing_cols = $wpdb->get_col("DESCRIBE {$appointments_table}");
            $missing_cols = array_diff($required_appointment_cols, $existing_cols);
            if (!empty($missing_cols)) {
                $missing['snab_appointments'] = $missing_cols;
            }
        }

        // Required columns for appointment_attendees table (v1.10.0)
        $attendees_table = $wpdb->prefix . 'snab_appointment_attendees';
        $required_attendee_cols = array(
            'id', 'appointment_id', 'attendee_type', 'user_id', 'name', 'email',
            'phone', 'reminder_24h_sent', 'reminder_1h_sent', 'created_at'
        );

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$attendees_table}'");
        if ($table_exists) {
            $existing_cols = $wpdb->get_col("DESCRIBE {$attendees_table}");
            $missing_cols = array_diff($required_attendee_cols, $existing_cols);
            if (!empty($missing_cols)) {
                $missing['snab_appointment_attendees'] = $missing_cols;
            }
        }

        return $missing;
    }

    /**
     * Repair missing tables and columns.
     *
     * @since 1.4.1
     * @return array Results of repair operations.
     */
    public static function repair_database() {
        global $wpdb;

        $results = array(
            'tables_created' => array(),
            'columns_added' => array(),
            'options_set' => array(),
            'errors' => array(),
        );

        // First, create any missing tables using dbDelta
        $table_status = self::verify_tables();
        $missing_tables = array_keys(array_filter($table_status, function($exists) {
            return !$exists;
        }));

        if (!empty($missing_tables)) {
            self::create_tables();
            $results['tables_created'] = $missing_tables;
        }

        // Check and add missing columns for staff table
        $staff_table = $wpdb->prefix . 'snab_staff';
        $staff_columns = $wpdb->get_col("DESCRIBE {$staff_table}");

        $staff_column_defs = array(
            'title' => "ALTER TABLE {$staff_table} ADD COLUMN title VARCHAR(100) DEFAULT NULL AFTER name",
            'bio' => "ALTER TABLE {$staff_table} ADD COLUMN bio TEXT DEFAULT NULL AFTER phone",
            'avatar_url' => "ALTER TABLE {$staff_table} ADD COLUMN avatar_url VARCHAR(255) DEFAULT NULL AFTER bio",
            'google_access_token' => "ALTER TABLE {$staff_table} ADD COLUMN google_access_token TEXT DEFAULT NULL AFTER google_refresh_token",
            'google_token_expires' => "ALTER TABLE {$staff_table} ADD COLUMN google_token_expires INT DEFAULT NULL AFTER google_access_token",
        );

        foreach ($staff_column_defs as $col => $sql) {
            if (!in_array($col, $staff_columns)) {
                $result = $wpdb->query($sql);
                if ($result !== false) {
                    $results['columns_added'][] = "snab_staff.{$col}";
                } else {
                    $results['errors'][] = "Failed to add column: snab_staff.{$col} - " . $wpdb->last_error;
                }
            }
        }

        // Check and add missing columns for appointment_types table
        $types_table = $wpdb->prefix . 'snab_appointment_types';
        $types_columns = $wpdb->get_col("DESCRIBE {$types_table}");

        $types_column_defs = array(
            'color' => "ALTER TABLE {$types_table} ADD COLUMN color VARCHAR(7) DEFAULT '#3788d8' AFTER description",
        );

        foreach ($types_column_defs as $col => $sql) {
            if (!in_array($col, $types_columns)) {
                $result = $wpdb->query($sql);
                if ($result !== false) {
                    $results['columns_added'][] = "snab_appointment_types.{$col}";
                } else {
                    $results['errors'][] = "Failed to add column: snab_appointment_types.{$col} - " . $wpdb->last_error;
                }
            }
        }

        // Check and add missing columns for appointments table
        $appointments_table = $wpdb->prefix . 'snab_appointments';
        $appointments_columns = $wpdb->get_col("DESCRIBE {$appointments_table}");

        $appointments_column_defs = array(
            'cancelled_by' => "ALTER TABLE {$appointments_table} ADD COLUMN cancelled_by VARCHAR(50) DEFAULT NULL AFTER cancellation_reason",
            'reschedule_count' => "ALTER TABLE {$appointments_table} ADD COLUMN reschedule_count INT DEFAULT 0 AFTER cancelled_at",
            'original_datetime' => "ALTER TABLE {$appointments_table} ADD COLUMN original_datetime DATETIME DEFAULT NULL AFTER reschedule_count",
            'rescheduled_by' => "ALTER TABLE {$appointments_table} ADD COLUMN rescheduled_by VARCHAR(50) DEFAULT NULL AFTER original_datetime",
            'reschedule_reason' => "ALTER TABLE {$appointments_table} ADD COLUMN reschedule_reason TEXT DEFAULT NULL AFTER rescheduled_by",
            'created_by' => "ALTER TABLE {$appointments_table} ADD COLUMN created_by VARCHAR(50) DEFAULT 'client' AFTER reschedule_reason",
        );

        foreach ($appointments_column_defs as $col => $sql) {
            if (!in_array($col, $appointments_columns)) {
                $result = $wpdb->query($sql);
                if ($result !== false) {
                    $results['columns_added'][] = "snab_appointments.{$col}";
                } else {
                    $results['errors'][] = "Failed to add column: snab_appointments.{$col} - " . $wpdb->last_error;
                }
            }
        }

        // Set any missing default options
        $default_options = array(
            // v1.5.0 options
            'snab_enable_client_portal' => '1',
            'snab_cancellation_hours_before' => '24',
            'snab_reschedule_hours_before' => '24',
            'snab_max_reschedules_per_appointment' => '2',
            'snab_require_cancel_reason' => '1',
            'snab_notify_admin_on_client_changes' => '1',
            // v1.6.0 options
            'snab_notify_new_booking' => true,
            'snab_notify_cancellation' => true,
            'snab_notify_reschedule' => true,
            'snab_notify_reminder' => false,
            'snab_notification_email' => get_option('admin_email'),
            'snab_notification_frequency' => 'instant',
            'snab_secondary_notification_email' => '',
            'snab_staff_selection_mode' => 'auto',
        );

        foreach ($default_options as $option => $default) {
            if (get_option($option) === false) {
                update_option($option, $default);
                $results['options_set'][] = $option;
            }
        }

        return $results;
    }

    /**
     * Get comprehensive database status.
     *
     * @since 1.4.1
     * @return array Database status information.
     */
    public static function get_database_status() {
        global $wpdb;

        $status = array(
            'tables' => self::verify_tables(),
            'missing_columns' => self::verify_columns(),
            'version' => array(
                'plugin' => defined('SNAB_VERSION') ? SNAB_VERSION : 'unknown',
                'db' => defined('SNAB_DB_VERSION') ? SNAB_DB_VERSION : 'unknown',
                'installed_plugin' => get_option('snab_version', 'not set'),
                'installed_db' => get_option('snab_db_version', 'not set'),
            ),
            'row_counts' => array(),
        );

        // Get row counts for each table
        foreach (array_keys($status['tables']) as $table) {
            $full_table = $wpdb->prefix . $table;
            if ($status['tables'][$table]) {
                $status['row_counts'][$table] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_table}");
            } else {
                $status['row_counts'][$table] = null;
            }
        }

        return $status;
    }
}
