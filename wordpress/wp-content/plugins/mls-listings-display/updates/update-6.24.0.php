<?php
/**
 * MLS Listings Display - Update to 6.24.0
 *
 * Creates database tables for:
 * - File uploads (wp_mld_form_uploads)
 * - Form templates (wp_mld_form_templates)
 *
 * @package MLS_Listings_Display
 * @since 6.24.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Run the 6.24.0 update
 *
 * @return bool True on success
 */
function mld_update_to_6_24_0() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $charset_collate = $wpdb->get_charset_collate();
    $success = true;

    // Create form uploads table
    $uploads_table = $wpdb->prefix . 'mld_form_uploads';
    $sql_uploads = "CREATE TABLE {$uploads_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        submission_id BIGINT UNSIGNED NULL,
        form_id BIGINT UNSIGNED NOT NULL,
        field_id VARCHAR(50) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        attachment_id BIGINT UNSIGNED NULL,
        upload_token VARCHAR(64) NULL,
        uploaded_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_form_id (form_id),
        INDEX idx_submission_id (submission_id),
        INDEX idx_upload_token (upload_token)
    ) {$charset_collate};";

    dbDelta($sql_uploads);

    // Verify uploads table
    $uploads_exists = $wpdb->get_var("SHOW TABLES LIKE '{$uploads_table}'");
    if (!$uploads_exists) {
        error_log("MLD Update 6.24.0: Failed to create {$uploads_table}");
        $success = false;
    }

    // Create form templates table
    $templates_table = $wpdb->prefix . 'mld_form_templates';
    $sql_templates = "CREATE TABLE {$templates_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_name VARCHAR(255) NOT NULL,
        template_slug VARCHAR(100) NOT NULL,
        category VARCHAR(100) DEFAULT 'general',
        description TEXT NULL,
        preview_image VARCHAR(500) NULL,
        fields JSON NOT NULL,
        settings JSON NOT NULL,
        is_system TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        usage_count INT UNSIGNED DEFAULT 0,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY idx_template_slug (template_slug),
        INDEX idx_category (category),
        INDEX idx_is_system (is_system)
    ) {$charset_collate};";

    dbDelta($sql_templates);

    // Verify templates table
    $templates_exists = $wpdb->get_var("SHOW TABLES LIKE '{$templates_table}'");
    if (!$templates_exists) {
        error_log("MLD Update 6.24.0: Failed to create {$templates_table}");
        $success = false;
    }

    // Insert default system templates if table is empty
    if ($templates_exists) {
        $template_count = $wpdb->get_var("SELECT COUNT(*) FROM {$templates_table}");
        if ($template_count == 0) {
            mld_insert_default_templates();
        }
    }

    // Update version
    if ($success) {
        update_option('mld_db_version', '6.24.0');
        error_log("MLD Update 6.24.0: Successfully completed");
    }

    return $success;
}

/**
 * Insert default system templates
 */
function mld_insert_default_templates() {
    global $wpdb;
    $templates_table = $wpdb->prefix . 'mld_form_templates';
    $now = current_time('mysql');

    // Template 1: Buyer Intake (4 steps)
    $buyer_intake = [
        'template_name' => 'Buyer Intake Form',
        'template_slug' => 'buyer-intake',
        'category' => 'real-estate',
        'description' => 'Comprehensive buyer qualification form with property preferences, timeline, and financial readiness.',
        'fields' => json_encode(['fields' => [
            // Step 1: Contact Information
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Full Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'full'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => true, 'step' => 1, 'order' => 3, 'width' => 'half'],
            ['id' => 'field_contact_method', 'type' => 'dropdown', 'label' => 'Preferred Contact Method', 'required' => false, 'step' => 1, 'order' => 4, 'width' => 'full', 'options' => ['Phone Call', 'Text Message', 'Email']],
            // Step 2: Property Preferences
            ['id' => 'field_section_prefs', 'type' => 'section', 'label' => 'Property Preferences', 'step' => 2, 'order' => 5],
            ['id' => 'field_property_type', 'type' => 'dropdown', 'label' => 'Property Type', 'required' => true, 'step' => 2, 'order' => 6, 'width' => 'half', 'options' => ['Single Family Home', 'Condo/Townhouse', 'Multi-Family', 'Land/Lot']],
            ['id' => 'field_bedrooms', 'type' => 'dropdown', 'label' => 'Minimum Bedrooms', 'required' => true, 'step' => 2, 'order' => 7, 'width' => 'half', 'options' => ['1', '2', '3', '4', '5+']],
            ['id' => 'field_bathrooms', 'type' => 'dropdown', 'label' => 'Minimum Bathrooms', 'required' => true, 'step' => 2, 'order' => 8, 'width' => 'half', 'options' => ['1', '1.5', '2', '2.5', '3+']],
            ['id' => 'field_locations', 'type' => 'textarea', 'label' => 'Preferred Locations/Neighborhoods', 'required' => false, 'step' => 2, 'order' => 9, 'width' => 'full', 'placeholder' => 'List cities, neighborhoods, or areas you are interested in'],
            ['id' => 'field_must_haves', 'type' => 'checkbox', 'label' => 'Must-Have Features', 'required' => false, 'step' => 2, 'order' => 10, 'width' => 'full', 'options' => ['Garage', 'Pool', 'Basement', 'Updated Kitchen', 'Large Yard', 'Home Office']],
            // Step 3: Timeline & Motivation
            ['id' => 'field_section_timeline', 'type' => 'section', 'label' => 'Timeline & Motivation', 'step' => 3, 'order' => 11],
            ['id' => 'field_timeline', 'type' => 'dropdown', 'label' => 'When are you looking to buy?', 'required' => true, 'step' => 3, 'order' => 12, 'width' => 'full', 'options' => ['Immediately (0-30 days)', '1-3 months', '3-6 months', '6-12 months', 'Just browsing']],
            ['id' => 'field_first_time', 'type' => 'radio', 'label' => 'Are you a first-time home buyer?', 'required' => true, 'step' => 3, 'order' => 13, 'width' => 'full', 'options' => ['Yes', 'No']],
            ['id' => 'field_reason', 'type' => 'dropdown', 'label' => 'Reason for buying', 'required' => false, 'step' => 3, 'order' => 14, 'width' => 'full', 'options' => ['Primary Residence', 'Investment Property', 'Vacation Home', 'Relocating for Work', 'Downsizing', 'Upsizing']],
            // Step 4: Financial Readiness
            ['id' => 'field_section_financial', 'type' => 'section', 'label' => 'Financial Readiness', 'step' => 4, 'order' => 15],
            ['id' => 'field_budget', 'type' => 'dropdown', 'label' => 'Budget Range', 'required' => true, 'step' => 4, 'order' => 16, 'width' => 'full', 'options' => ['Under $200,000', '$200,000 - $350,000', '$350,000 - $500,000', '$500,000 - $750,000', '$750,000 - $1,000,000', 'Over $1,000,000']],
            ['id' => 'field_preapproved', 'type' => 'radio', 'label' => 'Are you pre-approved for a mortgage?', 'required' => true, 'step' => 4, 'order' => 17, 'width' => 'full', 'options' => ['Yes', 'No', 'In Process']],
            ['id' => 'field_down_payment', 'type' => 'dropdown', 'label' => 'Estimated Down Payment', 'required' => false, 'step' => 4, 'order' => 18, 'width' => 'full', 'options' => ['Less than 5%', '5-10%', '10-20%', '20% or more', 'Cash purchase']],
            ['id' => 'field_comments', 'type' => 'textarea', 'label' => 'Additional Comments', 'required' => false, 'step' => 4, 'order' => 19, 'width' => 'full', 'placeholder' => 'Any other details you would like to share?'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Submit Application',
            'success_message' => 'Thank you for your buyer intake form! A member of our team will contact you within 24 hours to discuss your home search.',
            'multistep_enabled' => true,
            'multistep_progress_type' => 'steps',
            'multistep_show_step_titles' => true,
            'multistep_prev_button_text' => 'Previous',
            'multistep_next_button_text' => 'Next',
            'steps' => [
                ['title' => 'Contact Info', 'description' => 'Your contact details'],
                ['title' => 'Preferences', 'description' => 'What you are looking for'],
                ['title' => 'Timeline', 'description' => 'When and why'],
                ['title' => 'Financial', 'description' => 'Budget and readiness']
            ]
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Template 2: Seller Intake (3 steps)
    $seller_intake = [
        'template_name' => 'Seller Intake Form',
        'template_slug' => 'seller-intake',
        'category' => 'real-estate',
        'description' => 'Gather property information and seller motivation for listing consultations.',
        'fields' => json_encode(['fields' => [
            // Step 1: Contact Information
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Full Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'full'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => true, 'step' => 1, 'order' => 3, 'width' => 'half'],
            // Step 2: Property Information
            ['id' => 'field_section_property', 'type' => 'section', 'label' => 'Property Information', 'step' => 2, 'order' => 4],
            ['id' => 'field_address', 'type' => 'textarea', 'label' => 'Property Address', 'required' => true, 'step' => 2, 'order' => 5, 'width' => 'full', 'placeholder' => 'Street, City, State, ZIP'],
            ['id' => 'field_property_type', 'type' => 'dropdown', 'label' => 'Property Type', 'required' => true, 'step' => 2, 'order' => 6, 'width' => 'half', 'options' => ['Single Family Home', 'Condo/Townhouse', 'Multi-Family', 'Land/Lot']],
            ['id' => 'field_year_built', 'type' => 'number', 'label' => 'Year Built', 'required' => false, 'step' => 2, 'order' => 7, 'width' => 'half'],
            ['id' => 'field_bedrooms', 'type' => 'dropdown', 'label' => 'Bedrooms', 'required' => true, 'step' => 2, 'order' => 8, 'width' => 'half', 'options' => ['1', '2', '3', '4', '5+']],
            ['id' => 'field_bathrooms', 'type' => 'dropdown', 'label' => 'Bathrooms', 'required' => true, 'step' => 2, 'order' => 9, 'width' => 'half', 'options' => ['1', '1.5', '2', '2.5', '3+']],
            ['id' => 'field_sqft', 'type' => 'number', 'label' => 'Approximate Square Footage', 'required' => false, 'step' => 2, 'order' => 10, 'width' => 'half'],
            ['id' => 'field_lot_size', 'type' => 'text', 'label' => 'Lot Size', 'required' => false, 'step' => 2, 'order' => 11, 'width' => 'half', 'placeholder' => 'e.g., 0.25 acres'],
            ['id' => 'field_features', 'type' => 'checkbox', 'label' => 'Property Features', 'required' => false, 'step' => 2, 'order' => 12, 'width' => 'full', 'options' => ['Pool', 'Garage', 'Basement', 'Updated Kitchen', 'New Roof', 'Central AC']],
            // Step 3: Selling Details
            ['id' => 'field_section_selling', 'type' => 'section', 'label' => 'Selling Details', 'step' => 3, 'order' => 13],
            ['id' => 'field_timeline', 'type' => 'dropdown', 'label' => 'When do you need to sell?', 'required' => true, 'step' => 3, 'order' => 14, 'width' => 'full', 'options' => ['ASAP', '1-3 months', '3-6 months', '6+ months', 'Just exploring options']],
            ['id' => 'field_reason', 'type' => 'dropdown', 'label' => 'Reason for selling', 'required' => false, 'step' => 3, 'order' => 15, 'width' => 'full', 'options' => ['Relocating', 'Upgrading', 'Downsizing', 'Investment', 'Divorce', 'Estate Sale', 'Other']],
            ['id' => 'field_price_expectation', 'type' => 'text', 'label' => 'Price Expectation (if any)', 'required' => false, 'step' => 3, 'order' => 16, 'width' => 'full', 'placeholder' => 'e.g., $450,000'],
            ['id' => 'field_mortgage', 'type' => 'radio', 'label' => 'Is there a mortgage on the property?', 'required' => false, 'step' => 3, 'order' => 17, 'width' => 'full', 'options' => ['Yes', 'No']],
            ['id' => 'field_comments', 'type' => 'textarea', 'label' => 'Additional Information', 'required' => false, 'step' => 3, 'order' => 18, 'width' => 'full', 'placeholder' => 'Any recent updates, repairs needed, or other details?'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Request Consultation',
            'success_message' => 'Thank you! We will contact you soon to schedule a listing consultation.',
            'multistep_enabled' => true,
            'multistep_progress_type' => 'steps',
            'multistep_show_step_titles' => true,
            'steps' => [
                ['title' => 'Contact Info', 'description' => ''],
                ['title' => 'Property Details', 'description' => ''],
                ['title' => 'Selling Goals', 'description' => '']
            ]
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Template 3: Tenant Application (4 steps)
    $tenant_application = [
        'template_name' => 'Tenant Application',
        'template_slug' => 'tenant-application',
        'category' => 'real-estate',
        'description' => 'Rental application with personal info, employment, rental history, and references.',
        'fields' => json_encode(['fields' => [
            // Step 1: Personal Information
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Full Legal Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'full'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => true, 'step' => 1, 'order' => 3, 'width' => 'half'],
            ['id' => 'field_dob', 'type' => 'date', 'label' => 'Date of Birth', 'required' => true, 'step' => 1, 'order' => 4, 'width' => 'half'],
            ['id' => 'field_ssn_last4', 'type' => 'text', 'label' => 'Last 4 digits of SSN', 'required' => false, 'step' => 1, 'order' => 5, 'width' => 'half', 'placeholder' => 'XXXX'],
            ['id' => 'field_current_address', 'type' => 'textarea', 'label' => 'Current Address', 'required' => true, 'step' => 1, 'order' => 6, 'width' => 'full'],
            // Step 2: Employment Information
            ['id' => 'field_section_employment', 'type' => 'section', 'label' => 'Employment Information', 'step' => 2, 'order' => 7],
            ['id' => 'field_employer', 'type' => 'text', 'label' => 'Current Employer', 'required' => true, 'step' => 2, 'order' => 8, 'width' => 'full'],
            ['id' => 'field_job_title', 'type' => 'text', 'label' => 'Job Title', 'required' => true, 'step' => 2, 'order' => 9, 'width' => 'half'],
            ['id' => 'field_employment_length', 'type' => 'text', 'label' => 'Length of Employment', 'required' => true, 'step' => 2, 'order' => 10, 'width' => 'half', 'placeholder' => 'e.g., 2 years'],
            ['id' => 'field_monthly_income', 'type' => 'currency', 'label' => 'Monthly Gross Income', 'required' => true, 'step' => 2, 'order' => 11, 'width' => 'half'],
            ['id' => 'field_employer_phone', 'type' => 'phone', 'label' => 'Employer Phone', 'required' => false, 'step' => 2, 'order' => 12, 'width' => 'half'],
            // Step 3: Rental History
            ['id' => 'field_section_rental', 'type' => 'section', 'label' => 'Rental History', 'step' => 3, 'order' => 13],
            ['id' => 'field_prev_landlord', 'type' => 'text', 'label' => 'Previous Landlord Name', 'required' => true, 'step' => 3, 'order' => 14, 'width' => 'full'],
            ['id' => 'field_prev_landlord_phone', 'type' => 'phone', 'label' => 'Landlord Phone', 'required' => true, 'step' => 3, 'order' => 15, 'width' => 'half'],
            ['id' => 'field_prev_rent', 'type' => 'currency', 'label' => 'Monthly Rent Paid', 'required' => false, 'step' => 3, 'order' => 16, 'width' => 'half'],
            ['id' => 'field_reason_leaving', 'type' => 'textarea', 'label' => 'Reason for Leaving', 'required' => false, 'step' => 3, 'order' => 17, 'width' => 'full'],
            ['id' => 'field_evicted', 'type' => 'radio', 'label' => 'Have you ever been evicted?', 'required' => true, 'step' => 3, 'order' => 18, 'width' => 'full', 'options' => ['No', 'Yes']],
            // Step 4: Additional Info & References
            ['id' => 'field_section_refs', 'type' => 'section', 'label' => 'References & Additional Info', 'step' => 4, 'order' => 19],
            ['id' => 'field_reference1', 'type' => 'text', 'label' => 'Personal Reference Name', 'required' => true, 'step' => 4, 'order' => 20, 'width' => 'half'],
            ['id' => 'field_reference1_phone', 'type' => 'phone', 'label' => 'Reference Phone', 'required' => true, 'step' => 4, 'order' => 21, 'width' => 'half'],
            ['id' => 'field_pets', 'type' => 'radio', 'label' => 'Do you have pets?', 'required' => true, 'step' => 4, 'order' => 22, 'width' => 'full', 'options' => ['No', 'Yes']],
            ['id' => 'field_pet_details', 'type' => 'text', 'label' => 'If yes, please describe', 'required' => false, 'step' => 4, 'order' => 23, 'width' => 'full', 'conditional' => ['enabled' => true, 'action' => 'show', 'logic' => 'all', 'rules' => [['field_id' => 'field_pets', 'operator' => 'equals', 'value' => 'Yes']]]],
            ['id' => 'field_move_in_date', 'type' => 'date', 'label' => 'Desired Move-In Date', 'required' => true, 'step' => 4, 'order' => 24, 'width' => 'half'],
            ['id' => 'field_comments', 'type' => 'textarea', 'label' => 'Additional Comments', 'required' => false, 'step' => 4, 'order' => 25, 'width' => 'full'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Submit Application',
            'success_message' => 'Thank you for your application! We will review it and contact you within 2-3 business days.',
            'multistep_enabled' => true,
            'multistep_progress_type' => 'bar',
            'multistep_show_step_titles' => true,
            'steps' => [
                ['title' => 'Personal Info', 'description' => ''],
                ['title' => 'Employment', 'description' => ''],
                ['title' => 'Rental History', 'description' => ''],
                ['title' => 'References', 'description' => '']
            ]
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Template 4: Maintenance Request (No multi-step)
    $maintenance_request = [
        'template_name' => 'Maintenance Request',
        'template_slug' => 'maintenance-request',
        'category' => 'real-estate',
        'description' => 'Simple maintenance request form for tenants to report issues.',
        'fields' => json_encode(['fields' => [
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Your Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'half'],
            ['id' => 'field_unit', 'type' => 'text', 'label' => 'Unit/Property Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email', 'required' => true, 'step' => 1, 'order' => 3, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone', 'required' => true, 'step' => 1, 'order' => 4, 'width' => 'half'],
            ['id' => 'field_urgency', 'type' => 'dropdown', 'label' => 'Urgency Level', 'required' => true, 'step' => 1, 'order' => 5, 'width' => 'full', 'options' => ['Emergency (Safety Hazard)', 'Urgent (Within 24 hours)', 'Normal (Within a week)', 'Low Priority']],
            ['id' => 'field_category', 'type' => 'dropdown', 'label' => 'Issue Category', 'required' => true, 'step' => 1, 'order' => 6, 'width' => 'full', 'options' => ['Plumbing', 'Electrical', 'HVAC/Heating/Cooling', 'Appliance', 'Structural', 'Pest Control', 'Other']],
            ['id' => 'field_description', 'type' => 'textarea', 'label' => 'Describe the Issue', 'required' => true, 'step' => 1, 'order' => 7, 'width' => 'full', 'placeholder' => 'Please provide as much detail as possible about the maintenance issue.'],
            ['id' => 'field_permission', 'type' => 'radio', 'label' => 'Permission to enter if you are not home?', 'required' => true, 'step' => 1, 'order' => 8, 'width' => 'full', 'options' => ['Yes', 'No - Contact me first']],
            ['id' => 'field_availability', 'type' => 'text', 'label' => 'Best times for access', 'required' => false, 'step' => 1, 'order' => 9, 'width' => 'full', 'placeholder' => 'e.g., Weekdays after 5pm'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Submit Request',
            'success_message' => 'Your maintenance request has been submitted. We will contact you to schedule service.',
            'multistep_enabled' => false
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Template 5: Investor Inquiry (3 steps)
    $investor_inquiry = [
        'template_name' => 'Investor Inquiry',
        'template_slug' => 'investor-inquiry',
        'category' => 'real-estate',
        'description' => 'Capture investment property inquiries with budget and strategy details.',
        'fields' => json_encode(['fields' => [
            // Step 1: Contact Info
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Full Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'full'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => true, 'step' => 1, 'order' => 3, 'width' => 'half'],
            ['id' => 'field_company', 'type' => 'text', 'label' => 'Company/Entity Name (if applicable)', 'required' => false, 'step' => 1, 'order' => 4, 'width' => 'full'],
            // Step 2: Investment Goals
            ['id' => 'field_section_goals', 'type' => 'section', 'label' => 'Investment Goals', 'step' => 2, 'order' => 5],
            ['id' => 'field_strategy', 'type' => 'checkbox', 'label' => 'Investment Strategy', 'required' => true, 'step' => 2, 'order' => 6, 'width' => 'full', 'options' => ['Buy & Hold (Rental)', 'Fix & Flip', 'BRRRR', 'Wholesale', 'Commercial', 'Land Development']],
            ['id' => 'field_property_types', 'type' => 'checkbox', 'label' => 'Property Types of Interest', 'required' => true, 'step' => 2, 'order' => 7, 'width' => 'full', 'options' => ['Single Family', 'Multi-Family (2-4 units)', 'Apartment Complex', 'Commercial', 'Mixed Use', 'Land']],
            ['id' => 'field_locations', 'type' => 'textarea', 'label' => 'Target Markets/Locations', 'required' => false, 'step' => 2, 'order' => 8, 'width' => 'full', 'placeholder' => 'Which cities, neighborhoods, or regions are you interested in?'],
            // Step 3: Financial Details
            ['id' => 'field_section_financial', 'type' => 'section', 'label' => 'Financial Details', 'step' => 3, 'order' => 9],
            ['id' => 'field_budget', 'type' => 'dropdown', 'label' => 'Investment Budget (per property)', 'required' => true, 'step' => 3, 'order' => 10, 'width' => 'full', 'options' => ['Under $100,000', '$100,000 - $250,000', '$250,000 - $500,000', '$500,000 - $1,000,000', 'Over $1,000,000']],
            ['id' => 'field_funding', 'type' => 'dropdown', 'label' => 'Funding Source', 'required' => true, 'step' => 3, 'order' => 11, 'width' => 'full', 'options' => ['Cash', 'Conventional Loan', 'Hard Money', 'Private Lender', 'HELOC/Line of Credit', 'Partner/Syndication']],
            ['id' => 'field_experience', 'type' => 'dropdown', 'label' => 'Investment Experience', 'required' => true, 'step' => 3, 'order' => 12, 'width' => 'full', 'options' => ['First-time investor', '1-5 properties', '6-20 properties', '20+ properties']],
            ['id' => 'field_timeline', 'type' => 'dropdown', 'label' => 'Ready to purchase within', 'required' => true, 'step' => 3, 'order' => 13, 'width' => 'full', 'options' => ['Immediately', '1-3 months', '3-6 months', '6+ months']],
            ['id' => 'field_comments', 'type' => 'textarea', 'label' => 'Additional Details', 'required' => false, 'step' => 3, 'order' => 14, 'width' => 'full', 'placeholder' => 'Tell us more about your investment goals or specific requirements.'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Submit Inquiry',
            'success_message' => 'Thank you for your investment inquiry! Our team will review your goals and reach out with matching opportunities.',
            'multistep_enabled' => true,
            'multistep_progress_type' => 'steps',
            'multistep_show_step_titles' => true,
            'steps' => [
                ['title' => 'Contact', 'description' => ''],
                ['title' => 'Goals', 'description' => ''],
                ['title' => 'Financial', 'description' => '']
            ]
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Template 6: General Contact (No multi-step)
    $general_contact = [
        'template_name' => 'General Contact',
        'template_slug' => 'general-contact',
        'category' => 'general',
        'description' => 'Simple contact form with name, email, phone, and message.',
        'fields' => json_encode(['fields' => [
            ['id' => 'field_name', 'type' => 'text', 'label' => 'Your Name', 'required' => true, 'step' => 1, 'order' => 1, 'width' => 'full'],
            ['id' => 'field_email', 'type' => 'email', 'label' => 'Email Address', 'required' => true, 'step' => 1, 'order' => 2, 'width' => 'half'],
            ['id' => 'field_phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => false, 'step' => 1, 'order' => 3, 'width' => 'half'],
            ['id' => 'field_subject', 'type' => 'text', 'label' => 'Subject', 'required' => false, 'step' => 1, 'order' => 4, 'width' => 'full'],
            ['id' => 'field_message', 'type' => 'textarea', 'label' => 'Message', 'required' => true, 'step' => 1, 'order' => 5, 'width' => 'full', 'placeholder' => 'How can we help you?'],
        ]]),
        'settings' => json_encode([
            'submit_button_text' => 'Send Message',
            'success_message' => 'Thank you for contacting us! We will get back to you soon.',
            'multistep_enabled' => false
        ]),
        'is_system' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now
    ];

    // Insert all templates
    $templates = [
        $buyer_intake,
        $seller_intake,
        $tenant_application,
        $maintenance_request,
        $investor_inquiry,
        $general_contact
    ];

    foreach ($templates as $template) {
        $wpdb->insert($templates_table, $template);
        if ($wpdb->last_error) {
            error_log("MLD Update 6.24.0: Failed to insert template {$template['template_slug']}: " . $wpdb->last_error);
        }
    }

    error_log("MLD Update 6.24.0: Inserted " . count($templates) . " default templates");
}
