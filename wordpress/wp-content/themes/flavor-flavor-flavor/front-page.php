<?php
/**
 * Homepage Template
 *
 * This template displays the front page with dynamically ordered sections.
 * Sections can be reordered, enabled/disabled, and customized via
 * Appearance → Homepage Sections or the WordPress Customizer.
 *
 * @package flavor_flavor_flavor
 * @version 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

// Get all homepage sections in configured order
$sections = bne_get_homepage_sections();
?>

<main id="main" class="bne-homepage" role="main">

    <?php
    foreach ($sections as $section) {
        // Skip disabled sections
        if (empty($section['enabled'])) {
            continue;
        }

        // Render section based on type
        if ($section['type'] === 'custom') {
            // Custom HTML section
            if (!empty($section['html'])) {
                echo wp_kses_post($section['html']);
            }
        } elseif (!empty($section['override_html'])) {
            // Built-in section with custom HTML override
            echo wp_kses_post($section['override_html']);
        } else {
            // Built-in section using template file
            bne_get_homepage_section($section['id']);
        }
    }
    ?>

</main>

<?php get_footer(); ?>
