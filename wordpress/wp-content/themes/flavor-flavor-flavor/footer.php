<?php
/**
 * Footer Template
 *
 * @package flavor_flavor_flavor
 * @version 1.2.4
 */

if (!defined('ABSPATH')) {
    exit;
}

$phone_number = get_theme_mod('bne_phone_number', '(617) 955-2224');
$agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
$brokerage_name = get_theme_mod('bne_brokerage_name', 'Douglas Elliman Real Estate');
$brokerage_logo = get_theme_mod('bne_brokerage_logo', '');

// Social links
$instagram = get_theme_mod('bne_social_instagram', '');
$facebook = get_theme_mod('bne_social_facebook', '');
$youtube = get_theme_mod('bne_social_youtube', '');
$linkedin = get_theme_mod('bne_social_linkedin', '');

// Get featured cities
$cities = BNE_MLS_Helpers::get_featured_cities();
?>

    <footer id="colophon" class="bne-footer">
        <div class="bne-container">
            <div class="bne-footer__grid">
                <!-- Contact Info -->
                <div class="bne-footer__section">
                    <h3 class="bne-footer__title">Contact Us</h3>
                    <p class="bne-footer__agent"><?php echo esc_html($agent_name); ?></p>
                    <div class="bne-footer__brokerage-wrapper">
                        <?php if ($brokerage_logo) : ?>
                            <img src="<?php echo esc_url($brokerage_logo); ?>" alt="<?php echo esc_attr($brokerage_name); ?>" class="bne-footer__brokerage-logo">
                        <?php endif; ?>
                        <p class="bne-footer__brokerage"><?php echo esc_html($brokerage_name); ?></p>
                    </div>
                    <p class="bne-footer__phone">
                        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $phone_number)); ?>">
                            <?php echo esc_html($phone_number); ?>
                        </a>
                    </p>

                    <!-- Social Links -->
                    <div class="bne-footer__social">
                        <?php if ($instagram) : ?>
                            <a href="<?php echo esc_url($instagram); ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($facebook) : ?>
                            <a href="<?php echo esc_url($facebook); ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($youtube) : ?>
                            <a href="<?php echo esc_url($youtube); ?>" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                        <?php if ($linkedin) : ?>
                            <a href="<?php echo esc_url($linkedin); ?>" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="bne-footer__section">
                    <h3 class="bne-footer__title">Quick Links</h3>
                    <?php
                    wp_nav_menu(array(
                        'theme_location' => 'footer',
                        'menu_class'     => 'bne-footer__menu',
                        'container'      => false,
                        'fallback_cb'    => false,
                        'depth'          => 1,
                    ));
                    ?>
                </div>

                <!-- Search by City -->
                <div class="bne-footer__section">
                    <h3 class="bne-footer__title">Search by City</h3>
                    <ul class="bne-footer__cities">
                        <?php foreach ($cities as $city) : ?>
                            <li>
                                <a href="<?php echo esc_url($city['url']); ?>">
                                    <?php echo esc_html($city['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Contact Form Widget Area -->
                <div class="bne-footer__section bne-footer__section--form">
                    <h3 class="bne-footer__title">Get in Touch</h3>
                    <?php if (is_active_sidebar('footer-contact')) : ?>
                        <?php dynamic_sidebar('footer-contact'); ?>
                    <?php else : ?>
                        <p>Have questions? We're here to help!</p>
                        <a href="<?php echo esc_url(BNE_MLS_Helpers::get_contact_page_url()); ?>" class="bne-btn bne-btn--primary">
                            Contact Us
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="bne-footer__bottom">
                <p class="bne-footer__copyright">
                    &copy; <?php echo date('Y'); ?> <?php echo esc_html($agent_name); ?>. All rights reserved.
                </p>
                <p class="bne-footer__disclaimer">
                    Equal Housing Opportunity. Information deemed reliable but not guaranteed.
                </p>
            </div>
        </div>
    </footer>

</div><!-- #page -->

<?php wp_footer(); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var header = document.querySelector('.bne-header');
    var menuToggle = document.querySelector('.bne-header__menu-toggle');
    var menu = document.querySelector('.bne-header__menu');

    // Scroll effect - shrink header on scroll
    if (header) {
        var lastScroll = 0;
        window.addEventListener('scroll', function() {
            var currentScroll = window.pageYOffset;

            if (currentScroll > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }

            lastScroll = currentScroll;
        }, { passive: true });
    }

    // Mobile menu toggle
    if (menuToggle && menu) {
        menuToggle.addEventListener('click', function() {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !expanded);
            menu.classList.toggle('bne-header__menu--open');

            // Animate hamburger icon
            this.classList.toggle('is-active');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!menu.contains(e.target) && !menuToggle.contains(e.target)) {
                menu.classList.remove('bne-header__menu--open');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuToggle.classList.remove('is-active');
            }
        });
    }
});
</script>

</body>
</html>
