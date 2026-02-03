<?php
/**
 * Homepage Section Order - Customizer Control
 *
 * Custom Customizer control for reordering and toggling
 * homepage sections with drag-and-drop interface.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.3
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('WP_Customize_Control')) {

    class BNE_Section_Order_Control extends WP_Customize_Control {

        /**
         * Control type
         *
         * @var string
         */
        public $type = 'section_order';

        /**
         * Enqueue control scripts and styles
         */
        public function enqueue() {
            wp_enqueue_script('jquery-ui-sortable');

            wp_enqueue_script(
                'bne-customizer-section-order',
                get_stylesheet_directory_uri() . '/assets/js/customizer-section-order.js',
                array('jquery', 'jquery-ui-sortable', 'customize-controls'),
                BNE_THEME_VERSION,
                true
            );

            wp_enqueue_style(
                'bne-customizer-section-order',
                get_stylesheet_directory_uri() . '/assets/css/customizer-section-order.css',
                array(),
                BNE_THEME_VERSION
            );
        }

        /**
         * Render control content
         */
        public function render_content() {
            $sections = BNE_Section_Manager::get_sections();
            ?>
            <div class="bne-section-order-control">
                <?php if (!empty($this->label)) : ?>
                    <span class="customize-control-title"><?php echo esc_html($this->label); ?></span>
                <?php endif; ?>

                <?php if (!empty($this->description)) : ?>
                    <span class="description customize-control-description"><?php echo esc_html($this->description); ?></span>
                <?php endif; ?>

                <ul class="bne-customizer-sections" id="bne-customizer-sections">
                    <?php foreach ($sections as $section) : ?>
                        <li class="bne-customizer-section<?php echo !$section['enabled'] ? ' bne-customizer-section--disabled' : ''; ?>"
                            data-section-id="<?php echo esc_attr($section['id']); ?>">
                            <span class="bne-customizer-section__drag dashicons dashicons-menu"></span>
                            <label class="bne-customizer-section__toggle">
                                <input type="checkbox" <?php checked($section['enabled']); ?>>
                                <span class="bne-customizer-section__name">
                                    <?php echo esc_html($section['name']); ?>
                                    <?php if ($section['type'] === 'custom') : ?>
                                        <span class="bne-customizer-section__badge"><?php _e('Custom', 'flavor-flavor-flavor'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <input type="hidden"
                       <?php $this->link(); ?>
                       value="<?php echo esc_attr($this->value()); ?>"
                       id="bne-section-order-value">

                <p class="bne-customizer-sections__link">
                    <a href="<?php echo esc_url(admin_url('themes.php?page=bne-homepage-sections')); ?>" target="_blank">
                        <?php _e('Edit Section Content', 'flavor-flavor-flavor'); ?>
                        <span class="dashicons dashicons-external" style="font-size: 14px; line-height: 1.5;"></span>
                    </a>
                </p>
            </div>
            <?php
        }
    }
}
