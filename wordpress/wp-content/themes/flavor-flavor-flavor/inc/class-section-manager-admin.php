<?php
/**
 * Homepage Section Manager - Admin Page
 *
 * Provides the admin interface for managing homepage sections.
 * Includes drag-and-drop reordering, visibility toggles,
 * custom section creation, and HTML override editing.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Section_Manager_Admin {

    /**
     * Initialize admin functionality
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('wp_ajax_bne_save_sections', array(__CLASS__, 'ajax_save_sections'));
        add_action('wp_ajax_bne_add_custom_section', array(__CLASS__, 'ajax_add_section'));
        add_action('wp_ajax_bne_delete_section', array(__CLASS__, 'ajax_delete_section'));
        add_action('wp_ajax_bne_get_section', array(__CLASS__, 'ajax_get_section'));
    }

    /**
     * Register admin menu page
     */
    public static function register_admin_page() {
        add_theme_page(
            __('Homepage Sections', 'flavor-flavor-flavor'),
            __('Homepage Sections', 'flavor-flavor-flavor'),
            'edit_theme_options',
            'bne-homepage-sections',
            array(__CLASS__, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'appearance_page_bne-homepage-sections') {
            return;
        }

        // jQuery UI Sortable (included in WordPress)
        wp_enqueue_script('jquery-ui-sortable');

        // Admin JavaScript
        wp_enqueue_script(
            'bne-section-manager-admin',
            get_stylesheet_directory_uri() . '/assets/js/section-manager-admin.js',
            array('jquery', 'jquery-ui-sortable'),
            BNE_THEME_VERSION,
            true
        );

        // Localize script
        wp_localize_script('bne-section-manager-admin', 'bneSectionManager', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bne_section_manager'),
            'homeUrl' => home_url('/'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete this custom section? This cannot be undone.', 'flavor-flavor-flavor'),
                'confirmClearOverride' => __('Are you sure you want to remove the custom HTML override and restore the default template?', 'flavor-flavor-flavor'),
                'saving' => __('Saving...', 'flavor-flavor-flavor'),
                'saved' => __('Changes saved!', 'flavor-flavor-flavor'),
                'error' => __('Error saving changes. Please try again.', 'flavor-flavor-flavor'),
                'newSectionName' => __('New Custom Section', 'flavor-flavor-flavor')
            )
        ));

        // Admin CSS
        wp_enqueue_style(
            'bne-section-manager-admin',
            get_stylesheet_directory_uri() . '/assets/css/section-manager-admin.css',
            array(),
            BNE_THEME_VERSION
        );
    }

    /**
     * Render admin page
     */
    public static function render_admin_page() {
        if (!current_user_can('edit_theme_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'flavor-flavor-flavor'));
        }

        $sections = BNE_Section_Manager::get_sections();
        $builtin_defs = BNE_Section_Manager::get_builtin_definitions();
        ?>
        <div class="wrap bne-section-manager">
            <h1 class="wp-heading-inline"><?php _e('Homepage Section Manager', 'flavor-flavor-flavor'); ?></h1>
            <button type="button" class="page-title-action" id="bne-add-section">
                <?php _e('Add Custom Section', 'flavor-flavor-flavor'); ?>
            </button>
            <hr class="wp-header-end">

            <div class="bne-section-manager__intro">
                <p><?php _e('Drag and drop sections to reorder them. Toggle visibility with the checkbox. Click "Edit" to customize section content.', 'flavor-flavor-flavor'); ?></p>
                <p>
                    <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" class="button">
                        <?php _e('Preview Homepage', 'flavor-flavor-flavor'); ?> <span class="dashicons dashicons-external" style="line-height: 1.4;"></span>
                    </a>
                </p>
            </div>

            <div class="bne-section-manager__content">
                <form id="bne-sections-form">
                    <?php wp_nonce_field('bne_section_manager', 'bne_section_nonce'); ?>

                    <ul id="bne-sections-list" class="bne-sections-list">
                        <?php foreach ($sections as $index => $section) : ?>
                            <?php self::render_section_item($section, $builtin_defs); ?>
                        <?php endforeach; ?>
                    </ul>

                    <div class="bne-section-manager__actions">
                        <button type="submit" class="button button-primary button-large" id="bne-save-sections">
                            <?php _e('Save Changes', 'flavor-flavor-flavor'); ?>
                        </button>
                        <span class="bne-save-status"></span>
                    </div>
                </form>
            </div>

            <!-- HTML Editor Modal -->
            <div id="bne-editor-modal" class="bne-modal" style="display: none;">
                <div class="bne-modal__overlay"></div>
                <div class="bne-modal__container">
                    <div class="bne-modal__header">
                        <h2 class="bne-modal__title"><?php _e('Edit Section', 'flavor-flavor-flavor'); ?></h2>
                        <button type="button" class="bne-modal__close" aria-label="<?php esc_attr_e('Close', 'flavor-flavor-flavor'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="bne-modal__body">
                        <input type="hidden" id="bne-editor-section-id" value="">
                        <input type="hidden" id="bne-editor-section-type" value="">

                        <div class="bne-modal__field bne-modal__field--name">
                            <label for="bne-editor-name"><?php _e('Section Name', 'flavor-flavor-flavor'); ?></label>
                            <input type="text" id="bne-editor-name" class="regular-text">
                            <p class="description"><?php _e('Display name shown in the section list.', 'flavor-flavor-flavor'); ?></p>
                        </div>

                        <div class="bne-modal__field">
                            <label for="bne-editor-html"><?php _e('HTML Content', 'flavor-flavor-flavor'); ?></label>
                            <textarea id="bne-editor-html" rows="20" class="large-text code"></textarea>
                            <p class="description">
                                <?php _e('Enter your custom HTML. Use standard HTML tags and CSS classes. The content will be sanitized for security.', 'flavor-flavor-flavor'); ?>
                            </p>
                        </div>

                        <div class="bne-modal__tips">
                            <h4><?php _e('Tips:', 'flavor-flavor-flavor'); ?></h4>
                            <ul>
                                <li><?php _e('Wrap your content in a <code>&lt;section class="bne-section"&gt;</code> tag for consistent styling.', 'flavor-flavor-flavor'); ?></li>
                                <li><?php _e('Use <code>&lt;div class="bne-container"&gt;</code> for centered content.', 'flavor-flavor-flavor'); ?></li>
                                <li><?php _e('Available classes: <code>bne-section-header</code>, <code>bne-section-title</code>, <code>bne-grid--3</code>', 'flavor-flavor-flavor'); ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="bne-modal__footer">
                        <div class="bne-modal__footer-left">
                            <button type="button" class="button" id="bne-editor-clear-override" style="display: none;">
                                <?php _e('Restore Default Template', 'flavor-flavor-flavor'); ?>
                            </button>
                        </div>
                        <div class="bne-modal__footer-right">
                            <button type="button" class="button" id="bne-editor-cancel">
                                <?php _e('Cancel', 'flavor-flavor-flavor'); ?>
                            </button>
                            <button type="button" class="button button-primary" id="bne-editor-save">
                                <?php _e('Save Changes', 'flavor-flavor-flavor'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single section item in the list
     *
     * @param array $section Section data
     * @param array $builtin_defs Built-in section definitions
     */
    private static function render_section_item($section, $builtin_defs) {
        $is_custom = ($section['type'] === 'custom');
        $has_override = !$is_custom && !empty($section['override_html']);
        $description = '';

        if (!$is_custom && isset($builtin_defs[$section['id']])) {
            $description = $builtin_defs[$section['id']]['description'];
        }

        $classes = array('bne-section-item');
        if ($is_custom) {
            $classes[] = 'bne-section-item--custom';
        }
        if ($has_override) {
            $classes[] = 'bne-section-item--override';
        }
        if (!$section['enabled']) {
            $classes[] = 'bne-section-item--disabled';
        }
        ?>
        <li class="<?php echo esc_attr(implode(' ', $classes)); ?>"
            data-section-id="<?php echo esc_attr($section['id']); ?>"
            data-section-type="<?php echo esc_attr($section['type']); ?>">

            <div class="bne-section-item__drag">
                <span class="dashicons dashicons-menu"></span>
            </div>

            <div class="bne-section-item__toggle">
                <label class="bne-toggle">
                    <input type="checkbox"
                           name="sections[<?php echo esc_attr($section['id']); ?>][enabled]"
                           value="1"
                           <?php checked($section['enabled']); ?>>
                    <span class="bne-toggle__slider"></span>
                </label>
            </div>

            <div class="bne-section-item__info">
                <span class="bne-section-item__name">
                    <?php if ($is_custom) : ?>
                        <span class="bne-section-item__badge"><?php _e('Custom', 'flavor-flavor-flavor'); ?></span>
                    <?php endif; ?>
                    <?php echo esc_html($section['name']); ?>
                    <?php if ($has_override) : ?>
                        <span class="bne-section-item__badge bne-section-item__badge--override"><?php _e('Override', 'flavor-flavor-flavor'); ?></span>
                    <?php endif; ?>
                </span>
                <?php if ($description) : ?>
                    <span class="bne-section-item__description"><?php echo esc_html($description); ?></span>
                <?php endif; ?>
            </div>

            <div class="bne-section-item__actions">
                <?php if ($is_custom) : ?>
                    <button type="button" class="button button-small bne-edit-section">
                        <?php _e('Edit', 'flavor-flavor-flavor'); ?>
                    </button>
                    <button type="button" class="button button-small button-link-delete bne-delete-section">
                        <?php _e('Delete', 'flavor-flavor-flavor'); ?>
                    </button>
                <?php else : ?>
                    <button type="button" class="button button-small bne-edit-override">
                        <?php echo $has_override ? __('Edit Override', 'flavor-flavor-flavor') : __('Add Override', 'flavor-flavor-flavor'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </li>
        <?php
    }

    /**
     * AJAX: Save all sections
     */
    public static function ajax_save_sections() {
        check_ajax_referer('bne_section_manager', 'nonce');

        if (!current_user_can('edit_theme_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'flavor-flavor-flavor')));
        }

        $sections_data = isset($_POST['sections']) ? $_POST['sections'] : array();

        if (!is_array($sections_data)) {
            wp_send_json_error(array('message' => __('Invalid data format.', 'flavor-flavor-flavor')));
        }

        // The JavaScript sends sections in order with their data
        $sections = array();
        foreach ($sections_data as $section_input) {
            if (!isset($section_input['id']) || !isset($section_input['type'])) {
                continue;
            }

            $section = array(
                'id' => sanitize_key($section_input['id']),
                'type' => sanitize_key($section_input['type']),
                'name' => isset($section_input['name']) ? sanitize_text_field($section_input['name']) : '',
                'enabled' => !empty($section_input['enabled'])
            );

            if ($section['type'] === 'custom') {
                $section['html'] = isset($section_input['html']) ? wp_kses_post(wp_unslash($section_input['html'])) : '';
            } else {
                $section['override_html'] = isset($section_input['override_html']) ? wp_kses_post(wp_unslash($section_input['override_html'])) : '';
            }

            $sections[] = $section;
        }

        if (BNE_Section_Manager::save_sections($sections)) {
            wp_send_json_success(array('message' => __('Sections saved successfully.', 'flavor-flavor-flavor')));
        } else {
            wp_send_json_error(array('message' => __('Failed to save sections.', 'flavor-flavor-flavor')));
        }
    }

    /**
     * AJAX: Add new custom section
     */
    public static function ajax_add_section() {
        check_ajax_referer('bne_section_manager', 'nonce');

        if (!current_user_can('edit_theme_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'flavor-flavor-flavor')));
        }

        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : __('New Custom Section', 'flavor-flavor-flavor');
        $html = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';

        $new_id = BNE_Section_Manager::add_custom_section($name, $html);

        if ($new_id) {
            wp_send_json_success(array(
                'message' => __('Custom section added.', 'flavor-flavor-flavor'),
                'section_id' => $new_id,
                'section' => BNE_Section_Manager::get_section($new_id)
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to add section.', 'flavor-flavor-flavor')));
        }
    }

    /**
     * AJAX: Delete custom section
     */
    public static function ajax_delete_section() {
        check_ajax_referer('bne_section_manager', 'nonce');

        if (!current_user_can('edit_theme_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'flavor-flavor-flavor')));
        }

        $section_id = isset($_POST['section_id']) ? sanitize_key($_POST['section_id']) : '';

        if (empty($section_id)) {
            wp_send_json_error(array('message' => __('Invalid section ID.', 'flavor-flavor-flavor')));
        }

        if (BNE_Section_Manager::delete_section($section_id)) {
            wp_send_json_success(array('message' => __('Section deleted.', 'flavor-flavor-flavor')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete section. Only custom sections can be deleted.', 'flavor-flavor-flavor')));
        }
    }

    /**
     * AJAX: Get section data
     */
    public static function ajax_get_section() {
        check_ajax_referer('bne_section_manager', 'nonce');

        if (!current_user_can('edit_theme_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'flavor-flavor-flavor')));
        }

        $section_id = isset($_POST['section_id']) ? sanitize_key($_POST['section_id']) : '';

        if (empty($section_id)) {
            wp_send_json_error(array('message' => __('Invalid section ID.', 'flavor-flavor-flavor')));
        }

        $section = BNE_Section_Manager::get_section($section_id);

        if ($section) {
            wp_send_json_success(array('section' => $section));
        } else {
            wp_send_json_error(array('message' => __('Section not found.', 'flavor-flavor-flavor')));
        }
    }
}
