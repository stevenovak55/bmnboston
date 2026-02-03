<?php
/**
 * Homepage About Section
 *
 * Displays the about us section with stats and description.
 * All fields are now editable via Customizer > About Section.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get customizer values
$section_title = get_theme_mod('bne_about_title', 'About Us');
$about_description = get_theme_mod('bne_about_description', 'With decades of combined experience, our team delivers exceptional results for buyers and sellers across Greater Boston.');

// Stat 1
$stat1_value = get_theme_mod('bne_stat1_value', get_theme_mod('bne_sales_amount', '$600+ Million'));
$stat1_label = get_theme_mod('bne_stat1_label', 'in Sales');

// Stat 2 - default to active listings count if empty
$stat2_value = get_theme_mod('bne_stat2_value', '');
if (empty($stat2_value)) {
    $total_listings = BNE_MLS_Helpers::get_total_listing_count();
    $stat2_value = number_format($total_listings) . '+';
}
$stat2_label = get_theme_mod('bne_stat2_label', 'Active Listings');

// Stat 3
$stat3_value = get_theme_mod('bne_stat3_value', 'Top 3');
$stat3_label = get_theme_mod('bne_stat3_label', 'Small Teams');

// Bottom text
$bottom_text = get_theme_mod('bne_about_bottom_text', get_theme_mod('bne_team_ranking', '$95 Million booked and closed for 2025'));

// CTA Button
$cta_text = get_theme_mod('bne_about_cta_text', 'Learn More About Us');
$cta_url = get_theme_mod('bne_about_cta_url', '/about/');
?>

<section class="bne-section bne-section--alt bne-about">
    <div class="bne-container">
        <div class="bne-about__wrapper">
            <div class="bne-about__content">
                <h2 class="bne-section-title bne-about__title"><?php echo esc_html($section_title); ?></h2>
                <p class="bne-about__description"><?php echo wp_kses_post($about_description); ?></p>

                <div class="bne-about__stats">
                    <div class="bne-stat">
                        <span class="bne-stat__value"><?php echo esc_html($stat1_value); ?></span>
                        <span class="bne-stat__label"><?php echo esc_html($stat1_label); ?></span>
                    </div>
                    <div class="bne-stat">
                        <span class="bne-stat__value"><?php echo esc_html($stat2_value); ?></span>
                        <span class="bne-stat__label"><?php echo esc_html($stat2_label); ?></span>
                    </div>
                    <div class="bne-stat">
                        <span class="bne-stat__value"><?php echo esc_html($stat3_value); ?></span>
                        <span class="bne-stat__label"><?php echo esc_html($stat3_label); ?></span>
                    </div>
                </div>

                <?php if (!empty($bottom_text)) : ?>
                    <p class="bne-about__ranking"><?php echo esc_html($bottom_text); ?></p>
                <?php endif; ?>

                <?php if (!empty($cta_text) && !empty($cta_url)) : ?>
                    <div class="bne-about__cta">
                        <a href="<?php echo esc_url(home_url($cta_url)); ?>" class="bne-btn bne-btn--primary">
                            <?php echo esc_html($cta_text); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
