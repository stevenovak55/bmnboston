<?php
/**
 * Theme Setup Class
 *
 * @package flavor_flavor_flavor
 * @version 1.2.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Theme_Setup {

    /**
     * Initialize theme setup
     */
    public static function init() {
        add_action('after_setup_theme', array(__CLASS__, 'setup_theme'));
        add_action('widgets_init', array(__CLASS__, 'register_sidebars'));
        add_action('customize_register', array(__CLASS__, 'customize_register'));
    }

    /**
     * Setup theme features
     */
    public static function setup_theme() {
        // Add theme support
        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
        add_theme_support('custom-logo', array(
            'height'      => 100,
            'width'       => 300,
            'flex-height' => true,
            'flex-width'  => true,
        ));
        add_theme_support('html5', array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
        ));
        add_theme_support('customize-selective-refresh-widgets');
        add_theme_support('responsive-embeds');

        // Register navigation menus
        register_nav_menus(array(
            'primary'   => __('Primary Menu', 'flavor-flavor-flavor'),
            'footer'    => __('Footer Menu', 'flavor-flavor-flavor'),
            'resources' => __('Resources Menu', 'flavor-flavor-flavor'),
        ));

        // Add custom image sizes
        add_image_size('bne-listing-card', 400, 280, true);
        add_image_size('bne-team-photo', 300, 300, true);
        add_image_size('bne-hero', 800, 1000, false);
        add_image_size('bne-neighborhood', 600, 400, true);
    }

    /**
     * Register sidebars/widget areas
     */
    public static function register_sidebars() {
        register_sidebar(array(
            'name'          => __('Footer Contact Area', 'flavor-flavor-flavor'),
            'id'            => 'footer-contact',
            'description'   => __('Add contact form widget here', 'flavor-flavor-flavor'),
            'before_widget' => '<div id="%1$s" class="widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ));
    }

    /**
     * Register customizer settings
     */
    public static function customize_register($wp_customize) {
        // =========================================
        // HEADER SETTINGS
        // =========================================
        $wp_customize->add_section('bne_header_section', array(
            'title'       => __('Header Settings', 'flavor-flavor-flavor'),
            'priority'    => 25,
            'capability'  => 'edit_theme_options',
        ));

        // Hide Site Title
        $wp_customize->add_setting('bne_hide_site_title', array(
            'default'           => 0,
            'type'              => 'theme_mod',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'absint',
            'transport'         => 'refresh',
        ));
        $wp_customize->add_control('bne_hide_site_title', array(
            'label'       => __('Hide Site Title', 'flavor-flavor-flavor'),
            'description' => __('Hide the site title text in header when no logo is set', 'flavor-flavor-flavor'),
            'section'     => 'bne_header_section',
            'type'        => 'checkbox',
            'settings'    => 'bne_hide_site_title',
        ));

        // =========================================
        // HERO SECTION
        // =========================================
        $wp_customize->add_section('bne_hero_section', array(
            'title'    => __('Homepage Hero', 'flavor-flavor-flavor'),
            'priority' => 30,
        ));

        // Agent Name
        $wp_customize->add_setting('bne_agent_name', array(
            'default'           => 'Steven Novak',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_agent_name', array(
            'label'   => __('Agent Name', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'text',
        ));

        // Agent Title
        $wp_customize->add_setting('bne_agent_title', array(
            'default'           => 'Licensed Real Estate Salesperson',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_agent_title', array(
            'label'   => __('Agent Title', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'text',
        ));

        // License Number
        $wp_customize->add_setting('bne_license_number', array(
            'default'           => 'MA: 9517748',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_license_number', array(
            'label'   => __('License Number', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'text',
        ));

        // Agent Photo
        $wp_customize->add_setting('bne_agent_photo', array(
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'bne_agent_photo', array(
            'label'   => __('Agent Photo', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
        )));

        // Phone Number
        $wp_customize->add_setting('bne_phone_number', array(
            'default'           => '(617) 955-2224',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_phone_number', array(
            'label'   => __('Phone Number', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'tel',
        ));

        // Agent Email
        $wp_customize->add_setting('bne_agent_email', array(
            'default'           => 'mail@steve-novak.com',
            'sanitize_callback' => 'sanitize_email',
        ));
        $wp_customize->add_control('bne_agent_email', array(
            'label'   => __('Agent Email', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'email',
        ));

        // Agent Address
        $wp_customize->add_setting('bne_agent_address', array(
            'default'           => '20 Park Plaza, Boston, MA 02116',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_agent_address', array(
            'label'   => __('Office Address', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'text',
        ));

        // Group/Team Name
        $wp_customize->add_setting('bne_group_name', array(
            'default'           => 'Brody Murphy Novak Group',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_group_name', array(
            'label'       => __('Team/Group Name', 'flavor-flavor-flavor'),
            'description' => __('Shows as "Member of [Group Name]"', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'text',
        ));

        // Group/Team URL
        $wp_customize->add_setting('bne_group_url', array(
            'default'           => '#',
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control('bne_group_url', array(
            'label'   => __('Team/Group URL', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'url',
        ));

        // Show Hero Search Form
        $wp_customize->add_setting('bne_show_hero_search', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_hero_search', array(
            'label'       => __('Show Quick Search Form', 'flavor-flavor-flavor'),
            'description' => __('Display the property search form in the hero section', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'checkbox',
        ));

        // Show Search Properties Button
        $wp_customize->add_setting('bne_show_search_button', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_search_button', array(
            'label'       => __('Show Search Properties Button', 'flavor-flavor-flavor'),
            'description' => __('Display the "Search Properties" CTA button', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'checkbox',
        ));

        // Show Contact Us Button
        $wp_customize->add_setting('bne_show_contact_button', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_contact_button', array(
            'label'       => __('Show Contact Us Button', 'flavor-flavor-flavor'),
            'description' => __('Display the "Contact Us" CTA button', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'checkbox',
        ));

        // =========================================
        // HERO VISUAL SETTINGS (NEW - v1.2.8)
        // Added to existing bne_hero_section
        // =========================================

        // --- Hero Section Layout ---
        $wp_customize->add_setting('bne_hero_min_height', array(
            'default'           => 100,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_min_height', array(
            'label'       => __('Hero Minimum Height (vh)', 'flavor-flavor-flavor'),
            'description' => __('Minimum height as viewport height percentage (0-150)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 150,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_padding_top', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_padding_top', array(
            'label'       => __('Hero Top Padding (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 200,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_padding_bottom', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_padding_bottom', array(
            'label'       => __('Hero Bottom Padding (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 200,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_margin_top', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_margin_top', array(
            'label'       => __('Hero Top Margin (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 200,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_margin_bottom', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_margin_bottom', array(
            'label'       => __('Hero Bottom Margin (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 200,
                'step' => 5,
            ),
        ));

        // --- Hero Image Settings ---
        $wp_customize->add_setting('bne_hero_image_height_desktop', array(
            'default'           => 100,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_height_desktop', array(
            'label'       => __('Image Height Desktop (vh)', 'flavor-flavor-flavor'),
            'description' => __('Max height as viewport percentage on desktop', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 30,
                'max'  => 150,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_image_height_mobile', array(
            'default'           => 40,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_height_mobile', array(
            'label'       => __('Image Height Mobile (vh)', 'flavor-flavor-flavor'),
            'description' => __('Min height as viewport percentage on mobile', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 20,
                'max'  => 80,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_image_max_width', array(
            'default'           => 50,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_max_width', array(
            'label'       => __('Image Max Width (%)', 'flavor-flavor-flavor'),
            'description' => __('Maximum width percentage on desktop (30-100%)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 30,
                'max'  => 100,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_image_object_fit', array(
            'default'           => 'contain',
            'sanitize_callback' => 'sanitize_text_field',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_object_fit', array(
            'label'   => __('Image Fit Mode', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'select',
            'choices' => array(
                'contain' => __('Contain (show full image)', 'flavor-flavor-flavor'),
                'cover'   => __('Cover (fill space, may crop)', 'flavor-flavor-flavor'),
            ),
        ));

        // Image Padding
        $wp_customize->add_setting('bne_hero_image_padding', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_padding', array(
            'label'       => __('Image Padding (px)', 'flavor-flavor-flavor'),
            'description' => __('Padding around the image (0-100px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 100,
                'step' => 5,
            ),
        ));

        // Image Margin
        $wp_customize->add_setting('bne_hero_image_margin', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_margin', array(
            'label'       => __('Image Margin (px)', 'flavor-flavor-flavor'),
            'description' => __('Margin around the image (0-100px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 100,
                'step' => 5,
            ),
        ));

        // Image Offset (break out of container)
        $wp_customize->add_setting('bne_hero_image_offset', array(
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_image_offset', array(
            'label'       => __('Image Offset (px)', 'flavor-flavor-flavor'),
            'description' => __('Extend image beyond container (0-300px). Pushes image left on desktop.', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 300,
                'step' => 10,
            ),
        ));

        // --- Hero Typography Settings ---
        $wp_customize->add_setting('bne_hero_name_font_size', array(
            'default'           => 52,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_name_font_size', array(
            'label'       => __('Name Font Size (px)', 'flavor-flavor-flavor'),
            'description' => __('Agent name font size on desktop (24-80px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 24,
                'max'  => 80,
                'step' => 2,
            ),
        ));

        $wp_customize->add_setting('bne_hero_name_font_weight', array(
            'default'           => '400',
            'sanitize_callback' => 'sanitize_text_field',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_name_font_weight', array(
            'label'   => __('Name Font Weight', 'flavor-flavor-flavor'),
            'section' => 'bne_hero_section',
            'type'    => 'select',
            'choices' => array(
                '300' => __('Light (300)', 'flavor-flavor-flavor'),
                '400' => __('Regular (400)', 'flavor-flavor-flavor'),
                '500' => __('Medium (500)', 'flavor-flavor-flavor'),
                '600' => __('Semi-Bold (600)', 'flavor-flavor-flavor'),
                '700' => __('Bold (700)', 'flavor-flavor-flavor'),
            ),
        ));

        $wp_customize->add_setting('bne_hero_name_letter_spacing', array(
            'default'           => 8,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_name_letter_spacing', array(
            'label'       => __('Name Letter Spacing (0.01em)', 'flavor-flavor-flavor'),
            'description' => __('Letter spacing multiplier (0-20 = 0em to 0.2em)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 20,
                'step' => 1,
            ),
        ));

        $wp_customize->add_setting('bne_hero_title_font_size', array(
            'default'           => 14,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_title_font_size', array(
            'label'       => __('Title Font Size (px)', 'flavor-flavor-flavor'),
            'description' => __('Agent title/role font size (10-24px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 10,
                'max'  => 24,
                'step' => 1,
            ),
        ));

        $wp_customize->add_setting('bne_hero_license_font_size', array(
            'default'           => 14,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_license_font_size', array(
            'label'       => __('License Font Size (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 10,
                'max'  => 20,
                'step' => 1,
            ),
        ));

        $wp_customize->add_setting('bne_hero_contact_font_size', array(
            'default'           => 14,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_contact_font_size', array(
            'label'       => __('Contact Info Font Size (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 10,
                'max'  => 20,
                'step' => 1,
            ),
        ));

        // --- Hero Content Area Settings ---
        $wp_customize->add_setting('bne_hero_content_max_width', array(
            'default'           => 500,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_content_max_width', array(
            'label'       => __('Content Max Width (px)', 'flavor-flavor-flavor'),
            'description' => __('Maximum width of the text content area (300-1024px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 300,
                'max'  => 1024,
                'step' => 10,
            ),
        ));

        $wp_customize->add_setting('bne_hero_content_padding', array(
            'default'           => 32,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_content_padding', array(
            'label'       => __('Content Padding (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 80,
                'step' => 4,
            ),
        ));

        // --- Hero Search Form Settings ---
        $wp_customize->add_setting('bne_hero_search_max_width', array(
            'default'           => 100,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_search_max_width', array(
            'label'       => __('Search Form Max Width (%)', 'flavor-flavor-flavor'),
            'description' => __('Maximum width as percentage of container', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 50,
                'max'  => 100,
                'step' => 5,
            ),
        ));

        $wp_customize->add_setting('bne_hero_search_padding', array(
            'default'           => 32,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_search_padding', array(
            'label'       => __('Search Form Padding (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 8,
                'max'  => 64,
                'step' => 4,
            ),
        ));

        $wp_customize->add_setting('bne_hero_search_border_radius', array(
            'default'           => 12,
            'sanitize_callback' => 'absint',
            'transport'         => 'postMessage',
        ));
        $wp_customize->add_control('bne_hero_search_border_radius', array(
            'label'       => __('Search Form Border Radius (px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_hero_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 32,
                'step' => 2,
            ),
        ));

        // =========================================
        // HOMEPAGE LAYOUT (Section Order)
        // =========================================
        $wp_customize->add_section('bne_homepage_layout_section', array(
            'title'       => __('Homepage Layout', 'flavor-flavor-flavor'),
            'description' => __('Reorder and toggle homepage sections. For full editing including custom sections, use Appearance → Homepage Sections.', 'flavor-flavor-flavor'),
            'priority'    => 31,
        ));

        // Section Order Control
        $wp_customize->add_setting('bne_homepage_section_order', array(
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'transport'         => 'refresh',
        ));

        if (class_exists('BNE_Section_Order_Control')) {
            $wp_customize->add_control(new BNE_Section_Order_Control($wp_customize, 'bne_homepage_section_order', array(
                'label'       => __('Section Order', 'flavor-flavor-flavor'),
                'description' => __('Drag to reorder. Check to enable. For custom HTML sections, use the admin page.', 'flavor-flavor-flavor'),
                'section'     => 'bne_homepage_layout_section',
            )));
        }

        // =========================================
        // NEIGHBORHOOD ANALYTICS SECTION
        // =========================================
        $wp_customize->add_section('bne_analytics_section', array(
            'title'       => __('Neighborhood Analytics', 'flavor-flavor-flavor'),
            'description' => __('Configure the neighborhood analytics section on the homepage.', 'flavor-flavor-flavor'),
            'priority'    => 32,
        ));

        // Show Analytics Section
        $wp_customize->add_setting('bne_show_analytics_section', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_analytics_section', array(
            'label'       => __('Show Analytics Section', 'flavor-flavor-flavor'),
            'description' => __('Display the neighborhood analytics section on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_analytics_section',
            'type'        => 'checkbox',
        ));

        // Analytics Section Title
        $wp_customize->add_setting('bne_analytics_title', array(
            'default'           => 'Explore Boston Neighborhoods',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_analytics_title', array(
            'label'   => __('Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_analytics_section',
            'type'    => 'text',
        ));

        // Analytics Section Subtitle
        $wp_customize->add_setting('bne_analytics_subtitle', array(
            'default'           => 'Discover market insights and find your perfect neighborhood',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_analytics_subtitle', array(
            'label'   => __('Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_analytics_section',
            'type'    => 'text',
        ));

        // Analytics Neighborhoods List
        $wp_customize->add_setting('bne_analytics_neighborhoods', array(
            'default'           => 'Back Bay, South End, Beacon Hill, Jamaica Plain, Charlestown, Cambridge',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_analytics_neighborhoods', array(
            'label'       => __('Featured Neighborhoods', 'flavor-flavor-flavor'),
            'description' => __('Comma-separated list of neighborhoods to display with analytics', 'flavor-flavor-flavor'),
            'section'     => 'bne_analytics_section',
            'type'        => 'textarea',
        ));

        // =========================================
        // MARKET ANALYTICS SECTION (MLD Shortcodes)
        // =========================================
        $wp_customize->add_section('bne_market_analytics_section', array(
            'title'       => __('Market Analytics', 'flavor-flavor-flavor'),
            'description' => __('Configure market analytics with city-based insights using MLD shortcodes.', 'flavor-flavor-flavor'),
            'priority'    => 33,
        ));

        // Show Market Analytics Section
        $wp_customize->add_setting('bne_show_market_analytics', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_market_analytics', array(
            'label'       => __('Show Market Analytics Section', 'flavor-flavor-flavor'),
            'description' => __('Display detailed city market analytics on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_market_analytics_section',
            'type'        => 'checkbox',
        ));

        // Market Analytics Section Title
        $wp_customize->add_setting('bne_market_analytics_title', array(
            'default'           => 'City Market Insights',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_market_analytics_title', array(
            'label'   => __('Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_market_analytics_section',
            'type'    => 'text',
        ));

        // Market Analytics Section Subtitle
        $wp_customize->add_setting('bne_market_analytics_subtitle', array(
            'default'           => 'Detailed market intelligence for your target areas',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_market_analytics_subtitle', array(
            'label'   => __('Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_market_analytics_section',
            'type'    => 'text',
        ));

        // Featured Cities for Analytics (comma-separated)
        $wp_customize->add_setting('bne_market_analytics_cities', array(
            'default'           => 'Reading, Wakefield, Stoneham, Melrose, Winchester',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_market_analytics_cities', array(
            'label'       => __('Featured Cities', 'flavor-flavor-flavor'),
            'description' => __('Comma-separated list of cities for market analytics (max 5 recommended)', 'flavor-flavor-flavor'),
            'section'     => 'bne_market_analytics_section',
            'type'        => 'textarea',
        ));

        // Default State
        $wp_customize->add_setting('bne_market_analytics_state', array(
            'default'           => 'MA',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_market_analytics_state', array(
            'label'       => __('Default State', 'flavor-flavor-flavor'),
            'description' => __('State abbreviation (e.g., MA, NY, CA)', 'flavor-flavor-flavor'),
            'section'     => 'bne_market_analytics_section',
            'type'        => 'text',
        ));

        // Analysis Period (months)
        $wp_customize->add_setting('bne_market_analytics_months', array(
            'default'           => '12',
            'sanitize_callback' => 'absint',
        ));
        $wp_customize->add_control('bne_market_analytics_months', array(
            'label'       => __('Analysis Period (months)', 'flavor-flavor-flavor'),
            'description' => __('How many months of data to analyze', 'flavor-flavor-flavor'),
            'section'     => 'bne_market_analytics_section',
            'type'        => 'select',
            'choices'     => array(
                '3'  => __('3 months', 'flavor-flavor-flavor'),
                '6'  => __('6 months', 'flavor-flavor-flavor'),
                '12' => __('12 months', 'flavor-flavor-flavor'),
                '24' => __('24 months', 'flavor-flavor-flavor'),
            ),
        ));

        // Show Market Heat Index
        $wp_customize->add_setting('bne_show_market_heat', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_market_heat', array(
            'label'   => __('Show Market Heat Index', 'flavor-flavor-flavor'),
            'section' => 'bne_market_analytics_section',
            'type'    => 'checkbox',
        ));

        // Show Feature Premiums
        $wp_customize->add_setting('bne_show_feature_premiums', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_feature_premiums', array(
            'label'   => __('Show Feature Value Premiums', 'flavor-flavor-flavor'),
            'section' => 'bne_market_analytics_section',
            'type'    => 'checkbox',
        ));

        // Show Price by Bedrooms
        $wp_customize->add_setting('bne_show_price_bedrooms', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_price_bedrooms', array(
            'label'   => __('Show Price by Bedrooms', 'flavor-flavor-flavor'),
            'section' => 'bne_market_analytics_section',
            'type'    => 'checkbox',
        ));

        // Show Top Agents
        $wp_customize->add_setting('bne_show_top_agents', array(
            'default'           => false,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_top_agents', array(
            'label'       => __('Show Top Agents', 'flavor-flavor-flavor'),
            'description' => __('Display top performing agents in each city', 'flavor-flavor-flavor'),
            'section'     => 'bne_market_analytics_section',
            'type'        => 'checkbox',
        ));

        // =========================================
        // SOCIAL MEDIA
        // =========================================
        $wp_customize->add_section('bne_social_section', array(
            'title'    => __('Social Media Links', 'flavor-flavor-flavor'),
            'priority' => 35,
        ));

        $social_networks = array(
            'instagram' => 'Instagram URL',
            'facebook'  => 'Facebook URL',
            'youtube'   => 'YouTube URL',
            'linkedin'  => 'LinkedIn URL',
        );

        foreach ($social_networks as $network => $label) {
            $wp_customize->add_setting("bne_social_{$network}", array(
                'sanitize_callback' => 'esc_url_raw',
            ));
            $wp_customize->add_control("bne_social_{$network}", array(
                'label'   => __($label, 'flavor-flavor-flavor'),
                'section' => 'bne_social_section',
                'type'    => 'url',
            ));
        }

        // =========================================
        // ABOUT SECTION
        // =========================================
        $wp_customize->add_section('bne_about_section', array(
            'title'    => __('About Section', 'flavor-flavor-flavor'),
            'priority' => 40,
        ));

        // Section Title
        $wp_customize->add_setting('bne_about_title', array(
            'default'           => 'About Us',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_about_title', array(
            'label'   => __('Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // About Description
        $wp_customize->add_setting('bne_about_description', array(
            'default'           => 'With decades of combined experience, our team delivers exceptional results for buyers and sellers across Greater Boston.',
            'sanitize_callback' => 'wp_kses_post',
        ));
        $wp_customize->add_control('bne_about_description', array(
            'label'   => __('About Description', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'textarea',
        ));

        // --- Stat 1 ---
        $wp_customize->add_setting('bne_stat1_value', array(
            'default'           => '$600+ Million',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat1_value', array(
            'label'   => __('Stat 1 - Value', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_stat1_label', array(
            'default'           => 'in Sales',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat1_label', array(
            'label'   => __('Stat 1 - Label', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // --- Stat 2 ---
        $wp_customize->add_setting('bne_stat2_value', array(
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat2_value', array(
            'label'       => __('Stat 2 - Value', 'flavor-flavor-flavor'),
            'description' => __('Leave empty to auto-populate from active listings count', 'flavor-flavor-flavor'),
            'section'     => 'bne_about_section',
            'type'        => 'text',
        ));

        $wp_customize->add_setting('bne_stat2_label', array(
            'default'           => 'Active Listings',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat2_label', array(
            'label'   => __('Stat 2 - Label', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // --- Stat 3 ---
        $wp_customize->add_setting('bne_stat3_value', array(
            'default'           => 'Top 3',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat3_value', array(
            'label'   => __('Stat 3 - Value', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_stat3_label', array(
            'default'           => 'Small Teams',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_stat3_label', array(
            'label'   => __('Stat 3 - Label', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // Team Ranking / Bottom Text
        $wp_customize->add_setting('bne_about_bottom_text', array(
            'default'           => '$95 Million booked and closed for 2025',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_about_bottom_text', array(
            'label'   => __('Bottom Text (below stats)', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // CTA Button Text
        $wp_customize->add_setting('bne_about_cta_text', array(
            'default'           => 'Learn More About Us',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_about_cta_text', array(
            'label'   => __('Button Text', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'text',
        ));

        // CTA Button URL
        $wp_customize->add_setting('bne_about_cta_url', array(
            'default'           => '/about/',
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control('bne_about_cta_url', array(
            'label'   => __('Button URL', 'flavor-flavor-flavor'),
            'section' => 'bne_about_section',
            'type'    => 'url',
        ));

        // Legacy setting for backwards compatibility
        $wp_customize->add_setting('bne_sales_amount', array(
            'default'           => '$600+ Million',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_setting('bne_team_ranking', array(
            'default'           => '$95 Million booked and closed for 2025',
            'sanitize_callback' => 'sanitize_text_field',
        ));

        // =========================================
        // FEATURED LOCATIONS
        // =========================================
        $wp_customize->add_section('bne_locations_section', array(
            'title'    => __('Featured Locations', 'flavor-flavor-flavor'),
            'priority' => 45,
        ));

        // Featured Neighborhoods (comma-separated)
        $wp_customize->add_setting('bne_featured_neighborhoods', array(
            'default'           => 'Seaport District,South Boston,South End,Back Bay,East Boston,Jamaica Plain,Beacon Hill',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_featured_neighborhoods', array(
            'label'       => __('Featured Neighborhoods', 'flavor-flavor-flavor'),
            'description' => __('Comma-separated list of neighborhoods', 'flavor-flavor-flavor'),
            'section'     => 'bne_locations_section',
            'type'        => 'textarea',
        ));

        // Featured Cities (comma-separated)
        $wp_customize->add_setting('bne_featured_cities', array(
            'default'           => 'Boston,Newton,Cambridge,Somerville,Arlington,Needham',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_featured_cities', array(
            'label'       => __('Featured Cities', 'flavor-flavor-flavor'),
            'description' => __('Comma-separated list of cities', 'flavor-flavor-flavor'),
            'section'     => 'bne_locations_section',
            'type'        => 'textarea',
        ));

        // =========================================
        // BROKERAGE BRANDING
        // =========================================
        $wp_customize->add_section('bne_brokerage_section', array(
            'title'    => __('Brokerage Branding', 'flavor-flavor-flavor'),
            'priority' => 50,
        ));

        // Brokerage Logo
        $wp_customize->add_setting('bne_brokerage_logo', array(
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'bne_brokerage_logo', array(
            'label'   => __('Brokerage Logo (Douglas Elliman)', 'flavor-flavor-flavor'),
            'section' => 'bne_brokerage_section',
        )));

        // Brokerage Name
        $wp_customize->add_setting('bne_brokerage_name', array(
            'default'           => 'Douglas Elliman Real Estate',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_brokerage_name', array(
            'label'   => __('Brokerage Name', 'flavor-flavor-flavor'),
            'section' => 'bne_brokerage_section',
            'type'    => 'text',
        ));

        // =========================================
        // LEAD GENERATION TOOLS
        // =========================================
        $wp_customize->add_section('bne_lead_tools_section', array(
            'title'       => __('Lead Generation Tools', 'flavor-flavor-flavor'),
            'description' => __('Configure CMA requests, property alerts, tour scheduling, and mortgage calculator.', 'flavor-flavor-flavor'),
            'priority'    => 55,
        ));

        // --- CMA Request Form ---
        $wp_customize->add_setting('bne_show_cma_section', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_cma_section', array(
            'label'       => __('Show CMA Request Section', 'flavor-flavor-flavor'),
            'description' => __('Display CMA request form on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'checkbox',
        ));

        $wp_customize->add_setting('bne_cma_title', array(
            'default'           => 'Get Your Free Home Valuation',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_cma_title', array(
            'label'   => __('CMA Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_cma_subtitle', array(
            'default'           => 'Request a Comparative Market Analysis to discover what your home is worth in today\'s market.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_cma_subtitle', array(
            'label'   => __('CMA Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'textarea',
        ));

        // --- Property Alerts ---
        $wp_customize->add_setting('bne_show_alerts_section', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_alerts_section', array(
            'label'       => __('Show Property Alerts Section', 'flavor-flavor-flavor'),
            'description' => __('Display property alerts signup on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'checkbox',
        ));

        $wp_customize->add_setting('bne_alerts_title', array(
            'default'           => 'Never Miss a New Listing',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_alerts_title', array(
            'label'   => __('Alerts Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_alerts_subtitle', array(
            'default'           => 'Get instant notifications when properties matching your criteria hit the market.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_alerts_subtitle', array(
            'label'   => __('Alerts Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'textarea',
        ));

        $wp_customize->add_setting('bne_alerts_cities', array(
            'default'           => 'Boston, Cambridge, Somerville, Newton, Brookline',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_alerts_cities', array(
            'label'       => __('Default Cities for Alerts', 'flavor-flavor-flavor'),
            'description' => __('Comma-separated list of suggested cities', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'textarea',
        ));

        // --- Schedule Showing ---
        $wp_customize->add_setting('bne_show_tour_section', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_tour_section', array(
            'label'       => __('Show Schedule Tour Section', 'flavor-flavor-flavor'),
            'description' => __('Display tour scheduling form on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'checkbox',
        ));

        $wp_customize->add_setting('bne_tour_title', array(
            'default'           => 'Schedule a Property Tour',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_tour_title', array(
            'label'   => __('Tour Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_tour_subtitle', array(
            'default'           => 'Choose your preferred tour type and let us show you your next home.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_tour_subtitle', array(
            'label'   => __('Tour Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'textarea',
        ));

        // --- Mortgage Calculator ---
        $wp_customize->add_setting('bne_show_mortgage_section', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        $wp_customize->add_control('bne_show_mortgage_section', array(
            'label'       => __('Show Mortgage Calculator', 'flavor-flavor-flavor'),
            'description' => __('Display mortgage calculator on homepage', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'checkbox',
        ));

        $wp_customize->add_setting('bne_mortgage_title', array(
            'default'           => 'Mortgage Calculator',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_mortgage_title', array(
            'label'   => __('Mortgage Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'text',
        ));

        $wp_customize->add_setting('bne_mortgage_subtitle', array(
            'default'           => 'Estimate your monthly payment and see how much home you can afford.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_mortgage_subtitle', array(
            'label'   => __('Mortgage Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_lead_tools_section',
            'type'    => 'textarea',
        ));

        $wp_customize->add_setting('bne_default_mortgage_rate', array(
            'default'           => 6.5,
            'sanitize_callback' => 'floatval',
        ));
        $wp_customize->add_control('bne_default_mortgage_rate', array(
            'label'       => __('Default Interest Rate (%)', 'flavor-flavor-flavor'),
            'description' => __('Default interest rate for calculator', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'number',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 20,
                'step' => 0.125,
            ),
        ));

        $wp_customize->add_setting('bne_default_property_tax_rate', array(
            'default'           => 1.2,
            'sanitize_callback' => 'floatval',
        ));
        $wp_customize->add_control('bne_default_property_tax_rate', array(
            'label'       => __('Default Property Tax Rate (%)', 'flavor-flavor-flavor'),
            'description' => __('Default annual property tax rate', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'number',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 5,
                'step' => 0.1,
            ),
        ));

        $wp_customize->add_setting('bne_default_home_insurance', array(
            'default'           => 1200,
            'sanitize_callback' => 'absint',
        ));
        $wp_customize->add_control('bne_default_home_insurance', array(
            'label'       => __('Default Annual Insurance ($)', 'flavor-flavor-flavor'),
            'description' => __('Default annual home insurance amount', 'flavor-flavor-flavor'),
            'section'     => 'bne_lead_tools_section',
            'type'        => 'number',
            'input_attrs' => array(
                'min'  => 0,
                'max'  => 10000,
                'step' => 100,
            ),
        ));

        // =========================================
        // PROMOTIONAL VIDEO SECTION
        // =========================================
        $wp_customize->add_section('bne_promo_video_section', array(
            'title'       => __('Promotional Video', 'flavor-flavor-flavor'),
            'description' => __('Configure the promotional video section. Section is hidden when no URL is provided.', 'flavor-flavor-flavor'),
            'priority'    => 56,
        ));

        // Video URL (required for section to display)
        $wp_customize->add_setting('bne_video_url', array(
            'default'           => '',
            'sanitize_callback' => 'esc_url_raw',
        ));
        $wp_customize->add_control('bne_video_url', array(
            'label'       => __('Video URL', 'flavor-flavor-flavor'),
            'description' => __('YouTube, Vimeo, or direct .mp4 URL. Section hidden when empty.', 'flavor-flavor-flavor'),
            'section'     => 'bne_promo_video_section',
            'type'        => 'url',
        ));

        // Video Title
        $wp_customize->add_setting('bne_video_title', array(
            'default'           => 'Discover Our Story',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_video_title', array(
            'label'   => __('Section Title', 'flavor-flavor-flavor'),
            'section' => 'bne_promo_video_section',
            'type'    => 'text',
        ));

        // Video Subtitle
        $wp_customize->add_setting('bne_video_subtitle', array(
            'default'           => 'Watch our video to learn more about our approach to real estate.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_video_subtitle', array(
            'label'   => __('Section Subtitle', 'flavor-flavor-flavor'),
            'section' => 'bne_promo_video_section',
            'type'    => 'textarea',
        ));

        // Aspect Ratio
        $wp_customize->add_setting('bne_video_aspect_ratio', array(
            'default'           => '16:9',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_video_aspect_ratio', array(
            'label'   => __('Aspect Ratio', 'flavor-flavor-flavor'),
            'section' => 'bne_promo_video_section',
            'type'    => 'select',
            'choices' => array(
                '16:9' => __('16:9 (Widescreen)', 'flavor-flavor-flavor'),
                '4:3'  => __('4:3 (Standard)', 'flavor-flavor-flavor'),
                '1:1'  => __('1:1 (Square)', 'flavor-flavor-flavor'),
                '9:16' => __('9:16 (Vertical/TikTok)', 'flavor-flavor-flavor'),
            ),
        ));

        // Max Width
        $wp_customize->add_setting('bne_video_max_width', array(
            'default'           => 900,
            'sanitize_callback' => 'absint',
        ));
        $wp_customize->add_control('bne_video_max_width', array(
            'label'       => __('Max Container Width (px)', 'flavor-flavor-flavor'),
            'description' => __('Maximum width of the video container (600-1200px)', 'flavor-flavor-flavor'),
            'section'     => 'bne_promo_video_section',
            'type'        => 'range',
            'input_attrs' => array(
                'min'  => 600,
                'max'  => 1200,
                'step' => 50,
            ),
        ));

        // Background Style
        $wp_customize->add_setting('bne_video_bg_style', array(
            'default'           => 'white',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control('bne_video_bg_style', array(
            'label'   => __('Background Style', 'flavor-flavor-flavor'),
            'section' => 'bne_promo_video_section',
            'type'    => 'select',
            'choices' => array(
                'white'    => __('White', 'flavor-flavor-flavor'),
                'beige'    => __('Beige', 'flavor-flavor-flavor'),
                'dark'     => __('Dark', 'flavor-flavor-flavor'),
                'gradient' => __('Gradient', 'flavor-flavor-flavor'),
            ),
        ));
    }
}
