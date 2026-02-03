<?php
/**
 * Contact Form Templates Manager
 *
 * Manages pre-built form templates for quick form creation.
 *
 * @package MLS_Listings_Display
 * @since 6.24.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Templates
 *
 * Provides methods for managing form templates.
 */
class MLD_Contact_Form_Templates {

    /**
     * Singleton instance
     *
     * @var MLD_Contact_Form_Templates|null
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
     * @return MLD_Contact_Form_Templates
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
        $this->table_name = $wpdb->prefix . 'mld_form_templates';
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        $instance = self::get_instance();

        // AJAX handlers
        add_action('wp_ajax_mld_get_templates', [$instance, 'ajax_get_templates']);
        add_action('wp_ajax_mld_apply_template', [$instance, 'ajax_apply_template']);
        add_action('wp_ajax_mld_get_template_preview', [$instance, 'ajax_get_template_preview']);
    }

    /**
     * Get all active templates
     *
     * @param array $args Query arguments
     * @return array Templates
     */
    public function get_templates(array $args = []) {
        global $wpdb;

        $defaults = [
            'category' => '',
            'is_system' => null,
            'orderby' => 'template_name',
            'order' => 'ASC',
            'limit' => 0,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        $where_clauses = ['is_active = 1'];
        $where_values = [];

        // Category filter
        if (!empty($args['category'])) {
            $where_clauses[] = 'category = %s';
            $where_values[] = $args['category'];
        }

        // System templates filter
        if ($args['is_system'] !== null) {
            $where_clauses[] = 'is_system = %d';
            $where_values[] = $args['is_system'] ? 1 : 0;
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Sanitize orderby
        $allowed_orderby = ['id', 'template_name', 'category', 'usage_count', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'template_name';
        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';

        // Build LIMIT clause
        $limit_sql = '';
        if ($args['limit'] > 0) {
            $limit_sql = $wpdb->prepare('LIMIT %d OFFSET %d', absint($args['limit']), absint($args['offset']));
        }

        $sql = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} {$limit_sql}";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        $results = $wpdb->get_results($sql);

        // Decode JSON fields
        foreach ($results as &$template) {
            $template = $this->decode_template_json($template);
        }

        return $results;
    }

    /**
     * Get a single template by ID
     *
     * @param int $template_id Template ID
     * @return object|null Template or null
     */
    public function get_template(int $template_id) {
        global $wpdb;

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $template_id
        ));

        if ($template) {
            $template = $this->decode_template_json($template);
        }

        return $template;
    }

    /**
     * Get a template by slug
     *
     * @param string $slug Template slug
     * @return object|null Template or null
     */
    public function get_template_by_slug(string $slug) {
        global $wpdb;

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE template_slug = %s",
            $slug
        ));

        if ($template) {
            $template = $this->decode_template_json($template);
        }

        return $template;
    }

    /**
     * Get system templates (built-in templates)
     *
     * @return array Templates
     */
    public function get_system_templates() {
        return $this->get_templates(['is_system' => true]);
    }

    /**
     * Get user templates (custom templates)
     *
     * @return array Templates
     */
    public function get_user_templates() {
        return $this->get_templates(['is_system' => false]);
    }

    /**
     * Get templates grouped by category
     *
     * @return array Templates grouped by category
     */
    public function get_templates_by_category() {
        $templates = $this->get_templates();
        $grouped = [];

        foreach ($templates as $template) {
            $category = $template->category ?: 'general';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $template;
        }

        return $grouped;
    }

    /**
     * Get available categories
     *
     * @return array Categories with labels
     */
    public function get_categories() {
        return [
            'real-estate' => __('Real Estate', 'mls-listings-display'),
            'general' => __('General', 'mls-listings-display'),
            'contact' => __('Contact', 'mls-listings-display'),
            'application' => __('Application', 'mls-listings-display'),
            'feedback' => __('Feedback', 'mls-listings-display'),
        ];
    }

    /**
     * Apply a template to create a new form
     *
     * @param int    $template_id Template ID
     * @param string $form_name   Optional custom form name
     * @return int|WP_Error New form ID on success, WP_Error on failure
     */
    public function apply_template(int $template_id, string $form_name = '') {
        $template = $this->get_template($template_id);

        if (!$template) {
            return new WP_Error('template_not_found', __('Template not found.', 'mls-listings-display'));
        }

        // Prepare form data from template
        $form_data = [
            'form_name' => !empty($form_name) ? $form_name : $template->template_name,
            'description' => $template->description,
            'fields' => $template->fields,
            'settings' => $template->settings,
            'status' => 'active',
        ];

        // Generate new field IDs to avoid conflicts
        if (isset($form_data['fields']['fields']) && is_array($form_data['fields']['fields'])) {
            foreach ($form_data['fields']['fields'] as &$field) {
                $old_id = $field['id'];
                $new_id = 'field_' . wp_generate_password(8, false);
                $field['id'] = $new_id;

                // Update any conditional logic references
                if (isset($field['conditional']['rules']) && is_array($field['conditional']['rules'])) {
                    // Keep track of ID mapping for later update
                    $id_map[$old_id] = $new_id;
                }
            }
            unset($field);

            // Update conditional logic field references
            if (!empty($id_map)) {
                foreach ($form_data['fields']['fields'] as &$field) {
                    if (isset($field['conditional']['rules']) && is_array($field['conditional']['rules'])) {
                        foreach ($field['conditional']['rules'] as &$rule) {
                            if (isset($rule['field_id']) && isset($id_map[$rule['field_id']])) {
                                $rule['field_id'] = $id_map[$rule['field_id']];
                            }
                        }
                        unset($rule);
                    }
                }
                unset($field);
            }
        }

        // Create the form
        $manager = MLD_Contact_Form_Manager::get_instance();
        $form_id = $manager->create_form($form_data);

        if ($form_id === false) {
            return new WP_Error('form_creation_failed', __('Failed to create form from template.', 'mls-listings-display'));
        }

        // Increment template usage count
        $this->increment_usage_count($template_id);

        return $form_id;
    }

    /**
     * Increment template usage count
     *
     * @param int $template_id Template ID
     * @return bool
     */
    public function increment_usage_count(int $template_id) {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} SET usage_count = usage_count + 1 WHERE id = %d",
            $template_id
        )) !== false;
    }

    /**
     * Save a form as a template
     *
     * @param int    $form_id       Form ID to save as template
     * @param string $template_name Template name
     * @param string $category      Category
     * @param string $description   Description
     * @return int|WP_Error Template ID on success, WP_Error on failure
     */
    public function save_form_as_template(int $form_id, string $template_name, string $category = 'general', string $description = '') {
        global $wpdb;

        $manager = MLD_Contact_Form_Manager::get_instance();
        $form = $manager->get_form($form_id);

        if (!$form) {
            return new WP_Error('form_not_found', __('Form not found.', 'mls-listings-display'));
        }

        // Generate unique slug
        $base_slug = sanitize_title($template_name);
        $slug = $base_slug;
        $counter = 1;

        while ($this->get_template_by_slug($slug)) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        $result = $wpdb->insert(
            $this->table_name,
            [
                'template_name' => sanitize_text_field($template_name),
                'template_slug' => $slug,
                'category' => sanitize_text_field($category),
                'description' => sanitize_textarea_field($description),
                'preview_image' => null,
                'fields' => wp_json_encode($form->fields),
                'settings' => wp_json_encode($form->settings),
                'is_system' => 0,
                'is_active' => 1,
                'usage_count' => 0,
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
        );

        if ($result === false) {
            return new WP_Error('template_creation_failed', __('Failed to create template.', 'mls-listings-display'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Delete a template
     *
     * @param int $template_id Template ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete_template(int $template_id) {
        global $wpdb;

        $template = $this->get_template($template_id);

        if (!$template) {
            return new WP_Error('template_not_found', __('Template not found.', 'mls-listings-display'));
        }

        // Prevent deletion of system templates
        if ($template->is_system) {
            return new WP_Error('cannot_delete_system', __('System templates cannot be deleted.', 'mls-listings-display'));
        }

        $result = $wpdb->delete($this->table_name, ['id' => $template_id], ['%d']);

        return $result !== false;
    }

    /**
     * AJAX: Get templates for the library modal
     */
    public function ajax_get_templates() {
        check_ajax_referer('mld_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mls-listings-display')]);
        }

        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';

        $args = [];
        if (!empty($category)) {
            $args['category'] = $category;
        }

        $templates = $this->get_templates($args);
        $categories = $this->get_categories();

        // Format templates for frontend
        $formatted = [];
        foreach ($templates as $template) {
            $field_count = 0;
            if (isset($template->fields['fields']) && is_array($template->fields['fields'])) {
                $field_count = count($template->fields['fields']);
            }

            $is_multistep = !empty($template->settings['multistep_enabled']);
            $step_count = 1;
            if ($is_multistep && isset($template->settings['steps'])) {
                $step_count = count($template->settings['steps']);
            }

            $formatted[] = [
                'id' => $template->id,
                'name' => $template->template_name,
                'slug' => $template->template_slug,
                'category' => $template->category,
                'category_label' => $categories[$template->category] ?? ucfirst($template->category),
                'description' => $template->description,
                'is_system' => (bool) $template->is_system,
                'usage_count' => $template->usage_count,
                'field_count' => $field_count,
                'is_multistep' => $is_multistep,
                'step_count' => $step_count,
            ];
        }

        wp_send_json_success([
            'templates' => $formatted,
            'categories' => $categories,
        ]);
    }

    /**
     * AJAX: Apply a template to create a new form
     */
    public function ajax_apply_template() {
        check_ajax_referer('mld_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mls-listings-display')]);
        }

        $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
        $form_name = isset($_POST['form_name']) ? sanitize_text_field($_POST['form_name']) : '';

        if (!$template_id) {
            wp_send_json_error(['message' => __('Invalid template.', 'mls-listings-display')]);
        }

        $result = $this->apply_template($template_id, $form_name);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'form_id' => $result,
            'message' => __('Form created from template successfully!', 'mls-listings-display'),
            'redirect_url' => admin_url('admin.php?page=mld-contact-form-edit&form_id=' . $result),
        ]);
    }

    /**
     * AJAX: Get template preview (fields and settings)
     */
    public function ajax_get_template_preview() {
        check_ajax_referer('mld_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'mls-listings-display')]);
        }

        $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;

        if (!$template_id) {
            wp_send_json_error(['message' => __('Invalid template.', 'mls-listings-display')]);
        }

        $template = $this->get_template($template_id);

        if (!$template) {
            wp_send_json_error(['message' => __('Template not found.', 'mls-listings-display')]);
        }

        // Get field type labels
        $manager = MLD_Contact_Form_Manager::get_instance();
        $field_types = $manager->get_field_types();

        // Format fields for preview
        $fields = [];
        if (isset($template->fields['fields']) && is_array($template->fields['fields'])) {
            foreach ($template->fields['fields'] as $field) {
                $type_info = $field_types[$field['type']] ?? ['label' => ucfirst($field['type']), 'icon' => 'dashicons-admin-generic'];
                $fields[] = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'type_label' => $type_info['label'],
                    'type_icon' => $type_info['icon'],
                    'required' => !empty($field['required']),
                    'step' => $field['step'] ?? 1,
                ];
            }
        }

        wp_send_json_success([
            'template' => [
                'id' => $template->id,
                'name' => $template->template_name,
                'description' => $template->description,
                'category' => $template->category,
                'is_multistep' => !empty($template->settings['multistep_enabled']),
                'steps' => $template->settings['steps'] ?? [],
            ],
            'fields' => $fields,
        ]);
    }

    /**
     * Decode JSON fields in a template object
     *
     * @param object $template Template object
     * @return object Template with decoded JSON
     */
    private function decode_template_json($template) {
        if (isset($template->fields) && is_string($template->fields)) {
            $template->fields = json_decode($template->fields, true);
        }
        if (isset($template->settings) && is_string($template->settings)) {
            $template->settings = json_decode($template->settings, true);
        }
        return $template;
    }
}
