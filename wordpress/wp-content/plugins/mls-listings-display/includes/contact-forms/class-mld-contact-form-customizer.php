<?php
/**
 * Contact Form Customizer Integration
 *
 * Registers WordPress Customizer settings for global form styling.
 *
 * @package MLS_Listings_Display
 * @since 6.21.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Customizer
 *
 * Handles Customizer integration for contact form styling.
 */
class MLD_Contact_Form_Customizer {

    /**
     * Singleton instance
     *
     * @var MLD_Contact_Form_Customizer
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return MLD_Contact_Form_Customizer
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
        add_action('customize_register', [$this, 'register_customizer_settings']);
        add_action('wp_head', [$this, 'output_customizer_css'], 100);
        add_action('customize_preview_init', [$this, 'enqueue_preview_scripts']);
    }

    /**
     * Register Customizer settings
     *
     * @param WP_Customize_Manager $wp_customize
     */
    public function register_customizer_settings($wp_customize) {
        // Add panel
        $wp_customize->add_panel('mld_contact_forms', [
            'title' => __('MLD Contact Forms', 'mls-listings-display'),
            'description' => __('Customize the appearance of contact forms created with MLS Listings Display.', 'mls-listings-display'),
            'priority' => 160,
        ]);

        // Add sections
        $this->add_colors_section($wp_customize);
        $this->add_typography_section($wp_customize);
        $this->add_spacing_section($wp_customize);
        $this->add_buttons_section($wp_customize);
        $this->add_layout_section($wp_customize);
    }

    /**
     * Add Colors section
     */
    private function add_colors_section($wp_customize) {
        $wp_customize->add_section('mld_cf_colors', [
            'title' => __('Colors', 'mls-listings-display'),
            'panel' => 'mld_contact_forms',
            'priority' => 10,
        ]);

        // Background color
        $wp_customize->add_setting('mld_cf_bg_color', [
            'default' => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_bg_color', [
            'label' => __('Background Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Text color
        $wp_customize->add_setting('mld_cf_text_color', [
            'default' => '#1f2937',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_text_color', [
            'label' => __('Text Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Label color
        $wp_customize->add_setting('mld_cf_label_color', [
            'default' => '#374151',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_label_color', [
            'label' => __('Label Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Border color
        $wp_customize->add_setting('mld_cf_border_color', [
            'default' => '#e5e7eb',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_border_color', [
            'label' => __('Border Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Focus color
        $wp_customize->add_setting('mld_cf_focus_color', [
            'default' => '#0891B2',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_focus_color', [
            'label' => __('Focus Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Button background
        $wp_customize->add_setting('mld_cf_button_bg', [
            'default' => '#0891B2',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_button_bg', [
            'label' => __('Button Background', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Button hover background
        $wp_customize->add_setting('mld_cf_button_hover_bg', [
            'default' => '#0E7490',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_button_hover_bg', [
            'label' => __('Button Hover Background', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Button text color
        $wp_customize->add_setting('mld_cf_button_text', [
            'default' => '#ffffff',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_button_text', [
            'label' => __('Button Text Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Error color
        $wp_customize->add_setting('mld_cf_error_color', [
            'default' => '#DC2626',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_error_color', [
            'label' => __('Error Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));

        // Success color
        $wp_customize->add_setting('mld_cf_success_color', [
            'default' => '#10B981',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'mld_cf_success_color', [
            'label' => __('Success Color', 'mls-listings-display'),
            'section' => 'mld_cf_colors',
        ]));
    }

    /**
     * Add Typography section
     */
    private function add_typography_section($wp_customize) {
        $wp_customize->add_section('mld_cf_typography', [
            'title' => __('Typography', 'mls-listings-display'),
            'panel' => 'mld_contact_forms',
            'priority' => 20,
        ]);

        // Font family
        $wp_customize->add_setting('mld_cf_font_family', [
            'default' => 'inherit',
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_font_family', [
            'label' => __('Font Family', 'mls-listings-display'),
            'section' => 'mld_cf_typography',
            'type' => 'select',
            'choices' => [
                'inherit' => __('Inherit from theme', 'mls-listings-display'),
                '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' => __('System UI', 'mls-listings-display'),
                '"Helvetica Neue", Helvetica, Arial, sans-serif' => __('Helvetica', 'mls-listings-display'),
                'Georgia, "Times New Roman", serif' => __('Georgia', 'mls-listings-display'),
                '"Courier New", Courier, monospace' => __('Monospace', 'mls-listings-display'),
            ],
        ]);

        // Label size
        $wp_customize->add_setting('mld_cf_label_size', [
            'default' => 14,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_label_size', [
            'label' => __('Label Font Size (px)', 'mls-listings-display'),
            'section' => 'mld_cf_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 10, 'max' => 24, 'step' => 1],
        ]);

        // Input size
        $wp_customize->add_setting('mld_cf_input_size', [
            'default' => 14,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_input_size', [
            'label' => __('Input Font Size (px)', 'mls-listings-display'),
            'section' => 'mld_cf_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 12, 'max' => 20, 'step' => 1],
        ]);

        // Button size
        $wp_customize->add_setting('mld_cf_button_size', [
            'default' => 16,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_button_size', [
            'label' => __('Button Font Size (px)', 'mls-listings-display'),
            'section' => 'mld_cf_typography',
            'type' => 'number',
            'input_attrs' => ['min' => 12, 'max' => 24, 'step' => 1],
        ]);

        // Label weight
        $wp_customize->add_setting('mld_cf_label_weight', [
            'default' => '600',
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_label_weight', [
            'label' => __('Label Font Weight', 'mls-listings-display'),
            'section' => 'mld_cf_typography',
            'type' => 'select',
            'choices' => [
                '400' => __('Normal (400)', 'mls-listings-display'),
                '500' => __('Medium (500)', 'mls-listings-display'),
                '600' => __('Semi-Bold (600)', 'mls-listings-display'),
                '700' => __('Bold (700)', 'mls-listings-display'),
            ],
        ]);
    }

    /**
     * Add Spacing section
     */
    private function add_spacing_section($wp_customize) {
        $wp_customize->add_section('mld_cf_spacing', [
            'title' => __('Spacing', 'mls-listings-display'),
            'panel' => 'mld_contact_forms',
            'priority' => 30,
        ]);

        // Form padding
        $wp_customize->add_setting('mld_cf_form_padding', [
            'default' => 24,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_form_padding', [
            'label' => __('Form Padding (px)', 'mls-listings-display'),
            'section' => 'mld_cf_spacing',
            'type' => 'number',
            'input_attrs' => ['min' => 0, 'max' => 60, 'step' => 4],
        ]);

        // Field gap
        $wp_customize->add_setting('mld_cf_field_gap', [
            'default' => 16,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_field_gap', [
            'label' => __('Field Spacing (px)', 'mls-listings-display'),
            'section' => 'mld_cf_spacing',
            'type' => 'number',
            'input_attrs' => ['min' => 8, 'max' => 40, 'step' => 4],
        ]);

        // Input padding vertical
        $wp_customize->add_setting('mld_cf_input_padding_v', [
            'default' => 12,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_input_padding_v', [
            'label' => __('Input Vertical Padding (px)', 'mls-listings-display'),
            'section' => 'mld_cf_spacing',
            'type' => 'number',
            'input_attrs' => ['min' => 6, 'max' => 20, 'step' => 2],
        ]);

        // Input padding horizontal
        $wp_customize->add_setting('mld_cf_input_padding_h', [
            'default' => 14,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_input_padding_h', [
            'label' => __('Input Horizontal Padding (px)', 'mls-listings-display'),
            'section' => 'mld_cf_spacing',
            'type' => 'number',
            'input_attrs' => ['min' => 8, 'max' => 24, 'step' => 2],
        ]);
    }

    /**
     * Add Buttons section
     */
    private function add_buttons_section($wp_customize) {
        $wp_customize->add_section('mld_cf_buttons', [
            'title' => __('Buttons', 'mls-listings-display'),
            'panel' => 'mld_contact_forms',
            'priority' => 40,
        ]);

        // Button border radius
        $wp_customize->add_setting('mld_cf_button_radius', [
            'default' => 6,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_button_radius', [
            'label' => __('Button Border Radius (px)', 'mls-listings-display'),
            'section' => 'mld_cf_buttons',
            'type' => 'number',
            'input_attrs' => ['min' => 0, 'max' => 30, 'step' => 2],
        ]);
    }

    /**
     * Add Layout section
     */
    private function add_layout_section($wp_customize) {
        $wp_customize->add_section('mld_cf_layout', [
            'title' => __('Layout & Effects', 'mls-listings-display'),
            'panel' => 'mld_contact_forms',
            'priority' => 50,
        ]);

        // Form border radius
        $wp_customize->add_setting('mld_cf_form_radius', [
            'default' => 8,
            'sanitize_callback' => 'absint',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_form_radius', [
            'label' => __('Form Border Radius (px)', 'mls-listings-display'),
            'section' => 'mld_cf_layout',
            'type' => 'number',
            'input_attrs' => ['min' => 0, 'max' => 30, 'step' => 2],
        ]);

        // Show border
        $wp_customize->add_setting('mld_cf_show_border', [
            'default' => true,
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_show_border', [
            'label' => __('Show Form Border', 'mls-listings-display'),
            'section' => 'mld_cf_layout',
            'type' => 'checkbox',
        ]);

        // Form shadow
        $wp_customize->add_setting('mld_cf_form_shadow', [
            'default' => 'small',
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'postMessage',
        ]);
        $wp_customize->add_control('mld_cf_form_shadow', [
            'label' => __('Form Shadow', 'mls-listings-display'),
            'section' => 'mld_cf_layout',
            'type' => 'select',
            'choices' => [
                'none' => __('None', 'mls-listings-display'),
                'small' => __('Small', 'mls-listings-display'),
                'medium' => __('Medium', 'mls-listings-display'),
                'large' => __('Large', 'mls-listings-display'),
            ],
        ]);
    }

    /**
     * Sanitize checkbox value
     *
     * @param mixed $value
     * @return bool
     */
    public function sanitize_checkbox($value) {
        return (bool) $value;
    }

    /**
     * Output CSS variables in head
     */
    public function output_customizer_css() {
        // Only output if we have contact forms on the page
        // This check is handled by the shortcode/renderer, so we output globally for Customizer preview
        ?>
        <style id="mld-contact-form-customizer-css">
        :root {
            --mld-cf-bg-color: <?php echo esc_attr(get_theme_mod('mld_cf_bg_color', '#ffffff')); ?>;
            --mld-cf-text-color: <?php echo esc_attr(get_theme_mod('mld_cf_text_color', '#1f2937')); ?>;
            --mld-cf-label-color: <?php echo esc_attr(get_theme_mod('mld_cf_label_color', '#374151')); ?>;
            --mld-cf-border-color: <?php echo esc_attr(get_theme_mod('mld_cf_border_color', '#e5e7eb')); ?>;
            --mld-cf-focus-color: <?php echo esc_attr(get_theme_mod('mld_cf_focus_color', '#0891B2')); ?>;
            --mld-cf-button-bg: <?php echo esc_attr(get_theme_mod('mld_cf_button_bg', '#0891B2')); ?>;
            --mld-cf-button-hover-bg: <?php echo esc_attr(get_theme_mod('mld_cf_button_hover_bg', '#0E7490')); ?>;
            --mld-cf-button-text: <?php echo esc_attr(get_theme_mod('mld_cf_button_text', '#ffffff')); ?>;
            --mld-cf-error-color: <?php echo esc_attr(get_theme_mod('mld_cf_error_color', '#DC2626')); ?>;
            --mld-cf-success-color: <?php echo esc_attr(get_theme_mod('mld_cf_success_color', '#10B981')); ?>;
            --mld-cf-font-family: <?php echo esc_attr(get_theme_mod('mld_cf_font_family', 'inherit')); ?>;
            --mld-cf-label-size: <?php echo absint(get_theme_mod('mld_cf_label_size', 14)); ?>px;
            --mld-cf-input-size: <?php echo absint(get_theme_mod('mld_cf_input_size', 14)); ?>px;
            --mld-cf-button-size: <?php echo absint(get_theme_mod('mld_cf_button_size', 16)); ?>px;
            --mld-cf-label-weight: <?php echo esc_attr(get_theme_mod('mld_cf_label_weight', '600')); ?>;
            --mld-cf-form-padding: <?php echo absint(get_theme_mod('mld_cf_form_padding', 24)); ?>px;
            --mld-cf-field-gap: <?php echo absint(get_theme_mod('mld_cf_field_gap', 16)); ?>px;
            --mld-cf-input-padding-v: <?php echo absint(get_theme_mod('mld_cf_input_padding_v', 12)); ?>px;
            --mld-cf-input-padding-h: <?php echo absint(get_theme_mod('mld_cf_input_padding_h', 14)); ?>px;
            --mld-cf-button-radius: <?php echo absint(get_theme_mod('mld_cf_button_radius', 6)); ?>px;
            --mld-cf-form-radius: <?php echo absint(get_theme_mod('mld_cf_form_radius', 8)); ?>px;
        }
        <?php if (get_theme_mod('mld_cf_show_border', true)) : ?>
        .mld-contact-form {
            border: 1px solid var(--mld-cf-border-color);
        }
        <?php endif; ?>
        <?php
        $shadow = get_theme_mod('mld_cf_form_shadow', 'small');
        if ($shadow !== 'none') :
        ?>
        .mld-contact-form {
            <?php if ($shadow === 'small') : ?>
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            <?php elseif ($shadow === 'medium') : ?>
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            <?php elseif ($shadow === 'large') : ?>
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            <?php endif; ?>
        }
        <?php endif; ?>
        </style>
        <?php
    }

    /**
     * Enqueue preview scripts for live customization
     */
    public function enqueue_preview_scripts() {
        wp_enqueue_script(
            'mld-contact-form-customizer-preview',
            MLD_PLUGIN_URL . 'assets/js/mld-contact-form-customizer-preview.js',
            ['jquery', 'customize-preview'],
            MLD_VERSION,
            true
        );
    }
}
