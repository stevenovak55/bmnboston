<?php
/**
 * Homepage Team Section
 *
 * Displays team members in a grid layout.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get team members from CPT
$team_members = BNE_Custom_Post_Types::get_team_members(6);
?>

<section class="bne-section bne-team">
    <div class="bne-container">
        <h2 class="bne-section-title">Meet Our Team</h2>
        <p class="bne-section-subtitle">Experienced professionals dedicated to your success</p>

        <?php if (!empty($team_members)) : ?>
            <div class="bne-grid bne-grid--4 bne-team__grid">
                <?php foreach ($team_members as $member) : ?>
                    <div class="bne-team-card">
                        <div class="bne-team-card__photo-wrapper">
                            <?php if ($member['photo']) : ?>
                                <img
                                    src="<?php echo esc_url($member['photo']); ?>"
                                    alt="<?php echo esc_attr($member['name']); ?>"
                                    class="bne-team-card__photo"
                                    loading="lazy"
                                >
                            <?php else : ?>
                                <div class="bne-team-card__photo-placeholder">
                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="80" height="80">
                                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bne-team-card__content">
                            <h3 class="bne-team-card__name"><?php echo esc_html($member['name']); ?></h3>
                            <?php if ($member['position']) : ?>
                                <p class="bne-team-card__position"><?php echo esc_html($member['position']); ?></p>
                            <?php endif; ?>
                            <?php if ($member['license_number']) : ?>
                                <p class="bne-team-card__license"><?php echo esc_html($member['license_number']); ?></p>
                            <?php endif; ?>

                            <!-- Contact -->
                            <div class="bne-team-card__contact">
                                <?php if ($member['email']) : ?>
                                    <a href="mailto:<?php echo esc_attr($member['email']); ?>" class="bne-team-card__contact-link" title="Email">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="18" height="18">
                                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($member['phone']) : ?>
                                    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $member['phone'])); ?>" class="bne-team-card__contact-link" title="Phone">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="18" height="18">
                                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <!-- Social Links -->
                            <div class="bne-team-card__social">
                                <?php if ($member['instagram']) : ?>
                                    <a href="<?php echo esc_url($member['instagram']); ?>" target="_blank" rel="noopener noreferrer" class="bne-team-card__social-link" title="Instagram">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="18" height="18">
                                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($member['facebook']) : ?>
                                    <a href="<?php echo esc_url($member['facebook']); ?>" target="_blank" rel="noopener noreferrer" class="bne-team-card__social-link" title="Facebook">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="18" height="18">
                                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($member['linkedin']) : ?>
                                    <a href="<?php echo esc_url($member['linkedin']); ?>" target="_blank" rel="noopener noreferrer" class="bne-team-card__social-link" title="LinkedIn">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="18" height="18">
                                            <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="bne-no-content">Team information coming soon.</p>
        <?php endif; ?>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(home_url('/about/')); ?>" class="bne-btn bne-btn--outline">
                Learn More About Our Team
            </a>
        </div>
    </div>
</section>
