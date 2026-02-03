<?php
/**
 * Homepage Section Manager - Core Class
 *
 * Handles section data storage, retrieval, and manipulation.
 * Provides CRUD operations for homepage sections including
 * built-in sections, custom sections, and section overrides.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Section_Manager {

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('customize_save_after', array(__CLASS__, 'sync_from_customizer'));
    }

    /**
     * Option name for storing section data
     */
    const OPTION_NAME = 'bne_homepage_sections';

    /**
     * Built-in section definitions
     *
     * @var array
     */
    private static $builtin_sections = array(
        'hero' => array(
            'name' => 'Hero Section',
            'description' => 'Agent photo, contact info, and quick search form',
            'template' => 'section-hero.php'
        ),
        'analytics' => array(
            'name' => 'Neighborhood Analytics',
            'description' => 'Market stats for featured neighborhoods',
            'template' => 'section-analytics.php'
        ),
        'market-analytics' => array(
            'name' => 'City Market Insights',
            'description' => 'Detailed city market analytics using MLD shortcodes',
            'template' => 'section-market-analytics.php'
        ),
        'services' => array(
            'name' => 'Our Services',
            'description' => 'Six value proposition cards',
            'template' => 'section-services.php'
        ),
        'neighborhoods' => array(
            'name' => 'Featured Neighborhoods',
            'description' => 'Neighborhood cards with live listing counts',
            'template' => 'section-neighborhoods.php'
        ),
        'listings' => array(
            'name' => 'Newest Listings',
            'description' => 'Grid of 8 most recent listings',
            'template' => 'section-listings.php'
        ),
        'cma-request' => array(
            'name' => 'CMA Request Form',
            'description' => 'Lead capture form for home valuation requests',
            'template' => 'section-cma-request.php'
        ),
        'property-alerts' => array(
            'name' => 'Property Alerts',
            'description' => 'Sign up form for property notifications',
            'template' => 'section-property-alerts.php'
        ),
        'schedule-showing' => array(
            'name' => 'Schedule Tour',
            'description' => 'Tour scheduling form with type selection',
            'template' => 'section-schedule-showing.php'
        ),
        'mortgage-calc' => array(
            'name' => 'Mortgage Calculator',
            'description' => 'Interactive mortgage payment calculator',
            'template' => 'section-mortgage-calc.php'
        ),
        'about' => array(
            'name' => 'About Us',
            'description' => 'Team stats and ranking information',
            'template' => 'section-about.php'
        ),
        'cities' => array(
            'name' => 'Featured Cities',
            'description' => 'City cards with live listing counts',
            'template' => 'section-cities.php'
        ),
        'testimonials' => array(
            'name' => 'Client Testimonials',
            'description' => 'Swiper carousel of client reviews',
            'template' => 'section-testimonials.php'
        ),
        'promo-video' => array(
            'name' => 'Promotional Video',
            'description' => 'Embedded promotional video (YouTube, Vimeo, or self-hosted)',
            'template' => 'section-promo-video.php'
        ),
        'team' => array(
            'name' => 'Our Team',
            'description' => 'Team member cards (up to 6)',
            'template' => 'section-team.php'
        ),
        'blog' => array(
            'name' => 'Latest Blog Posts',
            'description' => 'Three most recent blog posts',
            'template' => 'section-blog.php'
        )
    );

    /**
     * Get built-in section definitions
     *
     * @return array
     */
    public static function get_builtin_definitions() {
        return self::$builtin_sections;
    }

    /**
     * Get all sections (built-in + custom) in current order
     *
     * @return array
     */
    public static function get_sections() {
        $sections = get_option(self::OPTION_NAME, array());

        // If no saved sections, return default configuration
        if (empty($sections)) {
            $sections = self::get_default_sections();
        }

        return $sections;
    }

    /**
     * Get default section configuration
     *
     * @return array
     */
    public static function get_default_sections() {
        $sections = array();

        foreach (self::$builtin_sections as $id => $definition) {
            $sections[] = array(
                'id' => $id,
                'type' => 'builtin',
                'name' => $definition['name'],
                'enabled' => true,
                'override_html' => ''
            );
        }

        return $sections;
    }

    /**
     * Save sections configuration
     *
     * @param array $sections Section data array
     * @return bool Success status
     */
    public static function save_sections($sections) {
        // Validate and sanitize
        $sanitized = self::sanitize_sections($sections);

        if ($sanitized === false) {
            return false;
        }

        return update_option(self::OPTION_NAME, $sanitized);
    }

    /**
     * Sanitize sections data
     *
     * @param array $sections Raw section data
     * @return array|false Sanitized data or false on error
     */
    public static function sanitize_sections($sections) {
        if (!is_array($sections)) {
            return false;
        }

        $sanitized = array();

        foreach ($sections as $section) {
            if (!isset($section['id']) || !isset($section['type'])) {
                continue;
            }

            $clean = array(
                'id' => sanitize_key($section['id']),
                'type' => in_array($section['type'], array('builtin', 'custom')) ? $section['type'] : 'builtin',
                'enabled' => !empty($section['enabled'])
            );

            // Handle name
            if (isset($section['name'])) {
                $clean['name'] = sanitize_text_field($section['name']);
            } elseif ($clean['type'] === 'builtin' && isset(self::$builtin_sections[$clean['id']])) {
                $clean['name'] = self::$builtin_sections[$clean['id']]['name'];
            } else {
                $clean['name'] = 'Unnamed Section';
            }

            // Handle HTML content
            if ($clean['type'] === 'custom') {
                $clean['html'] = isset($section['html']) ? wp_kses_post($section['html']) : '';
            } else {
                $clean['override_html'] = isset($section['override_html']) ? wp_kses_post($section['override_html']) : '';
            }

            $sanitized[] = $clean;
        }

        return $sanitized;
    }

    /**
     * Add a new custom section
     *
     * @param string $name Section name
     * @param string $html Section HTML content
     * @param int $position Position to insert (default: end)
     * @return string|false New section ID or false on error
     */
    public static function add_custom_section($name, $html = '', $position = -1) {
        $sections = self::get_sections();

        $new_id = 'custom_' . time() . '_' . wp_rand(100, 999);

        $new_section = array(
            'id' => $new_id,
            'type' => 'custom',
            'name' => sanitize_text_field($name),
            'enabled' => true,
            'html' => wp_kses_post($html)
        );

        if ($position >= 0 && $position < count($sections)) {
            array_splice($sections, $position, 0, array($new_section));
        } else {
            $sections[] = $new_section;
        }

        if (self::save_sections($sections)) {
            return $new_id;
        }

        return false;
    }

    /**
     * Update a section
     *
     * @param string $section_id Section ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public static function update_section($section_id, $data) {
        $sections = self::get_sections();
        $updated = false;

        foreach ($sections as $index => $section) {
            if ($section['id'] === $section_id) {
                // Update allowed fields
                if (isset($data['name'])) {
                    $sections[$index]['name'] = sanitize_text_field($data['name']);
                }
                if (isset($data['enabled'])) {
                    $sections[$index]['enabled'] = !empty($data['enabled']);
                }
                if ($section['type'] === 'custom' && isset($data['html'])) {
                    $sections[$index]['html'] = wp_kses_post($data['html']);
                }
                if ($section['type'] === 'builtin' && isset($data['override_html'])) {
                    $sections[$index]['override_html'] = wp_kses_post($data['override_html']);
                }

                $updated = true;
                break;
            }
        }

        if ($updated) {
            return self::save_sections($sections);
        }

        return false;
    }

    /**
     * Delete a custom section
     *
     * @param string $section_id Section ID to delete
     * @return bool Success status
     */
    public static function delete_section($section_id) {
        $sections = self::get_sections();
        $new_sections = array();

        foreach ($sections as $section) {
            // Only allow deleting custom sections
            if ($section['id'] === $section_id && $section['type'] === 'custom') {
                continue;
            }
            $new_sections[] = $section;
        }

        // Check if anything was actually removed
        if (count($new_sections) === count($sections)) {
            return false;
        }

        return self::save_sections($new_sections);
    }

    /**
     * Clear override HTML from a built-in section
     *
     * @param string $section_id Section ID
     * @return bool Success status
     */
    public static function clear_override($section_id) {
        return self::update_section($section_id, array('override_html' => ''));
    }

    /**
     * Reorder sections
     *
     * @param array $order Array of section IDs in new order
     * @return bool Success status
     */
    public static function reorder_sections($order) {
        if (!is_array($order)) {
            return false;
        }

        $sections = self::get_sections();
        $sections_by_id = array();

        // Index sections by ID
        foreach ($sections as $section) {
            $sections_by_id[$section['id']] = $section;
        }

        // Rebuild array in new order
        $reordered = array();
        foreach ($order as $id) {
            $id = sanitize_key($id);
            if (isset($sections_by_id[$id])) {
                $reordered[] = $sections_by_id[$id];
                unset($sections_by_id[$id]);
            }
        }

        // Append any sections not in the order array (safety)
        foreach ($sections_by_id as $section) {
            $reordered[] = $section;
        }

        return self::save_sections($reordered);
    }

    /**
     * Get a single section by ID
     *
     * @param string $section_id Section ID
     * @return array|null Section data or null if not found
     */
    public static function get_section($section_id) {
        $sections = self::get_sections();

        foreach ($sections as $section) {
            if ($section['id'] === $section_id) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Check if a section has an override
     *
     * @param string $section_id Section ID
     * @return bool
     */
    public static function has_override($section_id) {
        $section = self::get_section($section_id);

        if ($section && $section['type'] === 'builtin') {
            return !empty($section['override_html']);
        }

        return false;
    }

    /**
     * Get section count
     *
     * @param string $type Optional type filter ('builtin', 'custom', or null for all)
     * @return int
     */
    public static function get_section_count($type = null) {
        $sections = self::get_sections();

        if ($type === null) {
            return count($sections);
        }

        $count = 0;
        foreach ($sections as $section) {
            if ($section['type'] === $type) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Reset to default configuration
     *
     * @return bool Success status
     */
    public static function reset_to_defaults() {
        delete_option(self::OPTION_NAME);
        return true;
    }

    /**
     * Sync section order/visibility from Customizer
     *
     * Called after Customizer saves to sync the theme_mod
     * changes back to the main section data.
     *
     * @param WP_Customize_Manager $wp_customize
     */
    public static function sync_from_customizer($wp_customize) {
        $customizer_value = get_theme_mod('bne_homepage_section_order', '');

        if (empty($customizer_value)) {
            return;
        }

        $customizer_data = json_decode($customizer_value, true);

        if (!is_array($customizer_data)) {
            return;
        }

        // Get current sections
        $sections = self::get_sections();
        $sections_by_id = array();

        // Index current sections by ID
        foreach ($sections as $section) {
            $sections_by_id[$section['id']] = $section;
        }

        // Rebuild sections in new order with updated enabled state
        $updated_sections = array();

        foreach ($customizer_data as $item) {
            if (!isset($item['id'])) {
                continue;
            }

            $id = sanitize_key($item['id']);

            if (isset($sections_by_id[$id])) {
                $section = $sections_by_id[$id];
                $section['enabled'] = !empty($item['enabled']);
                $updated_sections[] = $section;
                unset($sections_by_id[$id]);
            }
        }

        // Append any sections not in customizer data (newly added custom sections)
        foreach ($sections_by_id as $section) {
            $updated_sections[] = $section;
        }

        // Save updated sections
        self::save_sections($updated_sections);

        // Clear the customizer theme_mod since we've synced it
        remove_theme_mod('bne_homepage_section_order');
    }
}
