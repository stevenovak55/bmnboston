<?php
/**
 * School Navigation Section (back to district, related schools)
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$district = $data['district'] ?? array();
?>

<section class="bne-school-navigation">
    <div class="bne-container">
        <div class="bne-school-navigation__content">
            <?php if (!empty($district['name'])) : ?>
                <div class="bne-school-navigation__district">
                    <h3 class="bne-school-navigation__title">Part of <?php echo esc_html($district['name']); ?></h3>
                    <p class="bne-school-navigation__description">
                        View all schools in this district, including elementary, middle, and high schools.
                    </p>
                    <a href="<?php echo esc_url($district['url']); ?>" class="bne-btn bne-btn--primary">
                        View District Details
                    </a>
                </div>
            <?php endif; ?>

            <div class="bne-school-navigation__browse">
                <h3 class="bne-school-navigation__title">Explore More Schools</h3>
                <p class="bne-school-navigation__description">
                    Compare schools across Massachusetts by grade, location, and performance.
                </p>
                <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--text">
                    Browse All Districts
                </a>
            </div>
        </div>
    </div>
</section>
