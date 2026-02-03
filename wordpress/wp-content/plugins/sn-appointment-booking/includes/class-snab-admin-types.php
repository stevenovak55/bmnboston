<?php
/**
 * Admin Types Class
 *
 * Handles appointment types CRUD operations in admin.
 *
 * @package SN_Appointment_Booking
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Types class.
 *
 * @since 1.0.0
 */
class SNAB_Admin_Types {

    /**
     * Table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'snab_appointment_types';

        // Register AJAX handlers
        add_action('wp_ajax_snab_save_type', array($this, 'ajax_save_type'));
        add_action('wp_ajax_snab_delete_type', array($this, 'ajax_delete_type'));
        add_action('wp_ajax_snab_toggle_type_status', array($this, 'ajax_toggle_status'));
        add_action('wp_ajax_snab_update_type_order', array($this, 'ajax_update_order'));
        add_action('wp_ajax_snab_get_type', array($this, 'ajax_get_type'));
    }

    /**
     * Render the types management page.
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'sn-appointment-booking'));
        }

        $types = $this->get_all_types();
        ?>
        <div class="wrap snab-admin-wrap">
            <h1>
                <?php esc_html_e('Appointment Types', 'sn-appointment-booking'); ?>
                <button type="button" class="page-title-action snab-add-type-btn">
                    <?php esc_html_e('Add New', 'sn-appointment-booking'); ?>
                </button>
            </h1>

            <div id="snab-types-notice" class="notice" style="display: none;"></div>

            <!-- Types List -->
            <div class="snab-section">
                <table class="wp-list-table widefat fixed striped snab-types-table" id="snab-types-table">
                    <thead>
                        <tr>
                            <th class="column-order" style="width: 40px;"><?php esc_html_e('Order', 'sn-appointment-booking'); ?></th>
                            <th class="column-name"><?php esc_html_e('Name', 'sn-appointment-booking'); ?></th>
                            <th class="column-duration" style="width: 100px;"><?php esc_html_e('Duration', 'sn-appointment-booking'); ?></th>
                            <th class="column-buffer" style="width: 140px;"><?php esc_html_e('Buffer Time', 'sn-appointment-booking'); ?></th>
                            <th class="column-color" style="width: 80px;"><?php esc_html_e('Color', 'sn-appointment-booking'); ?></th>
                            <th class="column-status" style="width: 80px;"><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                            <th class="column-actions" style="width: 150px;"><?php esc_html_e('Actions', 'sn-appointment-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="snab-types-list">
                        <?php if (empty($types)): ?>
                            <tr class="snab-no-types">
                                <td colspan="7"><?php esc_html_e('No appointment types found. Click "Add New" to create one.', 'sn-appointment-booking'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($types as $type): ?>
                                <?php $this->render_type_row($type); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Add/Edit Modal -->
            <div id="snab-type-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content">
                    <div class="snab-modal-header">
                        <h2 id="snab-modal-title"><?php esc_html_e('Add Appointment Type', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <form id="snab-type-form">
                        <input type="hidden" id="snab-type-id" name="type_id" value="">
                        <?php wp_nonce_field('snab_admin_nonce', 'snab_nonce'); ?>

                        <div class="snab-modal-body">
                            <div class="snab-form-row">
                                <label for="snab-type-name"><?php esc_html_e('Name', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                <input type="text" id="snab-type-name" name="name" required>
                            </div>

                            <div class="snab-form-row">
                                <label for="snab-type-slug"><?php esc_html_e('Slug', 'sn-appointment-booking'); ?></label>
                                <input type="text" id="snab-type-slug" name="slug" placeholder="<?php esc_attr_e('Auto-generated from name', 'sn-appointment-booking'); ?>">
                                <p class="description"><?php esc_html_e('URL-friendly identifier. Leave blank to auto-generate.', 'sn-appointment-booking'); ?></p>
                            </div>

                            <div class="snab-form-row">
                                <label for="snab-type-description"><?php esc_html_e('Description', 'sn-appointment-booking'); ?></label>
                                <textarea id="snab-type-description" name="description" rows="3"></textarea>
                            </div>

                            <div class="snab-form-row snab-form-row-inline">
                                <div>
                                    <label for="snab-type-duration"><?php esc_html_e('Duration (minutes)', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="number" id="snab-type-duration" name="duration_minutes" min="5" max="480" step="5" value="60" required>
                                </div>
                                <div>
                                    <label for="snab-type-buffer-before"><?php esc_html_e('Buffer Before (min)', 'sn-appointment-booking'); ?></label>
                                    <input type="number" id="snab-type-buffer-before" name="buffer_before_minutes" min="0" max="120" step="5" value="0">
                                </div>
                                <div>
                                    <label for="snab-type-buffer-after"><?php esc_html_e('Buffer After (min)', 'sn-appointment-booking'); ?></label>
                                    <input type="number" id="snab-type-buffer-after" name="buffer_after_minutes" min="0" max="120" step="5" value="15">
                                </div>
                            </div>

                            <div class="snab-form-row">
                                <label for="snab-type-color"><?php esc_html_e('Color', 'sn-appointment-booking'); ?></label>
                                <div class="snab-color-picker-wrap">
                                    <input type="color" id="snab-type-color" name="color" value="#3788d8">
                                    <input type="text" id="snab-type-color-text" name="color_text" value="#3788d8" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                                    <span class="snab-color-preview-large" id="snab-color-preview"></span>
                                </div>
                            </div>

                            <div class="snab-form-row snab-form-row-checkboxes">
                                <label>
                                    <input type="checkbox" id="snab-type-active" name="is_active" value="1" checked>
                                    <?php esc_html_e('Active', 'sn-appointment-booking'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" id="snab-type-requires-approval" name="requires_approval" value="1">
                                    <?php esc_html_e('Requires Admin Approval', 'sn-appointment-booking'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" id="snab-type-requires-login" name="requires_login" value="1">
                                    <?php esc_html_e('Requires Login', 'sn-appointment-booking'); ?>
                                </label>
                            </div>
                        </div>

                        <div class="snab-modal-footer">
                            <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                            <button type="submit" class="button button-primary" id="snab-save-type-btn">
                                <span class="snab-btn-text"><?php esc_html_e('Save Type', 'sn-appointment-booking'); ?></span>
                                <span class="snab-spinner" style="display: none;"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div id="snab-delete-modal" class="snab-modal" style="display: none;">
                <div class="snab-modal-overlay"></div>
                <div class="snab-modal-content snab-modal-small">
                    <div class="snab-modal-header">
                        <h2><?php esc_html_e('Confirm Delete', 'sn-appointment-booking'); ?></h2>
                        <button type="button" class="snab-modal-close">&times;</button>
                    </div>
                    <div class="snab-modal-body">
                        <p><?php esc_html_e('Are you sure you want to delete this appointment type?', 'sn-appointment-booking'); ?></p>
                        <p class="snab-delete-warning">
                            <strong><?php esc_html_e('Warning:', 'sn-appointment-booking'); ?></strong>
                            <?php esc_html_e('This will not delete existing appointments of this type, but they will no longer be associated with a valid type.', 'sn-appointment-booking'); ?>
                        </p>
                        <input type="hidden" id="snab-delete-type-id" value="">
                    </div>
                    <div class="snab-modal-footer">
                        <button type="button" class="button snab-modal-cancel"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                        <button type="button" class="button button-link-delete" id="snab-confirm-delete-btn">
                            <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single type row.
     *
     * @param object $type The type object.
     */
    public function render_type_row($type) {
        ?>
        <tr data-type-id="<?php echo esc_attr($type->id); ?>" class="snab-type-row">
            <td class="column-order">
                <span class="snab-drag-handle dashicons dashicons-menu"></span>
                <span class="snab-sort-order"><?php echo esc_html($type->sort_order); ?></span>
            </td>
            <td class="column-name">
                <strong><?php echo esc_html($type->name); ?></strong>
                <div class="snab-type-slug"><?php echo esc_html($type->slug); ?></div>
                <?php if (!empty($type->description)): ?>
                    <div class="snab-type-description"><?php echo esc_html($type->description); ?></div>
                <?php endif; ?>
            </td>
            <td class="column-duration"><?php echo esc_html($type->duration_minutes); ?> <?php esc_html_e('min', 'sn-appointment-booking'); ?></td>
            <td class="column-buffer">
                <?php if ($type->buffer_before_minutes > 0): ?>
                    <span class="snab-buffer-badge"><?php echo esc_html($type->buffer_before_minutes); ?>m <?php esc_html_e('before', 'sn-appointment-booking'); ?></span>
                <?php endif; ?>
                <?php if ($type->buffer_after_minutes > 0): ?>
                    <span class="snab-buffer-badge"><?php echo esc_html($type->buffer_after_minutes); ?>m <?php esc_html_e('after', 'sn-appointment-booking'); ?></span>
                <?php endif; ?>
                <?php if ($type->buffer_before_minutes == 0 && $type->buffer_after_minutes == 0): ?>
                    <span class="snab-no-buffer"><?php esc_html_e('None', 'sn-appointment-booking'); ?></span>
                <?php endif; ?>
            </td>
            <td class="column-color">
                <span class="snab-color-swatch" style="background-color: <?php echo esc_attr($type->color); ?>;"></span>
            </td>
            <td class="column-status">
                <button type="button" class="snab-toggle-status <?php echo $type->is_active ? 'is-active' : 'is-inactive'; ?>"
                        data-type-id="<?php echo esc_attr($type->id); ?>"
                        title="<?php echo $type->is_active ? esc_attr__('Click to deactivate', 'sn-appointment-booking') : esc_attr__('Click to activate', 'sn-appointment-booking'); ?>">
                    <?php if ($type->is_active): ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-marker"></span>
                    <?php endif; ?>
                </button>
            </td>
            <td class="column-actions">
                <button type="button" class="button button-small snab-edit-type" data-type-id="<?php echo esc_attr($type->id); ?>">
                    <?php esc_html_e('Edit', 'sn-appointment-booking'); ?>
                </button>
                <button type="button" class="button button-small button-link-delete snab-delete-type" data-type-id="<?php echo esc_attr($type->id); ?>">
                    <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    /**
     * Get all appointment types.
     *
     * @return array Array of type objects.
     */
    public function get_all_types() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY sort_order, name");
    }

    /**
     * Get a single type by ID.
     *
     * @param int $id Type ID.
     * @return object|null Type object or null.
     */
    public function get_type($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }

    /**
     * Save a type (create or update).
     *
     * @param array $data Type data.
     * @return int|WP_Error Type ID on success, WP_Error on failure.
     */
    public function save_type($data) {
        global $wpdb;

        $id = isset($data['id']) ? absint($data['id']) : 0;
        $is_update = $id > 0;

        // Validate required fields
        if (empty($data['name'])) {
            return new WP_Error('missing_name', __('Name is required.', 'sn-appointment-booking'));
        }

        if (empty($data['duration_minutes']) || $data['duration_minutes'] < 5) {
            return new WP_Error('invalid_duration', __('Duration must be at least 5 minutes.', 'sn-appointment-booking'));
        }

        // Generate slug if not provided
        $slug = !empty($data['slug']) ? sanitize_title($data['slug']) : sanitize_title($data['name']);

        // Check for slug uniqueness
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE slug = %s AND id != %d",
            $slug,
            $id
        ));

        if ($existing) {
            // Append a number to make it unique
            $counter = 1;
            $original_slug = $slug;
            while ($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE slug = %s AND id != %d",
                $slug,
                $id
            ))) {
                $slug = $original_slug . '-' . $counter;
                $counter++;
            }
        }

        // Prepare data
        $type_data = array(
            'name' => sanitize_text_field($data['name']),
            'slug' => $slug,
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
            'duration_minutes' => absint($data['duration_minutes']),
            'buffer_before_minutes' => isset($data['buffer_before_minutes']) ? absint($data['buffer_before_minutes']) : 0,
            'buffer_after_minutes' => isset($data['buffer_after_minutes']) ? absint($data['buffer_after_minutes']) : 15,
            'color' => isset($data['color']) ? sanitize_hex_color($data['color']) : '#3788d8',
            'is_active' => isset($data['is_active']) ? absint($data['is_active']) : 1,
            'requires_approval' => isset($data['requires_approval']) ? absint($data['requires_approval']) : 0,
            'requires_login' => isset($data['requires_login']) ? absint($data['requires_login']) : 0,
            'updated_at' => current_time('mysql'),
        );

        $format = array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s');

        if ($is_update) {
            // Update existing
            $result = $wpdb->update(
                $this->table_name,
                $type_data,
                array('id' => $id),
                $format,
                array('%d')
            );

            if ($result === false) {
                return new WP_Error('db_error', __('Failed to update appointment type.', 'sn-appointment-booking'));
            }

            SNAB_Logger::info('Appointment type updated', array('id' => $id, 'name' => $type_data['name']));
            return $id;
        } else {
            // Get next sort order
            $max_order = $wpdb->get_var("SELECT MAX(sort_order) FROM {$this->table_name}");
            $type_data['sort_order'] = ($max_order !== null) ? $max_order + 1 : 0;
            $type_data['created_at'] = current_time('mysql');
            $format[] = '%d';
            $format[] = '%s';

            $result = $wpdb->insert($this->table_name, $type_data, $format);

            if ($result === false) {
                return new WP_Error('db_error', __('Failed to create appointment type.', 'sn-appointment-booking'));
            }

            $new_id = $wpdb->insert_id;
            SNAB_Logger::info('Appointment type created', array('id' => $new_id, 'name' => $type_data['name']));
            return $new_id;
        }
    }

    /**
     * Delete a type.
     *
     * @param int $id Type ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_type($id) {
        global $wpdb;

        // Check if type exists
        $type = $this->get_type($id);
        if (!$type) {
            return new WP_Error('not_found', __('Appointment type not found.', 'sn-appointment-booking'));
        }

        // Check if there are appointments using this type
        $appointments_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}snab_appointments WHERE appointment_type_id = %d",
            $id
        ));

        // Delete the type (appointments will have orphaned type_id, but we warned the user)
        $result = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to delete appointment type.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Appointment type deleted', array('id' => $id, 'name' => $type->name, 'had_appointments' => $appointments_count));
        return true;
    }

    /**
     * Toggle type active status.
     *
     * @param int $id Type ID.
     * @return bool|WP_Error New status on success, WP_Error on failure.
     */
    public function toggle_status($id) {
        global $wpdb;

        $type = $this->get_type($id);
        if (!$type) {
            return new WP_Error('not_found', __('Appointment type not found.', 'sn-appointment-booking'));
        }

        $new_status = $type->is_active ? 0 : 1;

        $result = $wpdb->update(
            $this->table_name,
            array('is_active' => $new_status, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to update status.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Appointment type status toggled', array('id' => $id, 'new_status' => $new_status ? 'active' : 'inactive'));
        return $new_status;
    }

    /**
     * Update type sort order.
     *
     * @param array $order Array of type IDs in new order.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function update_order($order) {
        global $wpdb;

        if (!is_array($order)) {
            return new WP_Error('invalid_data', __('Invalid order data.', 'sn-appointment-booking'));
        }

        foreach ($order as $position => $type_id) {
            $wpdb->update(
                $this->table_name,
                array('sort_order' => $position, 'updated_at' => current_time('mysql')),
                array('id' => absint($type_id)),
                array('%d', '%s'),
                array('%d')
            );
        }

        SNAB_Logger::info('Appointment types reordered', array('new_order' => $order));
        return true;
    }

    /**
     * AJAX: Save type.
     */
    public function ajax_save_type() {
        check_ajax_referer('snab_admin_nonce', 'snab_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $data = array(
            'id' => isset($_POST['type_id']) ? absint($_POST['type_id']) : 0,
            'name' => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'slug' => isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'duration_minutes' => isset($_POST['duration_minutes']) ? absint($_POST['duration_minutes']) : 60,
            'buffer_before_minutes' => isset($_POST['buffer_before_minutes']) ? absint($_POST['buffer_before_minutes']) : 0,
            'buffer_after_minutes' => isset($_POST['buffer_after_minutes']) ? absint($_POST['buffer_after_minutes']) : 15,
            'color' => isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '#3788d8',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
            'requires_login' => isset($_POST['requires_login']) ? 1 : 0,
        );

        $result = $this->save_type($data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        // Get the updated/created type to return
        $type = $this->get_type($result);

        // Render the row HTML
        ob_start();
        $this->render_type_row($type);
        $row_html = ob_get_clean();

        wp_send_json_success(array(
            'message' => $data['id'] > 0 ? __('Type updated successfully.', 'sn-appointment-booking') : __('Type created successfully.', 'sn-appointment-booking'),
            'type_id' => $result,
            'type' => $type,
            'row_html' => $row_html,
            'is_new' => $data['id'] == 0,
        ));
    }

    /**
     * AJAX: Delete type.
     */
    public function ajax_delete_type() {
        check_ajax_referer('snab_admin_nonce', 'snab_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;

        if (!$type_id) {
            wp_send_json_error(array('message' => __('Invalid type ID.', 'sn-appointment-booking')));
        }

        $result = $this->delete_type($type_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Type deleted successfully.', 'sn-appointment-booking'),
            'type_id' => $type_id,
        ));
    }

    /**
     * AJAX: Toggle status.
     */
    public function ajax_toggle_status() {
        check_ajax_referer('snab_admin_nonce', 'snab_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;

        if (!$type_id) {
            wp_send_json_error(array('message' => __('Invalid type ID.', 'sn-appointment-booking')));
        }

        $result = $this->toggle_status($type_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => $result ? __('Type activated.', 'sn-appointment-booking') : __('Type deactivated.', 'sn-appointment-booking'),
            'type_id' => $type_id,
            'new_status' => $result,
        ));
    }

    /**
     * AJAX: Update order.
     */
    public function ajax_update_order() {
        check_ajax_referer('snab_admin_nonce', 'snab_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $order = isset($_POST['order']) ? array_map('absint', $_POST['order']) : array();

        if (empty($order)) {
            wp_send_json_error(array('message' => __('Invalid order data.', 'sn-appointment-booking')));
        }

        $result = $this->update_order($order);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Order updated.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX: Get type data.
     */
    public function ajax_get_type() {
        check_ajax_referer('snab_admin_nonce', 'snab_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'sn-appointment-booking')));
        }

        $type_id = isset($_POST['type_id']) ? absint($_POST['type_id']) : 0;

        if (!$type_id) {
            wp_send_json_error(array('message' => __('Invalid type ID.', 'sn-appointment-booking')));
        }

        $type = $this->get_type($type_id);

        if (!$type) {
            wp_send_json_error(array('message' => __('Type not found.', 'sn-appointment-booking')));
        }

        wp_send_json_success(array('type' => $type));
    }
}
