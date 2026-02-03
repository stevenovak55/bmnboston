<?php
/**
 * Landing Page School Info Section
 *
 * Displays school information for school district landing pages
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 *
 * @var array $args Template arguments containing 'data'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$schools = $data['schools'] ?? array();
$district_name = $data['name'] ?? '';

if (empty($schools)) {
    return;
}
?>

<section class="bne-landing-schools">
    <div class="bne-landing-container">
        <h2 class="bne-landing-section-title">
            Schools in <?php echo esc_html($district_name); ?>
        </h2>
        <p class="bne-landing-section-subtitle">
            Explore schools serving this area
        </p>

        <div class="bne-landing-schools__grid">
            <?php foreach ($schools as $school) :
                $school_name = $school['name'] ?? '';
                $school_type = $school['type'] ?? '';
                $school_rating = $school['rating'] ?? 0;
                $school_grades = $school['grades'] ?? '';

                // Rating color class
                $rating_class = 'bne-landing-school-card__rating--average';
                if ($school_rating >= 8) {
                    $rating_class = 'bne-landing-school-card__rating--high';
                } elseif ($school_rating <= 4) {
                    $rating_class = 'bne-landing-school-card__rating--low';
                }
            ?>
                <div class="bne-landing-school-card">
                    <div class="bne-landing-school-card__rating <?php echo esc_attr($rating_class); ?>">
                        <?php echo esc_html($school_rating); ?>/10
                    </div>
                    <div class="bne-landing-school-card__content">
                        <h3 class="bne-landing-school-card__name">
                            <?php echo esc_html($school_name); ?>
                        </h3>
                        <div class="bne-landing-school-card__meta">
                            <span class="bne-landing-school-card__type"><?php echo esc_html($school_type); ?></span>
                            <?php if (!empty($school_grades)) : ?>
                                <span class="bne-landing-school-card__grades">Grades <?php echo esc_html($school_grades); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bne-landing-school-card__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                        </svg>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="bne-landing-schools__disclaimer">
            School ratings are provided for informational purposes only. Please verify with the school district for current information.
        </p>
    </div>
</section>
