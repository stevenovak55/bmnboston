<?php
/**
 * Contact Form Manager
 *
 * Handles CRUD operations for custom contact forms.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Manager
 *
 * Provides methods for creating, reading, updating, and deleting contact forms.
 */
class MLD_Contact_Form_Manager {

    /**
     * Singleton instance
     *
     * @var MLD_Contact_Form_Manager|null
     */
    private static $instance = null;

    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Get singleton instance
     *
     * @return MLD_Contact_Form_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mld_contact_forms';
    }

    /**
     * Create a new contact form
     *
     * @param array $data Form data including name, fields, settings, etc.
     * @return int|false Form ID on success, false on failure
     */
    public function create_form(array $data) {
        global $wpdb;

        $defaults = [
            'form_name' => 'New Contact Form',
            'form_slug' => '',
            'description' => '',
            'fields' => wp_json_encode(['fields' => []]),
            'settings' => wp_json_encode([
                'submit_button_text' => 'Send Message',
                'success_message' => 'Thank you for your message! We will get back to you soon.',
                'redirect_url' => '',
                'honeypot_enabled' => true,
                'form_layout' => 'vertical',
                // Multi-step settings (v6.23.0)
                'multistep_enabled' => false,
                'multistep_progress_type' => 'steps', // 'steps' or 'bar'
                'multistep_show_step_titles' => true,
                'multistep_prev_button_text' => 'Previous',
                'multistep_next_button_text' => 'Next',
                'steps' => [
                    ['title' => 'Step 1', 'description' => '']
                ]
            ]),
            'notification_settings' => wp_json_encode([
                'admin_email_enabled' => true,
                'admin_email_subject' => 'New Contact Form Submission - {form_name}',
                'additional_recipients' => '',
                'user_confirmation_enabled' => true,
                'user_confirmation_subject' => 'Thank you for contacting us!',
                'user_confirmation_message' => "Hi {field_first_name},\n\nThank you for reaching out. We have received your message and will get back to you soon.\n\nBest regards,\n{site_name}"
            ]),
            'status' => 'active',
            'submission_count' => 0,
            'created_by' => get_current_user_id()
        ];

        $data = wp_parse_args($data, $defaults);

        // Generate slug if not provided
        if (empty($data['form_slug'])) {
            $data['form_slug'] = $this->generate_unique_slug($data['form_name']);
        }

        // Ensure fields and settings are JSON strings
        if (is_array($data['fields'])) {
            $data['fields'] = wp_json_encode($data['fields']);
        }
        if (is_array($data['settings'])) {
            $data['settings'] = wp_json_encode($data['settings']);
        }
        if (is_array($data['notification_settings'])) {
            $data['notification_settings'] = wp_json_encode($data['notification_settings']);
        }

        $result = $wpdb->insert(
            $this->table_name,
            [
                'form_name' => sanitize_text_field($data['form_name']),
                'form_slug' => sanitize_title($data['form_slug']),
                'description' => sanitize_textarea_field($data['description']),
                'fields' => $data['fields'],
                'settings' => $data['settings'],
                'notification_settings' => $data['notification_settings'],
                'status' => in_array($data['status'], ['active', 'draft', 'archived']) ? $data['status'] : 'active',
                'submission_count' => absint($data['submission_count']),
                'created_by' => absint($data['created_by']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MLD Contact Forms] Failed to create form: ' . $wpdb->last_error);
            }
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get a single form by ID
     *
     * @param int $form_id Form ID
     * @return object|null Form object or null if not found
     */
    public function get_form($form_id) {
        global $wpdb;

        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            absint($form_id)
        ));

        if ($form) {
            $form = $this->decode_form_json($form);
        }

        return $form;
    }

    /**
     * Get a form by slug
     *
     * @param string $slug Form slug
     * @return object|null Form object or null if not found
     */
    public function get_form_by_slug($slug) {
        global $wpdb;

        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE form_slug = %s",
            sanitize_title($slug)
        ));

        if ($form) {
            $form = $this->decode_form_json($form);
        }

        return $form;
    }

    /**
     * Update an existing form
     *
     * @param int   $form_id Form ID
     * @param array $data    Data to update
     * @return bool True on success, false on failure
     */
    public function update_form($form_id, array $data) {
        global $wpdb;

        $update_data = [];
        $format = [];

        // Build update data array
        if (isset($data['form_name'])) {
            $update_data['form_name'] = sanitize_text_field($data['form_name']);
            $format[] = '%s';
        }

        if (isset($data['form_slug'])) {
            $update_data['form_slug'] = sanitize_title($data['form_slug']);
            $format[] = '%s';
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $format[] = '%s';
        }

        if (isset($data['fields'])) {
            $update_data['fields'] = is_array($data['fields']) ? wp_json_encode($data['fields']) : $data['fields'];
            $format[] = '%s';
        }

        if (isset($data['settings'])) {
            $update_data['settings'] = is_array($data['settings']) ? wp_json_encode($data['settings']) : $data['settings'];
            $format[] = '%s';
        }

        if (isset($data['notification_settings'])) {
            $update_data['notification_settings'] = is_array($data['notification_settings']) ? wp_json_encode($data['notification_settings']) : $data['notification_settings'];
            $format[] = '%s';
        }

        if (isset($data['status']) && in_array($data['status'], ['active', 'draft', 'archived'])) {
            $update_data['status'] = $data['status'];
            $format[] = '%s';
        }

        // Always update the updated_at timestamp
        $update_data['updated_at'] = current_time('mysql');
        $format[] = '%s';

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            ['id' => absint($form_id)],
            $format,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a form
     *
     * @param int $form_id Form ID
     * @return bool True on success, false on failure
     */
    public function delete_form($form_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            ['id' => absint($form_id)],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Duplicate a form
     *
     * @param int $form_id Source form ID
     * @return int|false New form ID on success, false on failure
     */
    public function duplicate_form($form_id) {
        $form = $this->get_form($form_id);

        if (!$form) {
            return false;
        }

        $new_data = [
            'form_name' => $form->form_name . ' (Copy)',
            'form_slug' => '', // Will be auto-generated
            'description' => $form->description,
            'fields' => $form->fields,
            'settings' => $form->settings,
            'notification_settings' => $form->notification_settings,
            'status' => 'draft',
            'submission_count' => 0
        ];

        return $this->create_form($new_data);
    }

    /**
     * Get multiple forms with optional filtering
     *
     * @param array $args Query arguments
     * @return array Array of form objects
     */
    public function get_forms(array $args = []) {
        global $wpdb;

        $defaults = [
            'status' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 0,
            'offset' => 0,
            'search' => ''
        ];

        $args = wp_parse_args($args, $defaults);

        $where_clauses = [];
        $where_values = [];

        // Status filter
        if (!empty($args['status']) && in_array($args['status'], ['active', 'draft', 'archived'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        // Search filter
        if (!empty($args['search'])) {
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_clauses[] = '(form_name LIKE %s OR description LIKE %s)';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Build WHERE clause
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Sanitize orderby
        $allowed_orderby = ['id', 'form_name', 'status', 'submission_count', 'created_at', 'updated_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build LIMIT clause
        $limit_sql = '';
        if ($args['limit'] > 0) {
            $limit_sql = $wpdb->prepare('LIMIT %d OFFSET %d', absint($args['limit']), absint($args['offset']));
        }

        // Build and execute query
        $sql = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY {$orderby} {$order} {$limit_sql}";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        $results = $wpdb->get_results($sql);

        // Decode JSON fields
        foreach ($results as &$form) {
            $form = $this->decode_form_json($form);
        }

        return $results;
    }

    /**
     * Get total count of forms
     *
     * @param string $status Optional status filter
     * @return int Count
     */
    public function get_form_count($status = '') {
        global $wpdb;

        if (!empty($status) && in_array($status, ['active', 'draft', 'archived'])) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }

    /**
     * Reorder fields within a form
     *
     * @param int   $form_id    Form ID
     * @param array $field_order Array of field IDs in new order
     * @return bool True on success, false on failure
     */
    public function reorder_fields($form_id, array $field_order) {
        $form = $this->get_form($form_id);

        if (!$form || !isset($form->fields['fields'])) {
            return false;
        }

        $fields = $form->fields['fields'];
        $reordered = [];

        // Create field lookup by ID
        $field_lookup = [];
        foreach ($fields as $field) {
            $field_lookup[$field['id']] = $field;
        }

        // Build reordered array
        $order = 1;
        foreach ($field_order as $field_id) {
            if (isset($field_lookup[$field_id])) {
                $field = $field_lookup[$field_id];
                $field['order'] = $order++;
                $reordered[] = $field;
                unset($field_lookup[$field_id]);
            }
        }

        // Append any remaining fields not in the order array
        foreach ($field_lookup as $field) {
            $field['order'] = $order++;
            $reordered[] = $field;
        }

        return $this->update_form($form_id, [
            'fields' => ['fields' => $reordered]
        ]);
    }

    /**
     * Generate shortcode for a form
     *
     * @param int $form_id Form ID
     * @return string Shortcode
     */
    public function generate_shortcode($form_id) {
        return '[mld_contact_form id="' . absint($form_id) . '"]';
    }

    /**
     * Increment submission count for a form
     *
     * @param int $form_id Form ID
     * @return void
     */
    public function increment_submission_count($form_id) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} SET submission_count = submission_count + 1, updated_at = %s WHERE id = %d",
            current_time('mysql'),
            absint($form_id)
        ));
    }

    /**
     * Generate a unique slug from form name
     *
     * @param string $name Form name
     * @return string Unique slug
     */
    private function generate_unique_slug($name) {
        global $wpdb;

        $base_slug = sanitize_title($name);
        $slug = $base_slug;
        $counter = 1;

        while ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE form_slug = %s",
            $slug
        )) > 0) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Decode JSON fields in a form object
     *
     * @param object $form Form object
     * @return object Form object with decoded JSON
     */
    private function decode_form_json($form) {
        if (isset($form->fields) && is_string($form->fields)) {
            $form->fields = json_decode($form->fields, true);
        }
        if (isset($form->settings) && is_string($form->settings)) {
            $form->settings = json_decode($form->settings, true);
        }
        if (isset($form->notification_settings) && is_string($form->notification_settings)) {
            $form->notification_settings = json_decode($form->notification_settings, true);
        }
        return $form;
    }

    /**
     * Get default field structure
     *
     * @param string $type Field type
     * @return array Field structure
     */
    public function get_default_field($type) {
        $defaults = [
            'id' => 'field_' . wp_generate_password(8, false),
            'type' => $type,
            'label' => ucfirst($type),
            'placeholder' => '',
            'required' => false,
            'validation' => [],
            'options' => [],
            'order' => 999,
            'width' => 'full',
            'step' => 1 // Multi-step support (v6.23.0)
        ];

        // Type-specific defaults
        switch ($type) {
            case 'text':
                $defaults['label'] = 'Text Field';
                $defaults['placeholder'] = 'Enter text';
                break;

            case 'email':
                $defaults['label'] = 'Email Address';
                $defaults['placeholder'] = 'Enter your email';
                $defaults['required'] = true;
                break;

            case 'phone':
                $defaults['label'] = 'Phone Number';
                $defaults['placeholder'] = '(555) 123-4567';
                break;

            case 'textarea':
                $defaults['label'] = 'Message';
                $defaults['placeholder'] = 'Enter your message';
                $defaults['validation'] = ['min_length' => 0, 'max_length' => 5000];
                break;

            case 'dropdown':
                $defaults['label'] = 'Select Option';
                $defaults['options'] = ['Option 1', 'Option 2', 'Option 3'];
                break;

            case 'checkbox':
                $defaults['label'] = 'Checkbox';
                $defaults['options'] = ['Option 1', 'Option 2'];
                break;

            case 'radio':
                $defaults['label'] = 'Choose One';
                $defaults['options'] = ['Option 1', 'Option 2', 'Option 3'];
                break;

            case 'date':
                $defaults['label'] = 'Date';
                $defaults['placeholder'] = 'Select a date';
                break;

            // New field types added in v6.22.0
            case 'number':
                $defaults['label'] = 'Number';
                $defaults['placeholder'] = 'Enter a number';
                $defaults['validation'] = [
                    'min' => '',
                    'max' => '',
                    'step' => 1
                ];
                break;

            case 'currency':
                $defaults['label'] = 'Amount';
                $defaults['placeholder'] = '0.00';
                $defaults['validation'] = [
                    'min' => 0,
                    'max' => '',
                    'currency_symbol' => '$',
                    'decimal_places' => 2
                ];
                break;

            case 'url':
                $defaults['label'] = 'Website';
                $defaults['placeholder'] = 'https://example.com';
                break;

            case 'hidden':
                $defaults['label'] = 'Hidden Field';
                $defaults['placeholder'] = '';
                $defaults['default_value'] = '';
                $defaults['width'] = 'full';
                break;

            case 'section':
                $defaults['label'] = 'Section Title';
                $defaults['description'] = '';
                $defaults['width'] = 'full';
                break;

            case 'paragraph':
                $defaults['label'] = 'Information';
                $defaults['content'] = 'Enter your text content here.';
                $defaults['width'] = 'full';
                break;

            // New field type added in v6.24.0
            case 'file':
                $defaults['label'] = 'Upload Files';
                $defaults['placeholder'] = '';
                $defaults['width'] = 'full';
                $defaults['file_config'] = [
                    'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'],
                    'max_size_mb' => 5,
                    'max_files' => 3
                ];
                break;
        }

        return $defaults;
    }

    /**
     * Get all available field types
     *
     * @return array Field types with labels and icons
     */
    public function get_field_types() {
        return [
            'text' => [
                'label' => 'Text',
                'icon' => 'dashicons-editor-textcolor',
                'description' => 'Single line text input'
            ],
            'email' => [
                'label' => 'Email',
                'icon' => 'dashicons-email',
                'description' => 'Email address with validation'
            ],
            'phone' => [
                'label' => 'Phone',
                'icon' => 'dashicons-phone',
                'description' => 'Phone number input'
            ],
            'textarea' => [
                'label' => 'Textarea',
                'icon' => 'dashicons-editor-paragraph',
                'description' => 'Multi-line text input'
            ],
            'dropdown' => [
                'label' => 'Dropdown',
                'icon' => 'dashicons-arrow-down-alt2',
                'description' => 'Select from options'
            ],
            'checkbox' => [
                'label' => 'Checkbox',
                'icon' => 'dashicons-yes-alt',
                'description' => 'Multiple choice checkboxes'
            ],
            'radio' => [
                'label' => 'Radio',
                'icon' => 'dashicons-marker',
                'description' => 'Single choice radio buttons'
            ],
            'date' => [
                'label' => 'Date',
                'icon' => 'dashicons-calendar-alt',
                'description' => 'Date picker input'
            ],
            // New field types added in v6.22.0
            'number' => [
                'label' => 'Number',
                'icon' => 'dashicons-calculator',
                'description' => 'Numeric input with min/max'
            ],
            'currency' => [
                'label' => 'Currency',
                'icon' => 'dashicons-money-alt',
                'description' => 'Money amount with formatting'
            ],
            'url' => [
                'label' => 'URL',
                'icon' => 'dashicons-admin-links',
                'description' => 'Website link input'
            ],
            'hidden' => [
                'label' => 'Hidden',
                'icon' => 'dashicons-hidden',
                'description' => 'Hidden field for tracking'
            ],
            'section' => [
                'label' => 'Section',
                'icon' => 'dashicons-minus',
                'description' => 'Section divider/heading'
            ],
            'paragraph' => [
                'label' => 'Paragraph',
                'icon' => 'dashicons-text',
                'description' => 'Static text or instructions'
            ],
            // New field type added in v6.24.0
            'file' => [
                'label' => 'File Upload',
                'icon' => 'dashicons-upload',
                'description' => 'File upload with drag-drop'
            ]
        ];
    }
}
