<?php
/**
 * MLD Update to Version 6.21.0
 * Universal Contact Form System
 *
 * Creates the contact_forms table and updates the form_submissions table
 * to support custom contact forms with dynamic fields.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.21.0 update
 *
 * @return bool True on success, false on failure
 */
function mld_update_to_6_21_0() {
    global $wpdb;

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[MLD Update 6.21.0] Starting Universal Contact Form System database update');
    }

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();
    $success = true;

    // =========================================================================
    // Step 1: Create the contact forms table
    // =========================================================================
    $table_forms = $wpdb->prefix . 'mld_contact_forms';

    $sql_forms = "CREATE TABLE $table_forms (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_name VARCHAR(255) NOT NULL,
        form_slug VARCHAR(100) NOT NULL,
        description TEXT,
        fields JSON NOT NULL COMMENT 'Array of field definitions',
        settings JSON NOT NULL COMMENT 'Form-specific settings',
        notification_settings JSON COMMENT 'Per-form notification overrides',
        status ENUM('active', 'draft', 'archived') DEFAULT 'active',
        submission_count INT UNSIGNED DEFAULT 0,
        created_by BIGINT(20) UNSIGNED,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_form_slug (form_slug),
        KEY idx_status (status),
        KEY idx_created_at (created_at)
    ) $charset_collate;";

    dbDelta($sql_forms);

    // Verify the contact forms table was created
    $forms_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_forms'");

    if (!$forms_table_exists) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.21.0] ERROR: Failed to create contact_forms table');
        }
        $success = false;
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.21.0] Successfully created contact_forms table');
        }
    }

    // =========================================================================
    // Step 2: Add form_id and form_data columns to form_submissions table
    // =========================================================================
    $table_submissions = $wpdb->prefix . 'mld_form_submissions';

    // Check if form_submissions table exists
    $submissions_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_submissions'");

    if ($submissions_table_exists) {
        // Check if form_id column already exists
        $form_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_submissions LIKE 'form_id'");

        if (empty($form_id_exists)) {
            // Add form_id column after id
            $result = $wpdb->query("ALTER TABLE $table_submissions ADD COLUMN form_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER id");

            if ($result !== false) {
                // Add index for form_id
                $wpdb->query("ALTER TABLE $table_submissions ADD KEY idx_form_id (form_id)");

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] Added form_id column to form_submissions table');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] ERROR: Failed to add form_id column');
                }
            }
        }

        // Check if form_data column already exists
        $form_data_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_submissions LIKE 'form_data'");

        if (empty($form_data_exists)) {
            // Add form_data column after form_id
            $result = $wpdb->query("ALTER TABLE $table_submissions ADD COLUMN form_data JSON COMMENT 'Dynamic field data from custom forms' AFTER form_id");

            if ($result !== false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] Added form_data column to form_submissions table');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] ERROR: Failed to add form_data column');
                }
            }
        }
    }

    // =========================================================================
    // Step 3: Create default contact form
    // =========================================================================
    if ($forms_table_exists) {
        // Check if default form already exists
        $default_exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table_forms WHERE form_slug = %s", 'default-contact-form')
        );

        if (!$default_exists) {
            // Define default form fields
            $default_fields = wp_json_encode([
                'fields' => [
                    [
                        'id' => 'field_' . wp_generate_password(8, false),
                        'type' => 'text',
                        'label' => 'First Name',
                        'placeholder' => 'Enter your first name',
                        'required' => true,
                        'validation' => [
                            'min_length' => 2,
                            'max_length' => 100
                        ],
                        'order' => 1,
                        'width' => 'half'
                    ],
                    [
                        'id' => 'field_' . wp_generate_password(8, false),
                        'type' => 'text',
                        'label' => 'Last Name',
                        'placeholder' => 'Enter your last name',
                        'required' => true,
                        'validation' => [
                            'min_length' => 2,
                            'max_length' => 100
                        ],
                        'order' => 2,
                        'width' => 'half'
                    ],
                    [
                        'id' => 'field_' . wp_generate_password(8, false),
                        'type' => 'email',
                        'label' => 'Email Address',
                        'placeholder' => 'Enter your email',
                        'required' => true,
                        'validation' => [],
                        'order' => 3,
                        'width' => 'full'
                    ],
                    [
                        'id' => 'field_' . wp_generate_password(8, false),
                        'type' => 'phone',
                        'label' => 'Phone Number',
                        'placeholder' => '(555) 123-4567',
                        'required' => false,
                        'validation' => [],
                        'order' => 4,
                        'width' => 'full'
                    ],
                    [
                        'id' => 'field_' . wp_generate_password(8, false),
                        'type' => 'textarea',
                        'label' => 'Message',
                        'placeholder' => 'How can we help you?',
                        'required' => true,
                        'validation' => [
                            'min_length' => 10,
                            'max_length' => 5000
                        ],
                        'order' => 5,
                        'width' => 'full'
                    ]
                ]
            ]);

            // Define default form settings
            $default_settings = wp_json_encode([
                'submit_button_text' => 'Send Message',
                'success_message' => 'Thank you for your message! We will get back to you soon.',
                'redirect_url' => '',
                'honeypot_enabled' => true,
                'form_layout' => 'vertical'
            ]);

            // Define default notification settings
            $default_notifications = wp_json_encode([
                'admin_email_enabled' => true,
                'admin_email_subject' => 'New Contact Form Submission - {form_name}',
                'additional_recipients' => '',
                'user_confirmation_enabled' => true,
                'user_confirmation_subject' => 'Thank you for contacting us!',
                'user_confirmation_message' => "Hi {field_first_name},\n\nThank you for reaching out to us. We have received your message and will get back to you as soon as possible.\n\nBest regards,\n{site_name}"
            ]);

            // Insert default form
            $insert_result = $wpdb->insert(
                $table_forms,
                [
                    'form_name' => 'Default Contact Form',
                    'form_slug' => 'default-contact-form',
                    'description' => 'A standard contact form with name, email, phone, and message fields.',
                    'fields' => $default_fields,
                    'settings' => $default_settings,
                    'notification_settings' => $default_notifications,
                    'status' => 'active',
                    'submission_count' => 0,
                    'created_by' => get_current_user_id() ?: 1,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
            );

            if ($insert_result) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] Created default contact form with ID: ' . $wpdb->insert_id);
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[MLD Update 6.21.0] ERROR: Failed to create default contact form: ' . $wpdb->last_error);
                }
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Update 6.21.0] Default contact form already exists');
            }
        }
    }

    // =========================================================================
    // Step 4: Update version options
    // =========================================================================
    if ($success) {
        update_option('mld_db_version', '6.21.0');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MLD Update 6.21.0] Database update completed successfully');
        }
    }

    return $success;
}

/**
 * Check if the 6.21.0 update has been applied
 *
 * @return bool True if update is needed, false if already applied
 */
function mld_needs_6_21_0_update() {
    global $wpdb;

    $table_forms = $wpdb->prefix . 'mld_contact_forms';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_forms'");

    return !$table_exists;
}
