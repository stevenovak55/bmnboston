<?php
/**
 * Homepage Testimonials Section
 *
 * Displays a Swiper carousel of client testimonials.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get testimonials from CPT
$testimonials = BNE_Custom_Post_Types::get_testimonials(10);
?>

<section class="bne-section bne-section--beige bne-testimonials">
    <div class="bne-container">
        <h2 class="bne-section-title">What Our Clients Say</h2>
        <p class="bne-section-subtitle">Real stories from real clients</p>

        <?php if (!empty($testimonials)) : ?>
            <div class="bne-testimonials-carousel">
                <div class="swiper testimonials-swiper">
                    <div class="swiper-wrapper">
                        <?php foreach ($testimonials as $testimonial) : ?>
                            <div class="swiper-slide">
                                <div class="bne-testimonial-card">
                                    <!-- Rating Stars -->
                                    <?php if ($testimonial['rating']) : ?>
                                        <div class="bne-testimonial-card__rating">
                                            <?php for ($i = 1; $i <= 5; $i++) : ?>
                                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="<?php echo $i <= $testimonial['rating'] ? 'currentColor' : 'none'; ?>" stroke="currentColor" width="20" height="20" class="bne-star <?php echo $i <= $testimonial['rating'] ? 'bne-star--filled' : ''; ?>">
                                                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                                                </svg>
                                            <?php endfor; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Quote -->
                                    <blockquote class="bne-testimonial-card__quote">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="32" height="32" class="bne-testimonial-card__quote-icon">
                                            <path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/>
                                        </svg>
                                        <?php echo wp_kses_post($testimonial['excerpt']); ?>
                                    </blockquote>

                                    <!-- Client Info -->
                                    <div class="bne-testimonial-card__client">
                                        <?php if ($testimonial['photo']) : ?>
                                            <img
                                                src="<?php echo esc_url($testimonial['photo']); ?>"
                                                alt="<?php echo esc_attr($testimonial['client_name']); ?>"
                                                class="bne-testimonial-card__photo"
                                            >
                                        <?php else : ?>
                                            <div class="bne-testimonial-card__photo-placeholder">
                                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="24" height="24">
                                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                                </svg>
                                            </div>
                                        <?php endif; ?>
                                        <div class="bne-testimonial-card__client-info">
                                            <span class="bne-testimonial-card__name"><?php echo esc_html($testimonial['client_name']); ?></span>
                                            <?php if ($testimonial['client_location']) : ?>
                                                <span class="bne-testimonial-card__location"><?php echo esc_html($testimonial['client_location']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Read More Link -->
                                    <a href="<?php echo esc_url(get_permalink($testimonial['id'])); ?>" class="bne-testimonial-card__link">
                                        Continue Reading
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                                            <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Navigation -->
                    <div class="swiper-button-prev"></div>
                    <div class="swiper-button-next"></div>

                    <!-- Pagination -->
                    <div class="swiper-pagination"></div>
                </div>
            </div>
        <?php else : ?>
            <p class="bne-no-content">No testimonials available yet.</p>
        <?php endif; ?>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(home_url('/testimonials/')); ?>" class="bne-btn bne-btn--outline">
                Read All Reviews
            </a>
        </div>
    </div>
</section>
