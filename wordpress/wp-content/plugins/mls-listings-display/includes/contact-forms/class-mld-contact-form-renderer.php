<?php
/**
 * Contact Form Renderer
 *
 * Generates frontend HTML for contact forms.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Renderer
 *
 * Renders contact forms on the frontend.
 */
class MLD_Contact_Form_Renderer {

    /**
     * Customizer settings cache
     *
     * @var array|null
     */
    private $customizer_settings = null;

    /**
     * Render a contact form
     *
     * @param int   $form_id Form ID
     * @param array $atts    Shortcode attributes
     * @return string HTML output
     */
    public function render_form($form_id, array $atts = []) {
        $manager = MLD_Contact_Form_Manager::get_instance();
        $form = $manager->get_form($form_id);

        if (!$form || $form->status !== 'active') {
            if (current_user_can('manage_options')) {
                return '<p class="mld-cf-error">' . esc_html__('Contact form not found or is not active.', 'mls-listings-display') . '</p>';
            }
            return '';
        }

        // Get fields
        $fields = isset($form->fields['fields']) ? $form->fields['fields'] : [];
        if (empty($fields)) {
            if (current_user_can('manage_options')) {
                return '<p class="mld-cf-error">' . esc_html__('This form has no fields. Please add fields in the form builder.', 'mls-listings-display') . '</p>';
            }
            return '';
        }

        // Get settings
        $settings = $form->settings;
        $layout_class = isset($settings['form_layout']) ? 'mld-cf-layout-' . $settings['form_layout'] : 'mld-cf-layout-vertical';

        // Check for multi-step (v6.23.0)
        $is_multistep = class_exists('MLD_Contact_Form_Multistep') && MLD_Contact_Form_Multistep::is_multistep_enabled($form);
        $multistep_settings = $is_multistep ? MLD_Contact_Form_Multistep::get_multistep_settings($form) : [];
        $steps = $is_multistep ? $multistep_settings['steps'] : [];
        $total_steps = count($steps);

        // Additional CSS classes from shortcode
        $extra_classes = !empty($atts['class']) ? ' ' . sanitize_html_class($atts['class']) : '';
        if ($is_multistep) {
            $extra_classes .= ' mld-cf-multistep';
        }

        // Prepare multi-step config for JS
        $multistep_config = '';
        if ($is_multistep) {
            $multistep_config = esc_attr(wp_json_encode(MLD_Contact_Form_Multistep::get_frontend_config($form, $fields)));
        }

        // Start output buffer
        ob_start();
        ?>
        <div class="mld-contact-form-wrapper<?php echo esc_attr($extra_classes); ?>">
            <form class="mld-contact-form <?php echo esc_attr($layout_class); ?>"
                  id="mld-contact-form-<?php echo esc_attr($form_id); ?>"
                  data-form-id="<?php echo esc_attr($form_id); ?>"
                  <?php if ($is_multistep): ?>data-multistep="<?php echo $multistep_config; ?>"<?php endif; ?>
                  method="post"
                  novalidate>

                <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('mld_contact_form_nonce')); ?>">
                <?php if ($is_multistep): ?>
                <input type="hidden" name="mld_current_step" value="1">
                <?php endif; ?>

                <?php
                // Honeypot field for spam protection
                if (!empty($settings['honeypot_enabled'])):
                ?>
                <div class="mld-cf-hp" aria-hidden="true" style="position: absolute; left: -9999px;">
                    <label for="mld_cf_hp_<?php echo esc_attr($form_id); ?>">Leave this field empty</label>
                    <input type="text" name="mld_cf_hp" id="mld_cf_hp_<?php echo esc_attr($form_id); ?>" tabindex="-1" autocomplete="off">
                </div>
                <?php endif; ?>

                <?php
                // Render progress indicator for multi-step forms
                if ($is_multistep && $total_steps > 1):
                    echo MLD_Contact_Form_Multistep::render_progress_indicator(
                        $steps,
                        1, // Start at step 1
                        $multistep_settings['multistep_progress_type'],
                        $multistep_settings['multistep_show_step_titles']
                    );
                endif;
                ?>

                <?php if ($is_multistep && $total_steps > 1): ?>
                    <?php
                    // Render multi-step form with step containers
                    $fields_by_step = MLD_Contact_Form_Multistep::organize_fields_by_step($fields, $steps);

                    for ($step_num = 1; $step_num <= $total_steps; $step_num++):
                        $step_fields = $fields_by_step[$step_num] ?? [];
                        $step_class = $step_num === 1 ? 'active' : '';
                    ?>
                    <div class="mld-cf-step <?php echo esc_attr($step_class); ?>" data-step="<?php echo esc_attr($step_num); ?>">
                        <?php if (!empty($steps[$step_num - 1]['description'])): ?>
                        <div class="mld-cf-step-description">
                            <?php echo esc_html($steps[$step_num - 1]['description']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="mld-cf-fields">
                            <?php echo $this->render_fields_group($step_fields); ?>
                        </div>

                        <?php
                        // Render step navigation
                        echo MLD_Contact_Form_Multistep::render_step_navigation(
                            $step_num,
                            $total_steps,
                            $multistep_settings['multistep_prev_button_text'],
                            $multistep_settings['multistep_next_button_text'],
                            $settings['submit_button_text'] ?? __('Send Message', 'mls-listings-display')
                        );
                        ?>
                    </div>
                    <?php endfor; ?>
                <?php else: ?>
                    <div class="mld-cf-fields">
                        <?php echo $this->render_fields_group($fields); ?>
                    </div>

                    <div class="mld-cf-submit-wrapper">
                        <button type="submit" class="mld-cf-submit">
                            <?php echo esc_html($settings['submit_button_text'] ?? __('Send Message', 'mls-listings-display')); ?>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="mld-cf-status" role="alert" aria-live="polite"></div>
            </form>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render a group of fields with row handling for half-width fields.
     *
     * @param array $fields Array of field definitions.
     * @return string HTML output.
     * @since 6.23.0
     */
    private function render_fields_group(array $fields) {
        // Sort fields by order
        usort($fields, function($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });

        $html = '';
        $in_row = false;

        foreach ($fields as $index => $field) {
            $is_half = isset($field['width']) && $field['width'] === 'half';
            $next_is_half = isset($fields[$index + 1]) && isset($fields[$index + 1]['width']) && $fields[$index + 1]['width'] === 'half';

            // Start row for half-width fields
            if ($is_half && !$in_row) {
                $html .= '<div class="mld-cf-row">';
                $in_row = true;
            }

            // Render the field
            $html .= $this->render_field($field);

            // End row after two half-width fields or when next is full
            if ($in_row && (!$next_is_half || !$is_half)) {
                $html .= '</div>';
                $in_row = false;
            }
        }

        // Close any open row
        if ($in_row) {
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Render a single field
     *
     * @param array $field Field definition
     * @return string HTML output
     */
    private function render_field(array $field) {
        $type = $field['type'] ?? 'text';
        $id = $field['id'] ?? 'field_' . uniqid();
        $label = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);
        $width_class = isset($field['width']) && $field['width'] === 'half' ? 'mld-cf-field-half' : 'mld-cf-field-full';

        // Get conditional data attribute (v6.22.0)
        $conditional_attr = '';
        if (class_exists('MLD_Contact_Form_Conditional')) {
            $conditional_attr = MLD_Contact_Form_Conditional::get_conditional_data_attributes($field);
        }

        // Special handling for display-only fields (section, paragraph)
        if ($type === 'section') {
            return $this->render_section_field($field, $conditional_attr);
        }

        if ($type === 'paragraph') {
            return $this->render_paragraph_field($field, $conditional_attr);
        }

        // Hidden fields don't have visible wrapper
        if ($type === 'hidden') {
            return $this->render_hidden_field($field, $conditional_attr);
        }

        $html = '<div class="mld-cf-field ' . esc_attr($width_class) . '" data-field-id="' . esc_attr($id) . '" ' . $conditional_attr . '>';

        // Label
        $html .= '<label for="' . esc_attr($id) . '">';
        $html .= esc_html($label);
        if ($required) {
            $html .= ' <span class="mld-cf-required">*</span>';
        }
        $html .= '</label>';

        // Render input based on type
        switch ($type) {
            case 'text':
                $html .= $this->render_text_field($field);
                break;

            case 'email':
                $html .= $this->render_email_field($field);
                break;

            case 'phone':
                $html .= $this->render_phone_field($field);
                break;

            case 'textarea':
                $html .= $this->render_textarea_field($field);
                break;

            case 'dropdown':
                $html .= $this->render_dropdown_field($field);
                break;

            case 'checkbox':
                $html .= $this->render_checkbox_field($field);
                break;

            case 'radio':
                $html .= $this->render_radio_field($field);
                break;

            case 'date':
                $html .= $this->render_date_field($field);
                break;

            // New field types added in v6.22.0
            case 'number':
                $html .= $this->render_number_field($field);
                break;

            case 'currency':
                $html .= $this->render_currency_field($field);
                break;

            case 'url':
                $html .= $this->render_url_field($field);
                break;

            // New field type added in v6.24.0
            case 'file':
                $html .= $this->render_file_field($field);
                break;

            default:
                $html .= $this->render_text_field($field);
        }

        // Error message container
        $html .= '<div class="mld-cf-field-error" role="alert"></div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render text field
     */
    private function render_text_field(array $field) {
        $attrs = $this->get_field_attributes($field);
        return '<input type="text" ' . $attrs . '>';
    }

    /**
     * Render email field
     */
    private function render_email_field(array $field) {
        $attrs = $this->get_field_attributes($field);
        return '<input type="email" ' . $attrs . '>';
    }

    /**
     * Render phone field
     */
    private function render_phone_field(array $field) {
        $attrs = $this->get_field_attributes($field);
        return '<input type="tel" ' . $attrs . '>';
    }

    /**
     * Render textarea field
     */
    private function render_textarea_field(array $field) {
        $id = $field['id'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);

        $html = '<textarea';
        $html .= ' id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr($id) . '"';
        $html .= ' placeholder="' . esc_attr($placeholder) . '"';
        $html .= ' rows="4"';
        if ($required) {
            $html .= ' required aria-required="true"';
        }
        $html .= '></textarea>';

        return $html;
    }

    /**
     * Render dropdown field
     */
    private function render_dropdown_field(array $field) {
        $id = $field['id'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? [];

        $html = '<select';
        $html .= ' id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr($id) . '"';
        if ($required) {
            $html .= ' required aria-required="true"';
        }
        $html .= '>';

        $html .= '<option value="">' . esc_html__('Select...', 'mls-listings-display') . '</option>';

        foreach ($options as $option) {
            $html .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    /**
     * Render checkbox field
     */
    private function render_checkbox_field(array $field) {
        $id = $field['id'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? [];

        $html = '<div class="mld-cf-checkbox-group"';
        if ($required) {
            $html .= ' data-required="true"';
        }
        $html .= '>';

        foreach ($options as $index => $option) {
            $option_id = $id . '_' . $index;
            $html .= '<label class="mld-cf-checkbox-label">';
            $html .= '<input type="checkbox" id="' . esc_attr($option_id) . '" name="' . esc_attr($id) . '[]" value="' . esc_attr($option) . '">';
            $html .= '<span>' . esc_html($option) . '</span>';
            $html .= '</label>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render radio field
     */
    private function render_radio_field(array $field) {
        $id = $field['id'] ?? '';
        $required = !empty($field['required']);
        $options = $field['options'] ?? [];

        $html = '<div class="mld-cf-radio-group">';

        foreach ($options as $index => $option) {
            $option_id = $id . '_' . $index;
            $html .= '<label class="mld-cf-radio-label">';
            $html .= '<input type="radio" id="' . esc_attr($option_id) . '" name="' . esc_attr($id) . '" value="' . esc_attr($option) . '"';
            if ($required) {
                $html .= ' required';
            }
            $html .= '>';
            $html .= '<span>' . esc_html($option) . '</span>';
            $html .= '</label>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render date field
     */
    private function render_date_field(array $field) {
        $attrs = $this->get_field_attributes($field);
        return '<input type="date" ' . $attrs . '>';
    }

    // =====================================================
    // New field types added in v6.22.0
    // =====================================================

    /**
     * Render number field
     */
    private function render_number_field(array $field) {
        $id = $field['id'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);
        $validation = $field['validation'] ?? [];

        $attrs = [];
        $attrs[] = 'type="number"';
        $attrs[] = 'id="' . esc_attr($id) . '"';
        $attrs[] = 'name="' . esc_attr($id) . '"';

        if (!empty($placeholder)) {
            $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';
        }

        if ($required) {
            $attrs[] = 'required';
            $attrs[] = 'aria-required="true"';
        }

        // Number-specific attributes
        if (isset($validation['min']) && $validation['min'] !== '') {
            $attrs[] = 'min="' . esc_attr($validation['min']) . '"';
        }
        if (isset($validation['max']) && $validation['max'] !== '') {
            $attrs[] = 'max="' . esc_attr($validation['max']) . '"';
        }
        if (isset($validation['step']) && $validation['step'] !== '') {
            $attrs[] = 'step="' . esc_attr($validation['step']) . '"';
        }

        return '<input ' . implode(' ', $attrs) . '>';
    }

    /**
     * Render currency field
     */
    private function render_currency_field(array $field) {
        $id = $field['id'] ?? '';
        $placeholder = $field['placeholder'] ?? '0.00';
        $required = !empty($field['required']);
        $validation = $field['validation'] ?? [];
        $currency_symbol = $validation['currency_symbol'] ?? '$';
        $decimal_places = $validation['decimal_places'] ?? 2;

        $html = '<div class="mld-cf-currency-wrapper">';
        $html .= '<span class="mld-cf-currency-symbol">' . esc_html($currency_symbol) . '</span>';

        $attrs = [];
        $attrs[] = 'type="number"';
        $attrs[] = 'id="' . esc_attr($id) . '"';
        $attrs[] = 'name="' . esc_attr($id) . '"';
        $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';
        $attrs[] = 'step="' . (1 / pow(10, $decimal_places)) . '"';
        $attrs[] = 'class="mld-cf-currency-input"';

        if ($required) {
            $attrs[] = 'required';
            $attrs[] = 'aria-required="true"';
        }

        if (isset($validation['min']) && $validation['min'] !== '') {
            $attrs[] = 'min="' . esc_attr($validation['min']) . '"';
        }
        if (isset($validation['max']) && $validation['max'] !== '') {
            $attrs[] = 'max="' . esc_attr($validation['max']) . '"';
        }

        $html .= '<input ' . implode(' ', $attrs) . '>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render URL field
     */
    private function render_url_field(array $field) {
        $id = $field['id'] ?? '';
        $placeholder = $field['placeholder'] ?? 'https://example.com';
        $required = !empty($field['required']);

        $attrs = [];
        $attrs[] = 'type="url"';
        $attrs[] = 'id="' . esc_attr($id) . '"';
        $attrs[] = 'name="' . esc_attr($id) . '"';
        $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';

        if ($required) {
            $attrs[] = 'required';
            $attrs[] = 'aria-required="true"';
        }

        return '<input ' . implode(' ', $attrs) . '>';
    }

    /**
     * Render file upload field (v6.24.0)
     */
    private function render_file_field(array $field) {
        $id = $field['id'] ?? '';
        $required = !empty($field['required']);
        $file_config = isset($field['file_config']) ? $field['file_config'] : [];

        $allowed_types = isset($file_config['allowed_types']) ? $file_config['allowed_types'] : ['pdf', 'jpg', 'jpeg', 'png'];
        $max_size_mb = isset($file_config['max_size_mb']) ? floatval($file_config['max_size_mb']) : 5;
        $max_files = isset($file_config['max_files']) ? intval($file_config['max_files']) : 3;

        // Get accept attribute
        $upload_handler = class_exists('MLD_Contact_Form_Upload') ? MLD_Contact_Form_Upload::get_instance() : null;
        $accept = $upload_handler ? $upload_handler->get_accept_attribute($allowed_types) : '.' . implode(',.', $allowed_types);
        $allowed_display = strtoupper(implode(', ', $allowed_types));

        $html = '<div class="mld-cf-file-upload-wrapper" data-field-id="' . esc_attr($id) . '"';
        $html .= ' data-max-files="' . esc_attr($max_files) . '"';
        $html .= ' data-max-size="' . esc_attr($max_size_mb * 1024 * 1024) . '"';
        $html .= ' data-allowed-types="' . esc_attr(implode(',', $allowed_types)) . '">';

        // Drag-drop zone
        $html .= '<div class="mld-cf-dropzone">';
        $html .= '<div class="mld-cf-dropzone-content">';
        $html .= '<span class="dashicons dashicons-cloud-upload mld-cf-dropzone-icon"></span>';
        $html .= '<p class="mld-cf-dropzone-text">' . esc_html__('Drag and drop files here, or', 'mls-listings-display') . '</p>';
        $html .= '<button type="button" class="mld-cf-dropzone-browse">' . esc_html__('Browse Files', 'mls-listings-display') . '</button>';
        $html .= '</div>';
        $html .= '<p class="mld-cf-dropzone-info">';
        $html .= sprintf(
            esc_html__('Allowed: %s | Max size: %s | Max files: %d', 'mls-listings-display'),
            $allowed_display,
            size_format($max_size_mb * 1024 * 1024),
            $max_files
        );
        $html .= '</p>';
        $html .= '</div>';

        // Hidden file input
        $html .= '<input type="file" id="' . esc_attr($id) . '_input" class="mld-cf-file-input"';
        $html .= ' accept="' . esc_attr($accept) . '"';
        $html .= ' multiple';
        if ($required) {
            $html .= ' data-required="true"';
        }
        $html .= '>';

        // Hidden input for upload tokens (submitted with form)
        $html .= '<input type="hidden" name="' . esc_attr($id) . '_tokens" id="' . esc_attr($id) . '_tokens" value="">';

        // File preview list
        $html .= '<div class="mld-cf-file-list" id="' . esc_attr($id) . '_list"></div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render hidden field
     *
     * @param array  $field            Field definition
     * @param string $conditional_attr Conditional data attribute (v6.22.0)
     */
    private function render_hidden_field(array $field, string $conditional_attr = '') {
        $id = $field['id'] ?? '';
        $default_value = $field['default_value'] ?? '';

        // Hidden fields with conditional logic need a wrapper for show/hide
        if (!empty($conditional_attr)) {
            return '<div class="mld-cf-hidden-wrapper" data-field-id="' . esc_attr($id) . '" ' . $conditional_attr . '>' .
                   '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" value="' . esc_attr($default_value) . '">' .
                   '</div>';
        }

        return '<input type="hidden" id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" value="' . esc_attr($default_value) . '">';
    }

    /**
     * Render section heading field (display only, no input)
     *
     * @param array  $field            Field definition
     * @param string $conditional_attr Conditional data attribute (v6.22.0)
     */
    private function render_section_field(array $field, string $conditional_attr = '') {
        $id = $field['id'] ?? '';
        $label = $field['label'] ?? 'Section';
        $description = $field['description'] ?? '';
        $width_class = isset($field['width']) && $field['width'] === 'half' ? 'mld-cf-field-half' : 'mld-cf-field-full';

        $html = '<div class="mld-cf-field mld-cf-section ' . esc_attr($width_class) . '" data-field-id="' . esc_attr($id) . '" ' . $conditional_attr . '>';
        $html .= '<h4 class="mld-cf-section-title">' . esc_html($label) . '</h4>';
        if (!empty($description)) {
            $html .= '<p class="mld-cf-section-description">' . esc_html($description) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render paragraph field (display only, static text)
     *
     * @param array  $field            Field definition
     * @param string $conditional_attr Conditional data attribute (v6.22.0)
     */
    private function render_paragraph_field(array $field, string $conditional_attr = '') {
        $id = $field['id'] ?? '';
        $content = $field['content'] ?? '';
        $width_class = isset($field['width']) && $field['width'] === 'half' ? 'mld-cf-field-half' : 'mld-cf-field-full';

        $html = '<div class="mld-cf-field mld-cf-paragraph ' . esc_attr($width_class) . '" data-field-id="' . esc_attr($id) . '" ' . $conditional_attr . '>';
        $html .= '<div class="mld-cf-paragraph-content">' . wp_kses_post($content) . '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get common field attributes
     */
    private function get_field_attributes(array $field) {
        $id = $field['id'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required = !empty($field['required']);

        $attrs = [];
        $attrs[] = 'id="' . esc_attr($id) . '"';
        $attrs[] = 'name="' . esc_attr($id) . '"';

        if (!empty($placeholder)) {
            $attrs[] = 'placeholder="' . esc_attr($placeholder) . '"';
        }

        if ($required) {
            $attrs[] = 'required';
            $attrs[] = 'aria-required="true"';
        }

        // Add validation attributes
        if (isset($field['validation'])) {
            if (!empty($field['validation']['min_length'])) {
                $attrs[] = 'minlength="' . intval($field['validation']['min_length']) . '"';
            }
            if (!empty($field['validation']['max_length'])) {
                $attrs[] = 'maxlength="' . intval($field['validation']['max_length']) . '"';
            }
            if (!empty($field['validation']['pattern'])) {
                $attrs[] = 'pattern="' . esc_attr($field['validation']['pattern']) . '"';
            }
        }

        return implode(' ', $attrs);
    }

    /**
     * Get Customizer settings for inline CSS
     */
    public function get_customizer_settings() {
        if ($this->customizer_settings !== null) {
            return $this->customizer_settings;
        }

        // Default values
        $defaults = [
            'bg_color' => '#ffffff',
            'text_color' => '#1f2937',
            'label_color' => '#374151',
            'border_color' => '#e5e7eb',
            'focus_color' => '#0891B2',
            'button_bg' => '#0891B2',
            'button_hover_bg' => '#0E7490',
            'button_text' => '#ffffff',
            'error_color' => '#DC2626',
            'success_color' => '#10B981',
            'font_family' => 'inherit',
            'label_size' => 14,
            'input_size' => 14,
            'button_size' => 16,
            'label_weight' => '600',
            'form_padding' => 24,
            'field_gap' => 16,
            'input_padding_v' => 12,
            'input_padding_h' => 14,
            'button_radius' => 6,
            'form_radius' => 8,
            'show_border' => true,
            'form_shadow' => 'small'
        ];

        $this->customizer_settings = [];

        foreach ($defaults as $key => $default) {
            $this->customizer_settings[$key] = get_theme_mod('mld_cf_' . $key, $default);
        }

        return $this->customizer_settings;
    }

    /**
     * Generate inline CSS from Customizer settings
     */
    public function get_customizer_inline_styles() {
        $settings = $this->get_customizer_settings();

        $css = ':root {';
        $css .= '--mld-cf-bg-color: ' . esc_attr($settings['bg_color']) . ';';
        $css .= '--mld-cf-text-color: ' . esc_attr($settings['text_color']) . ';';
        $css .= '--mld-cf-label-color: ' . esc_attr($settings['label_color']) . ';';
        $css .= '--mld-cf-border-color: ' . esc_attr($settings['border_color']) . ';';
        $css .= '--mld-cf-focus-color: ' . esc_attr($settings['focus_color']) . ';';
        $css .= '--mld-cf-button-bg: ' . esc_attr($settings['button_bg']) . ';';
        $css .= '--mld-cf-button-hover-bg: ' . esc_attr($settings['button_hover_bg']) . ';';
        $css .= '--mld-cf-button-text: ' . esc_attr($settings['button_text']) . ';';
        $css .= '--mld-cf-error-color: ' . esc_attr($settings['error_color']) . ';';
        $css .= '--mld-cf-success-color: ' . esc_attr($settings['success_color']) . ';';
        $css .= '--mld-cf-font-family: ' . esc_attr($settings['font_family']) . ';';
        $css .= '--mld-cf-label-size: ' . intval($settings['label_size']) . 'px;';
        $css .= '--mld-cf-input-size: ' . intval($settings['input_size']) . 'px;';
        $css .= '--mld-cf-button-size: ' . intval($settings['button_size']) . 'px;';
        $css .= '--mld-cf-label-weight: ' . esc_attr($settings['label_weight']) . ';';
        $css .= '--mld-cf-form-padding: ' . intval($settings['form_padding']) . 'px;';
        $css .= '--mld-cf-field-gap: ' . intval($settings['field_gap']) . 'px;';
        $css .= '--mld-cf-input-padding-v: ' . intval($settings['input_padding_v']) . 'px;';
        $css .= '--mld-cf-input-padding-h: ' . intval($settings['input_padding_h']) . 'px;';
        $css .= '--mld-cf-button-radius: ' . intval($settings['button_radius']) . 'px;';
        $css .= '--mld-cf-form-radius: ' . intval($settings['form_radius']) . 'px;';
        $css .= '}';

        return $css;
    }
}
