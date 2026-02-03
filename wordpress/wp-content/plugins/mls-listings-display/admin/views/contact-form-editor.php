<?php
/**
 * Contact Form Editor Admin View
 *
 * Drag-and-drop form builder interface.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$manager = MLD_Contact_Form_Manager::get_instance();
$form_id = isset($_GET['form_id']) ? absint($_GET['form_id']) : 0;
$is_new = empty($form_id);

// Get form data or defaults for new form
if ($is_new) {
    $form = (object) [
        'id' => 0,
        'form_name' => '',
        'form_slug' => '',
        'description' => '',
        'fields' => ['fields' => []],
        'settings' => [
            'submit_button_text' => 'Send Message',
            'success_message' => 'Thank you for your message! We will get back to you soon.',
            'redirect_url' => '',
            'honeypot_enabled' => true,
            'form_layout' => 'vertical'
        ],
        'notification_settings' => [
            'admin_email_enabled' => true,
            'admin_email_subject' => 'New Contact Form Submission - {form_name}',
            'additional_recipients' => '',
            'user_confirmation_enabled' => true,
            'user_confirmation_subject' => 'Thank you for contacting us!',
            'user_confirmation_message' => "Hi {field_first_name},\n\nThank you for reaching out. We have received your message and will get back to you soon.\n\nBest regards,\n{site_name}"
        ],
        'status' => 'active'
    ];
} else {
    $form = $manager->get_form($form_id);
    if (!$form) {
        wp_die(__('Form not found.', 'mls-listings-display'));
    }
}

// Get field types
$field_types = $manager->get_field_types();

// Prepare form data for JavaScript
$form_data_json = wp_json_encode([
    'id' => $form->id,
    'form_name' => $form->form_name,
    'form_slug' => $form->form_slug ?? '',
    'description' => $form->description ?? '',
    'fields' => $form->fields,
    'settings' => $form->settings,
    'notification_settings' => $form->notification_settings,
    'status' => $form->status ?? 'active'
]);

$base_url = admin_url('admin.php?page=mld_contact_forms');
?>

<div class="wrap mld-form-editor-wrap">
    <h1 class="wp-heading-inline">
        <?php echo $is_new ? esc_html__('Create New Contact Form', 'mls-listings-display') : esc_html__('Edit Contact Form', 'mls-listings-display'); ?>
    </h1>
    <a href="<?php echo esc_url($base_url); ?>" class="page-title-action">
        <?php esc_html_e('Back to Forms', 'mls-listings-display'); ?>
    </a>

    <?php if (!$is_new): ?>
        <span class="mld-shortcode-display" style="margin-left: 15px;">
            <strong><?php esc_html_e('Shortcode:', 'mls-listings-display'); ?></strong>
            <code id="form-shortcode"><?php echo esc_html($manager->generate_shortcode($form->id)); ?></code>
            <button type="button" class="button button-small mld-copy-shortcode" data-shortcode="<?php echo esc_attr($manager->generate_shortcode($form->id)); ?>">
                <span class="dashicons dashicons-clipboard" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
            </button>
        </span>
    <?php endif; ?>

    <hr class="wp-header-end">

    <div class="mld-form-builder" id="mld-form-builder">
        <!-- Left Panel: Field Types -->
        <div class="mld-builder-sidebar mld-field-types-panel">
            <h3><?php esc_html_e('Add Fields', 'mls-listings-display'); ?></h3>
            <p class="description"><?php esc_html_e('Drag fields to the form canvas or click to add.', 'mls-listings-display'); ?></p>

            <div class="mld-field-types-list">
                <?php foreach ($field_types as $type => $info): ?>
                    <div class="mld-field-type-item" data-field-type="<?php echo esc_attr($type); ?>" draggable="true">
                        <span class="dashicons <?php echo esc_attr($info['icon']); ?>"></span>
                        <span class="mld-field-type-label"><?php echo esc_html($info['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Center Panel: Form Canvas -->
        <div class="mld-builder-canvas">
            <div class="mld-canvas-header">
                <div class="mld-form-name-input">
                    <label for="form-name" class="screen-reader-text"><?php esc_html_e('Form Name', 'mls-listings-display'); ?></label>
                    <input type="text" id="form-name" class="mld-form-name" placeholder="<?php esc_attr_e('Enter form name...', 'mls-listings-display'); ?>" value="<?php echo esc_attr($form->form_name); ?>">
                </div>
                <div class="mld-canvas-actions">
                    <select id="form-status" class="mld-form-status">
                        <option value="active" <?php selected($form->status ?? 'active', 'active'); ?>><?php esc_html_e('Active', 'mls-listings-display'); ?></option>
                        <option value="draft" <?php selected($form->status ?? 'active', 'draft'); ?>><?php esc_html_e('Draft', 'mls-listings-display'); ?></option>
                        <option value="archived" <?php selected($form->status ?? 'active', 'archived'); ?>><?php esc_html_e('Archived', 'mls-listings-display'); ?></option>
                    </select>
                    <button type="button" id="save-form" class="button button-primary">
                        <span class="dashicons dashicons-cloud-upload" style="margin-top: 3px;"></span>
                        <?php esc_html_e('Save Form', 'mls-listings-display'); ?>
                    </button>
                </div>
            </div>

            <div class="mld-form-canvas-area" id="form-canvas">
                <div class="mld-canvas-dropzone" id="field-dropzone">
                    <div class="mld-canvas-placeholder" id="canvas-placeholder">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <p><?php esc_html_e('Drag fields here or click a field type to add', 'mls-listings-display'); ?></p>
                    </div>
                    <div class="mld-fields-container" id="fields-container">
                        <!-- Fields will be rendered here by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Form Preview Button -->
            <div class="mld-canvas-footer">
                <button type="button" id="preview-form" class="button">
                    <span class="dashicons dashicons-visibility" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Preview Form', 'mls-listings-display'); ?>
                </button>
                <span class="mld-save-status" id="save-status"></span>
            </div>
        </div>

        <!-- Right Panel: Field Properties / Form Settings -->
        <div class="mld-builder-sidebar mld-properties-panel">
            <!-- Tabs -->
            <div class="mld-properties-tabs">
                <button type="button" class="mld-tab-btn active" data-tab="field-properties">
                    <?php esc_html_e('Field', 'mls-listings-display'); ?>
                </button>
                <button type="button" class="mld-tab-btn" data-tab="form-settings">
                    <?php esc_html_e('Settings', 'mls-listings-display'); ?>
                </button>
                <button type="button" class="mld-tab-btn" data-tab="notifications">
                    <?php esc_html_e('Notifications', 'mls-listings-display'); ?>
                </button>
            </div>

            <!-- Field Properties Tab -->
            <div class="mld-tab-content active" id="field-properties">
                <div class="mld-no-field-selected">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                    <p><?php esc_html_e('Click on a field in the form to edit its properties.', 'mls-listings-display'); ?></p>
                </div>
                <div class="mld-field-properties-form" id="field-properties-form" style="display: none;">
                    <!-- Field properties will be rendered here by JavaScript -->
                </div>
            </div>

            <!-- Form Settings Tab -->
            <div class="mld-tab-content" id="form-settings">
                <div class="mld-settings-group">
                    <label for="setting-description"><?php esc_html_e('Form Description', 'mls-listings-display'); ?></label>
                    <textarea id="setting-description" rows="2" placeholder="<?php esc_attr_e('Optional description for admin reference', 'mls-listings-display'); ?>"><?php echo esc_textarea($form->description ?? ''); ?></textarea>
                </div>

                <div class="mld-settings-group">
                    <label for="setting-submit-text"><?php esc_html_e('Submit Button Text', 'mls-listings-display'); ?></label>
                    <input type="text" id="setting-submit-text" value="<?php echo esc_attr($form->settings['submit_button_text'] ?? 'Send Message'); ?>">
                </div>

                <div class="mld-settings-group">
                    <label for="setting-success-message"><?php esc_html_e('Success Message', 'mls-listings-display'); ?></label>
                    <textarea id="setting-success-message" rows="3"><?php echo esc_textarea($form->settings['success_message'] ?? ''); ?></textarea>
                    <p class="description"><?php esc_html_e('Displayed after successful form submission.', 'mls-listings-display'); ?></p>
                </div>

                <div class="mld-settings-group">
                    <label for="setting-redirect-url"><?php esc_html_e('Redirect URL (Optional)', 'mls-listings-display'); ?></label>
                    <input type="url" id="setting-redirect-url" value="<?php echo esc_url($form->settings['redirect_url'] ?? ''); ?>" placeholder="https://">
                    <p class="description"><?php esc_html_e('Redirect users to this URL after submission. Leave empty to show success message.', 'mls-listings-display'); ?></p>
                </div>

                <div class="mld-settings-group">
                    <label for="setting-form-layout"><?php esc_html_e('Form Layout', 'mls-listings-display'); ?></label>
                    <select id="setting-form-layout">
                        <option value="vertical" <?php selected($form->settings['form_layout'] ?? 'vertical', 'vertical'); ?>><?php esc_html_e('Vertical (stacked)', 'mls-listings-display'); ?></option>
                        <option value="horizontal" <?php selected($form->settings['form_layout'] ?? 'vertical', 'horizontal'); ?>><?php esc_html_e('Horizontal (inline)', 'mls-listings-display'); ?></option>
                    </select>
                </div>

                <div class="mld-settings-group">
                    <label class="mld-checkbox-label">
                        <input type="checkbox" id="setting-honeypot" <?php checked($form->settings['honeypot_enabled'] ?? true); ?>>
                        <?php esc_html_e('Enable spam protection (honeypot)', 'mls-listings-display'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Adds an invisible field to catch bots.', 'mls-listings-display'); ?></p>
                </div>

                <hr style="margin: 20px 0;">
                <h4 style="margin-bottom: 16px;"><?php esc_html_e('Multi-Step Wizard', 'mls-listings-display'); ?></h4>

                <div class="mld-settings-group">
                    <label class="mld-checkbox-label">
                        <input type="checkbox" id="setting-multistep-enabled" <?php checked($form->settings['multistep_enabled'] ?? false); ?>>
                        <?php esc_html_e('Enable multi-step form', 'mls-listings-display'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Split the form into multiple steps with navigation.', 'mls-listings-display'); ?></p>
                </div>

                <div class="mld-multistep-settings" id="multistep-settings" style="<?php echo empty($form->settings['multistep_enabled']) ? 'display: none;' : ''; ?>">
                    <div class="mld-settings-group">
                        <label for="setting-progress-type"><?php esc_html_e('Progress Indicator', 'mls-listings-display'); ?></label>
                        <select id="setting-progress-type">
                            <option value="steps" <?php selected($form->settings['multistep_progress_type'] ?? 'steps', 'steps'); ?>><?php esc_html_e('Step Numbers', 'mls-listings-display'); ?></option>
                            <option value="bar" <?php selected($form->settings['multistep_progress_type'] ?? 'steps', 'bar'); ?>><?php esc_html_e('Progress Bar', 'mls-listings-display'); ?></option>
                        </select>
                    </div>

                    <div class="mld-settings-group">
                        <label class="mld-checkbox-label">
                            <input type="checkbox" id="setting-show-step-titles" <?php checked($form->settings['multistep_show_step_titles'] ?? true); ?>>
                            <?php esc_html_e('Show step titles', 'mls-listings-display'); ?>
                        </label>
                    </div>

                    <div class="mld-settings-group mld-inline-buttons">
                        <div>
                            <label for="setting-prev-button"><?php esc_html_e('Previous Button', 'mls-listings-display'); ?></label>
                            <input type="text" id="setting-prev-button" value="<?php echo esc_attr($form->settings['multistep_prev_button_text'] ?? 'Previous'); ?>">
                        </div>
                        <div>
                            <label for="setting-next-button"><?php esc_html_e('Next Button', 'mls-listings-display'); ?></label>
                            <input type="text" id="setting-next-button" value="<?php echo esc_attr($form->settings['multistep_next_button_text'] ?? 'Next'); ?>">
                        </div>
                    </div>

                    <div class="mld-settings-group">
                        <label><?php esc_html_e('Form Steps', 'mls-listings-display'); ?></label>
                        <div class="mld-steps-list" id="steps-list">
                            <!-- Steps will be rendered by JavaScript -->
                        </div>
                        <button type="button" class="button button-small" id="add-step-btn">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-top: -2px;"></span>
                            <?php esc_html_e('Add Step', 'mls-listings-display'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Notifications Tab -->
            <div class="mld-tab-content" id="notifications">
                <h4><?php esc_html_e('Admin Notification', 'mls-listings-display'); ?></h4>

                <div class="mld-settings-group">
                    <label class="mld-checkbox-label">
                        <input type="checkbox" id="notif-admin-enabled" <?php checked($form->notification_settings['admin_email_enabled'] ?? true); ?>>
                        <?php esc_html_e('Send email to admin on new submission', 'mls-listings-display'); ?>
                    </label>
                </div>

                <div class="mld-settings-group">
                    <label for="notif-admin-subject"><?php esc_html_e('Admin Email Subject', 'mls-listings-display'); ?></label>
                    <input type="text" id="notif-admin-subject" value="<?php echo esc_attr($form->notification_settings['admin_email_subject'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('Available: {form_name}, {site_name}', 'mls-listings-display'); ?></p>
                </div>

                <div class="mld-settings-group">
                    <label for="notif-additional-recipients"><?php esc_html_e('Additional Recipients', 'mls-listings-display'); ?></label>
                    <input type="text" id="notif-additional-recipients" value="<?php echo esc_attr($form->notification_settings['additional_recipients'] ?? ''); ?>" placeholder="email1@example.com, email2@example.com">
                    <p class="description"><?php esc_html_e('Comma-separated list of additional emails to notify.', 'mls-listings-display'); ?></p>
                </div>

                <hr>

                <h4><?php esc_html_e('User Confirmation Email', 'mls-listings-display'); ?></h4>

                <div class="mld-settings-group">
                    <label class="mld-checkbox-label">
                        <input type="checkbox" id="notif-user-enabled" <?php checked($form->notification_settings['user_confirmation_enabled'] ?? true); ?>>
                        <?php esc_html_e('Send confirmation email to user', 'mls-listings-display'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Requires an Email field in your form.', 'mls-listings-display'); ?></p>
                </div>

                <div class="mld-settings-group">
                    <label for="notif-user-subject"><?php esc_html_e('Confirmation Email Subject', 'mls-listings-display'); ?></label>
                    <input type="text" id="notif-user-subject" value="<?php echo esc_attr($form->notification_settings['user_confirmation_subject'] ?? ''); ?>">
                </div>

                <div class="mld-settings-group">
                    <label for="notif-user-message"><?php esc_html_e('Confirmation Email Message', 'mls-listings-display'); ?></label>
                    <textarea id="notif-user-message" rows="6"><?php echo esc_textarea($form->notification_settings['user_confirmation_message'] ?? ''); ?></textarea>
                    <p class="description"><?php esc_html_e('Available placeholders: {field_*}, {form_name}, {site_name}, {site_url}', 'mls-listings-display'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Field Properties Template (used by JavaScript) -->
<script type="text/template" id="field-properties-template">
    <div class="mld-property-header">
        <span class="mld-field-type-badge">{{field_type_label}}</span>
        <button type="button" class="mld-remove-field button-link-delete" data-field-id="{{field_id}}">
            <span class="dashicons dashicons-trash"></span>
        </button>
    </div>

    <div class="mld-settings-group">
        <label><?php esc_html_e('Label', 'mls-listings-display'); ?></label>
        <input type="text" class="mld-field-prop" data-prop="label" value="{{label}}">
    </div>

    <div class="mld-settings-group">
        <label><?php esc_html_e('Placeholder', 'mls-listings-display'); ?></label>
        <input type="text" class="mld-field-prop" data-prop="placeholder" value="{{placeholder}}">
    </div>

    <div class="mld-settings-group">
        <label class="mld-checkbox-label">
            <input type="checkbox" class="mld-field-prop" data-prop="required" {{required_checked}}>
            <?php esc_html_e('Required field', 'mls-listings-display'); ?>
        </label>
    </div>

    <div class="mld-settings-group">
        <label><?php esc_html_e('Field Width', 'mls-listings-display'); ?></label>
        <select class="mld-field-prop" data-prop="width">
            <option value="full" {{width_full_selected}}><?php esc_html_e('Full Width', 'mls-listings-display'); ?></option>
            <option value="half" {{width_half_selected}}><?php esc_html_e('Half Width', 'mls-listings-display'); ?></option>
        </select>
    </div>

    <div class="mld-options-editor" style="{{options_display}}">
        <label><?php esc_html_e('Options', 'mls-listings-display'); ?></label>
        <div class="mld-options-list" id="options-list-{{field_id}}">
            <!-- Options will be rendered here -->
        </div>
        <button type="button" class="button mld-add-option" data-field-id="{{field_id}}">
            <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
            <?php esc_html_e('Add Option', 'mls-listings-display'); ?>
        </button>
    </div>
</script>

<!-- Pass form data to JavaScript -->
<script>
    var mldFormData = <?php echo $form_data_json; ?>;
    var mldFieldTypes = <?php echo wp_json_encode($field_types); ?>;
    var mldIsNewForm = <?php echo $is_new ? 'true' : 'false'; ?>;
</script>
