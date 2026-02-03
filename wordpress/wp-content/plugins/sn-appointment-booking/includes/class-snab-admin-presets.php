<?php
/**
 * Admin Shortcode Presets Class
 *
 * Handles the admin interface for managing shortcode presets.
 *
 * @package SN_Appointment_Booking
 * @since 1.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Presets class.
 *
 * @since 1.2.0
 */
class SNAB_Admin_Presets {

    /**
     * Constructor.
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_snab_save_preset', array($this, 'ajax_save_preset'));
        add_action('wp_ajax_snab_get_preset', array($this, 'ajax_get_preset'));
        add_action('wp_ajax_snab_delete_preset', array($this, 'ajax_delete_preset'));
        add_action('wp_ajax_snab_toggle_preset', array($this, 'ajax_toggle_preset'));
    }

    /**
     * Render the presets page.
     */
    public function render() {
        global $wpdb;

        // Get all presets
        $presets = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}snab_shortcode_presets ORDER BY name ASC"
        );

        // Get appointment types for the form
        $types = $wpdb->get_results(
            "SELECT id, name, color FROM {$wpdb->prefix}snab_appointment_types WHERE is_active = 1 ORDER BY sort_order ASC"
        );

        // Days of week
        $days_of_week = array(
            0 => __('Sunday', 'sn-appointment-booking'),
            1 => __('Monday', 'sn-appointment-booking'),
            2 => __('Tuesday', 'sn-appointment-booking'),
            3 => __('Wednesday', 'sn-appointment-booking'),
            4 => __('Thursday', 'sn-appointment-booking'),
            5 => __('Friday', 'sn-appointment-booking'),
            6 => __('Saturday', 'sn-appointment-booking'),
        );

        ?>
        <div class="wrap snab-admin-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Shortcode Presets', 'sn-appointment-booking'); ?></h1>

            <a href="#" class="page-title-action" id="snab-add-preset">
                <?php esc_html_e('Add New Preset', 'sn-appointment-booking'); ?>
            </a>

            <hr class="wp-header-end">

            <p class="description">
                <?php esc_html_e('Create reusable shortcode configurations with specific settings for different use cases. For example, create a preset for "Building A Showings" that only shows certain appointment types during specific hours.', 'sn-appointment-booking'); ?>
            </p>

            <?php if (empty($presets)): ?>
                <div class="snab-empty-state">
                    <span class="dashicons dashicons-shortcode"></span>
                    <h3><?php esc_html_e('No presets yet', 'sn-appointment-booking'); ?></h3>
                    <p><?php esc_html_e('Create your first shortcode preset to get started.', 'sn-appointment-booking'); ?></p>
                    <button type="button" class="button button-primary" id="snab-add-preset-empty">
                        <?php esc_html_e('Create First Preset', 'sn-appointment-booking'); ?>
                    </button>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped snab-presets-table">
                    <thead>
                        <tr>
                            <th class="column-name"><?php esc_html_e('Name', 'sn-appointment-booking'); ?></th>
                            <th class="column-shortcode"><?php esc_html_e('Shortcode', 'sn-appointment-booking'); ?></th>
                            <th class="column-types"><?php esc_html_e('Appointment Types', 'sn-appointment-booking'); ?></th>
                            <th class="column-restrictions"><?php esc_html_e('Restrictions', 'sn-appointment-booking'); ?></th>
                            <th class="column-status"><?php esc_html_e('Status', 'sn-appointment-booking'); ?></th>
                            <th class="column-actions"><?php esc_html_e('Actions', 'sn-appointment-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($presets as $preset): ?>
                            <?php
                            // Get type names
                            $type_names = array();
                            if (!empty($preset->appointment_types)) {
                                $type_ids = array_map('intval', explode(',', $preset->appointment_types));
                                foreach ($types as $type) {
                                    if (in_array($type->id, $type_ids)) {
                                        $type_names[] = $type->name;
                                    }
                                }
                            }

                            // Build restrictions text
                            $restrictions = array();
                            if (!empty($preset->allowed_days)) {
                                $day_nums = explode(',', $preset->allowed_days);
                                $day_names = array();
                                foreach ($day_nums as $d) {
                                    if (isset($days_of_week[(int)$d])) {
                                        $day_names[] = substr($days_of_week[(int)$d], 0, 3);
                                    }
                                }
                                $restrictions[] = implode(', ', $day_names);
                            }
                            if ($preset->start_hour !== null || $preset->end_hour !== null) {
                                $start = $preset->start_hour !== null ? sprintf('%d:00', $preset->start_hour) : '0:00';
                                $end = $preset->end_hour !== null ? sprintf('%d:00', $preset->end_hour) : '24:00';
                                $restrictions[] = $start . ' - ' . $end;
                            }
                            ?>
                            <tr data-id="<?php echo esc_attr($preset->id); ?>">
                                <td class="column-name">
                                    <strong><?php echo esc_html($preset->name); ?></strong>
                                    <?php if (!empty($preset->description)): ?>
                                        <p class="description"><?php echo esc_html($preset->description); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="column-shortcode">
                                    <code class="snab-shortcode-display">[snab_booking_form preset="<?php echo esc_attr($preset->slug); ?>"]</code>
                                    <button type="button" class="button button-small snab-copy-shortcode"
                                            data-shortcode='[snab_booking_form preset="<?php echo esc_attr($preset->slug); ?>"]'>
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </td>
                                <td class="column-types">
                                    <?php if (empty($type_names)): ?>
                                        <span class="snab-all-types"><?php esc_html_e('All types', 'sn-appointment-booking'); ?></span>
                                    <?php else: ?>
                                        <?php echo esc_html(implode(', ', $type_names)); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="column-restrictions">
                                    <?php if (empty($restrictions)): ?>
                                        <span class="snab-no-restrictions"><?php esc_html_e('None', 'sn-appointment-booking'); ?></span>
                                    <?php else: ?>
                                        <?php echo esc_html(implode(' | ', $restrictions)); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="column-status">
                                    <?php if ($preset->is_active): ?>
                                        <span class="snab-status snab-status-active"><?php esc_html_e('Active', 'sn-appointment-booking'); ?></span>
                                    <?php else: ?>
                                        <span class="snab-status snab-status-inactive"><?php esc_html_e('Inactive', 'sn-appointment-booking'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="column-actions">
                                    <button type="button" class="button button-small snab-edit-preset"
                                            data-id="<?php echo esc_attr($preset->id); ?>">
                                        <?php esc_html_e('Edit', 'sn-appointment-booking'); ?>
                                    </button>
                                    <button type="button" class="button button-small snab-toggle-preset"
                                            data-id="<?php echo esc_attr($preset->id); ?>"
                                            data-active="<?php echo esc_attr($preset->is_active); ?>">
                                        <?php echo $preset->is_active ? esc_html__('Deactivate', 'sn-appointment-booking') : esc_html__('Activate', 'sn-appointment-booking'); ?>
                                    </button>
                                    <button type="button" class="button button-small snab-delete-preset"
                                            data-id="<?php echo esc_attr($preset->id); ?>">
                                        <?php esc_html_e('Delete', 'sn-appointment-booking'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Add/Edit Preset Modal -->
        <div id="snab-preset-modal" class="snab-modal" style="display: none;">
            <div class="snab-modal-content snab-modal-large">
                <div class="snab-modal-header">
                    <h2 id="snab-preset-modal-title"><?php esc_html_e('Add New Preset', 'sn-appointment-booking'); ?></h2>
                    <button type="button" class="snab-modal-close">&times;</button>
                </div>
                <div class="snab-modal-body">
                    <form id="snab-preset-form">
                        <input type="hidden" id="snab-preset-id" name="id" value="">

                        <div class="snab-form-grid">
                            <div class="snab-form-section">
                                <h3><?php esc_html_e('Basic Information', 'sn-appointment-booking'); ?></h3>

                                <div class="snab-form-row">
                                    <label for="snab-preset-name"><?php esc_html_e('Preset Name', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="text" id="snab-preset-name" name="name" required
                                           placeholder="<?php esc_attr_e('e.g., Building A Showings', 'sn-appointment-booking'); ?>">
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-slug"><?php esc_html_e('Slug', 'sn-appointment-booking'); ?> <span class="required">*</span></label>
                                    <input type="text" id="snab-preset-slug" name="slug" required
                                           pattern="[a-z0-9-]+"
                                           placeholder="<?php esc_attr_e('building-a-showings', 'sn-appointment-booking'); ?>">
                                    <p class="description"><?php esc_html_e('Used in the shortcode. Lowercase letters, numbers, and hyphens only.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-description"><?php esc_html_e('Description', 'sn-appointment-booking'); ?></label>
                                    <textarea id="snab-preset-description" name="description" rows="2"
                                              placeholder="<?php esc_attr_e('Optional description for admin reference', 'sn-appointment-booking'); ?>"></textarea>
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-title"><?php esc_html_e('Custom Widget Title', 'sn-appointment-booking'); ?></label>
                                    <input type="text" id="snab-preset-title" name="custom_title"
                                           placeholder="<?php esc_attr_e('e.g., Schedule a Showing', 'sn-appointment-booking'); ?>">
                                    <p class="description"><?php esc_html_e('Overrides the default "Book Appointment" title.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-location"><?php esc_html_e('Default Location', 'sn-appointment-booking'); ?></label>
                                    <input type="text" id="snab-preset-location" name="default_location"
                                           placeholder="<?php esc_attr_e('e.g., 123 Main St, Apt 4', 'sn-appointment-booking'); ?>">
                                    <p class="description"><?php esc_html_e('Pre-fills the property address field.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-css"><?php esc_html_e('CSS Class', 'sn-appointment-booking'); ?></label>
                                    <input type="text" id="snab-preset-css" name="css_class"
                                           placeholder="<?php esc_attr_e('custom-class', 'sn-appointment-booking'); ?>">
                                </div>
                            </div>

                            <div class="snab-form-section">
                                <h3><?php esc_html_e('Restrictions', 'sn-appointment-booking'); ?></h3>

                                <div class="snab-form-row">
                                    <label><?php esc_html_e('Appointment Types', 'sn-appointment-booking'); ?></label>
                                    <div class="snab-checkbox-group" id="snab-preset-types">
                                        <?php foreach ($types as $type): ?>
                                            <label class="snab-checkbox-label">
                                                <input type="checkbox" name="appointment_types[]" value="<?php echo esc_attr($type->id); ?>">
                                                <span class="snab-type-badge" style="background-color: <?php echo esc_attr($type->color); ?>">
                                                    <?php echo esc_html($type->name); ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description"><?php esc_html_e('Leave unchecked to show all types.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row">
                                    <label><?php esc_html_e('Allowed Days', 'sn-appointment-booking'); ?></label>
                                    <div class="snab-checkbox-group snab-days-group" id="snab-preset-days">
                                        <?php foreach ($days_of_week as $num => $name): ?>
                                            <label class="snab-checkbox-label snab-day-label">
                                                <input type="checkbox" name="allowed_days[]" value="<?php echo esc_attr($num); ?>">
                                                <?php echo esc_html(substr($name, 0, 3)); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description"><?php esc_html_e('Leave unchecked to allow all days.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row snab-time-range">
                                    <label><?php esc_html_e('Hour Restrictions', 'sn-appointment-booking'); ?></label>
                                    <div class="snab-time-inputs">
                                        <select id="snab-preset-start-hour" name="start_hour">
                                            <option value=""><?php esc_html_e('Any', 'sn-appointment-booking'); ?></option>
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?php echo $h; ?>"><?php echo sprintf('%02d:00', $h); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <span><?php esc_html_e('to', 'sn-appointment-booking'); ?></span>
                                        <select id="snab-preset-end-hour" name="end_hour">
                                            <option value=""><?php esc_html_e('Any', 'sn-appointment-booking'); ?></option>
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?php echo $h; ?>"><?php echo sprintf('%02d:00', $h); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <p class="description"><?php esc_html_e('Restrict available time slots to these hours.', 'sn-appointment-booking'); ?></p>
                                </div>

                                <div class="snab-form-row">
                                    <label for="snab-preset-weeks"><?php esc_html_e('Weeks to Show', 'sn-appointment-booking'); ?></label>
                                    <select id="snab-preset-weeks" name="weeks_to_show">
                                        <?php for ($w = 1; $w <= 8; $w++): ?>
                                            <option value="<?php echo $w; ?>" <?php selected($w, 2); ?>>
                                                <?php echo sprintf(_n('%d week', '%d weeks', $w, 'sn-appointment-booking'), $w); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="snab-shortcode-preview">
                            <h4><?php esc_html_e('Generated Shortcode', 'sn-appointment-booking'); ?></h4>
                            <code id="snab-generated-shortcode">[snab_booking_form preset=""]</code>
                            <button type="button" class="button button-small snab-copy-generated">
                                <span class="dashicons dashicons-clipboard"></span> <?php esc_html_e('Copy', 'sn-appointment-booking'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="snab-modal-footer">
                    <button type="button" class="button snab-modal-close"><?php esc_html_e('Cancel', 'sn-appointment-booking'); ?></button>
                    <button type="button" class="button button-primary snab-save-preset"><?php esc_html_e('Save Preset', 'sn-appointment-booking'); ?></button>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * AJAX: Save a preset (create or update).
     */
    public function ajax_save_preset() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'snab_shortcode_presets';

        // Get and validate data
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';

        if (empty($name) || empty($slug)) {
            wp_send_json_error(__('Name and slug are required.', 'sn-appointment-booking'));
        }

        // Check for duplicate slug
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
            $slug, $id
        ));

        if ($existing) {
            wp_send_json_error(__('A preset with this slug already exists.', 'sn-appointment-booking'));
        }

        // Prepare data
        $appointment_types = isset($_POST['appointment_types']) && is_array($_POST['appointment_types'])
            ? implode(',', array_map('absint', $_POST['appointment_types']))
            : '';

        $allowed_days = isset($_POST['allowed_days']) && is_array($_POST['allowed_days'])
            ? implode(',', array_map('absint', $_POST['allowed_days']))
            : '';

        $data = array(
            'name' => $name,
            'slug' => $slug,
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'appointment_types' => $appointment_types,
            'allowed_days' => $allowed_days,
            'start_hour' => isset($_POST['start_hour']) && $_POST['start_hour'] !== '' ? absint($_POST['start_hour']) : null,
            'end_hour' => isset($_POST['end_hour']) && $_POST['end_hour'] !== '' ? absint($_POST['end_hour']) : null,
            'weeks_to_show' => isset($_POST['weeks_to_show']) ? absint($_POST['weeks_to_show']) : 2,
            'default_location' => isset($_POST['default_location']) ? sanitize_text_field($_POST['default_location']) : '',
            'custom_title' => isset($_POST['custom_title']) ? sanitize_text_field($_POST['custom_title']) : '',
            'css_class' => isset($_POST['css_class']) ? sanitize_html_class($_POST['css_class']) : '',
            'updated_at' => current_time('mysql'),
        );

        $format = array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s');

        if ($id > 0) {
            // Update existing
            $result = $wpdb->update($table, $data, array('id' => $id), $format, array('%d'));
            $message = __('Preset updated successfully.', 'sn-appointment-booking');
        } else {
            // Create new
            $data['is_active'] = 1;
            $data['created_at'] = current_time('mysql');
            $format[] = '%d';
            $format[] = '%s';
            $result = $wpdb->insert($table, $data, $format);
            $id = $wpdb->insert_id;
            $message = __('Preset created successfully.', 'sn-appointment-booking');
        }

        if ($result === false) {
            wp_send_json_error(__('Failed to save preset.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Preset saved', array('id' => $id, 'slug' => $slug));

        wp_send_json_success(array(
            'message' => $message,
            'id' => $id,
        ));
    }

    /**
     * AJAX: Get preset data for editing.
     */
    public function ajax_get_preset() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(__('Invalid preset ID.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $preset = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}snab_shortcode_presets WHERE id = %d",
            $id
        ));

        if (!$preset) {
            wp_send_json_error(__('Preset not found.', 'sn-appointment-booking'));
        }

        // Convert comma-separated values to arrays
        $preset->appointment_types = !empty($preset->appointment_types)
            ? array_map('intval', explode(',', $preset->appointment_types))
            : array();

        $preset->allowed_days = !empty($preset->allowed_days)
            ? array_map('intval', explode(',', $preset->allowed_days))
            : array();

        wp_send_json_success(array('preset' => $preset));
    }

    /**
     * AJAX: Delete a preset.
     */
    public function ajax_delete_preset() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(__('Invalid preset ID.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $result = $wpdb->delete(
            $wpdb->prefix . 'snab_shortcode_presets',
            array('id' => $id),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to delete preset.', 'sn-appointment-booking'));
        }

        SNAB_Logger::info('Preset deleted', array('id' => $id));

        wp_send_json_success(array(
            'message' => __('Preset deleted successfully.', 'sn-appointment-booking'),
        ));
    }

    /**
     * AJAX: Toggle preset active status.
     */
    public function ajax_toggle_preset() {
        check_ajax_referer('snab_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'sn-appointment-booking'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        if (!$id) {
            wp_send_json_error(__('Invalid preset ID.', 'sn-appointment-booking'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'snab_shortcode_presets';

        // Get current status
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$table} WHERE id = %d",
            $id
        ));

        if ($current === null) {
            wp_send_json_error(__('Preset not found.', 'sn-appointment-booking'));
        }

        // Toggle
        $new_status = $current ? 0 : 1;
        $result = $wpdb->update(
            $table,
            array('is_active' => $new_status, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(__('Failed to update preset.', 'sn-appointment-booking'));
        }

        wp_send_json_success(array(
            'message' => $new_status
                ? __('Preset activated.', 'sn-appointment-booking')
                : __('Preset deactivated.', 'sn-appointment-booking'),
            'is_active' => $new_status,
        ));
    }

    /**
     * Get a preset by slug.
     *
     * @param string $slug Preset slug.
     * @return object|null Preset object or null.
     */
    public static function get_preset_by_slug($slug) {
        global $wpdb;

        $preset = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}snab_shortcode_presets WHERE slug = %s AND is_active = 1",
            $slug
        ));

        return $preset;
    }
}
