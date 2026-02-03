<?php
/**
 * Related Schools Section
 *
 * Displays other schools in the same district for internal linking and discovery.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

// Get related schools using helper function
$related = function_exists('bmn_get_related_schools')
    ? bmn_get_related_schools($data, 6)
    : array();

// Don't show section if no related schools
if (empty($related)) {
    return;
}

$district_name = $data['district']['name'] ?? 'the District';
$current_level = ucfirst(strtolower($data['level'] ?? ''));
?>

<section class="bne-section bne-related-schools">
    <div class="bne-container">
        <h2 class="bne-section-title">
            Other Schools in <?php echo esc_html($district_name); ?>
        </h2>
        <p class="bne-section-subtitle">
            Explore more <?php echo esc_html($current_level); ?> and other schools in this district
        </p>

        <div class="bne-related-schools__grid">
            <?php foreach ($related as $school) : ?>
                <a href="<?php echo esc_url($school->url); ?>" class="bne-related-school-card">
                    <span class="bne-related-school-card__grade <?php echo esc_attr(bmn_get_grade_class($school->letter_grade)); ?>">
                        <?php echo esc_html($school->letter_grade); ?>
                    </span>
                    <div class="bne-related-school-card__info">
                        <h3 class="bne-related-school-card__name"><?php echo esc_html($school->name); ?></h3>
                        <span class="bne-related-school-card__meta">
                            <?php echo esc_html(ucfirst($school->level ?? 'School')); ?>
                            <?php if (!empty($school->state_rank)) : ?>
                                &bull; #<?php echo esc_html(number_format($school->state_rank)); ?> State
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="bne-related-school-card__arrow">&rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($data['district']['url'])) : ?>
            <div class="bne-related-schools__cta">
                <a href="<?php echo esc_url($data['district']['url']); ?>" class="bne-btn bne-btn--outline">
                    View All <?php echo esc_html($district_name); ?> Schools
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>
