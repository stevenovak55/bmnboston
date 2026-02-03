<?php
/**
 * Homepage Hero Section
 *
 * Displays the hero section with agent photo, name, license, social links,
 * and quick property search form with dark glass-morphism styling and autocomplete.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.6
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get customizer values
$agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
$agent_title = get_theme_mod('bne_agent_title', 'Licensed Real Estate Salesperson');
$license_number = get_theme_mod('bne_license_number', 'MA: 9517748');
$agent_photo = get_theme_mod('bne_agent_photo', '');
$phone_number = get_theme_mod('bne_phone_number', '617.955.2224');
$agent_address = get_theme_mod('bne_agent_address', '20 Park Plaza, Boston, MA 02116');
$agent_email = get_theme_mod('bne_agent_email', 'mail@steve-novak.com');
$group_name = get_theme_mod('bne_group_name', 'Brody Murphy Novak Group');
$group_url = get_theme_mod('bne_group_url', '#');

// Social links
$instagram = get_theme_mod('bne_social_instagram', '');
$facebook = get_theme_mod('bne_social_facebook', '');
$youtube = get_theme_mod('bne_social_youtube', '');
$linkedin = get_theme_mod('bne_social_linkedin', '');

// Quick search settings
$show_quick_search = get_theme_mod('bne_show_hero_search', true);
$property_types = BNE_MLS_Helpers::get_available_property_types();
$search_page_url = BNE_MLS_Helpers::get_search_page_url();

// CTA button visibility
$show_search_button = get_theme_mod('bne_show_search_button', true);
$show_contact_button = get_theme_mod('bne_show_contact_button', true);
?>

<section class="bne-hero bne-section">
    <div class="bne-container">
        <div class="bne-hero__wrapper">
            <!-- Agent Photo -->
            <div class="bne-hero__photo-wrapper bne-animate">
                <?php if ($agent_photo) : ?>
                    <img
                        src="<?php echo esc_url($agent_photo); ?>"
                        alt="<?php echo esc_attr($agent_name); ?>"
                        class="bne-hero__photo"
                    >
                <?php else : ?>
                    <div class="bne-hero__photo-placeholder">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="120" height="120">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Agent Info -->
            <div class="bne-hero__content">
                <h1 class="bne-hero__name bne-animate"><?php echo esc_html($agent_name); ?></h1>
                <p class="bne-hero__title bne-animate"><?php echo esc_html($agent_title); ?></p>
                <p class="bne-hero__license bne-animate"><?php echo esc_html($license_number); ?></p>

                <?php if ($group_name) : ?>
                    <p class="bne-hero__group bne-animate">
                        Member of <a href="<?php echo esc_url($group_url); ?>"><?php echo esc_html($group_name); ?></a>
                    </p>
                <?php endif; ?>

                <!-- Contact Info List -->
                <div class="bne-hero__contact-list bne-animate">
                    <?php if ($phone_number) : ?>
                        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $phone_number)); ?>" class="bne-hero__contact-item">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                            </svg>
                            <?php echo esc_html($phone_number); ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($agent_address) : ?>
                        <span class="bne-hero__contact-item">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                            </svg>
                            <?php echo esc_html($agent_address); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($agent_email) : ?>
                        <a href="mailto:<?php echo esc_attr($agent_email); ?>" class="bne-hero__contact-item">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                            <?php echo esc_html($agent_email); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Social Links -->
                <div class="bne-hero__social bne-animate">
                    <?php if ($instagram) : ?>
                        <a href="<?php echo esc_url($instagram); ?>" target="_blank" rel="noopener noreferrer" class="bne-hero__social-link" aria-label="Instagram">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                            </svg>
                        </a>
                    <?php endif; ?>

                    <?php if ($facebook) : ?>
                        <a href="<?php echo esc_url($facebook); ?>" target="_blank" rel="noopener noreferrer" class="bne-hero__social-link" aria-label="Facebook">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                            </svg>
                        </a>
                    <?php endif; ?>

                    <?php if ($youtube) : ?>
                        <a href="<?php echo esc_url($youtube); ?>" target="_blank" rel="noopener noreferrer" class="bne-hero__social-link" aria-label="YouTube">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                            </svg>
                        </a>
                    <?php endif; ?>

                    <?php if ($linkedin) : ?>
                        <a href="<?php echo esc_url($linkedin); ?>" target="_blank" rel="noopener noreferrer" class="bne-hero__social-link" aria-label="LinkedIn">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- CTA Buttons -->
                <?php if ($show_search_button || $show_contact_button) : ?>
                <div class="bne-hero__cta bne-animate">
                    <?php if ($show_search_button) : ?>
                    <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--primary bne-btn--lg">
                        Search Properties
                    </a>
                    <?php endif; ?>
                    <?php if ($show_contact_button) : ?>
                    <a href="<?php echo esc_url(BNE_MLS_Helpers::get_contact_page_url()); ?>" class="bne-btn bne-btn--outline bne-btn--lg">
                        Contact Us
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Desktop App Promo (hidden by default, shown on desktop via JS) -->
                <div class="bne-hero__app-promo bne-animate" id="bne-hero-app-promo">
                    <?php
                    $app_store_url = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';
                    $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($app_store_url);
                    ?>
                    <img src="<?php echo esc_url($qr_code_url); ?>" alt="Scan to download app" class="bne-hero__app-qr">
                    <div class="bne-hero__app-text">
                        <span class="bne-hero__app-label">Get the iOS App</span>
                        <a href="<?php echo esc_url($app_store_url); ?>" target="_blank" rel="noopener" class="bne-hero__app-badge">
                            <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/white/en-us?size=250x83" alt="Download on the App Store">
                        </a>
                    </div>
                </div>
                <style>
                .bne-hero__app-promo {
                    display: none;
                    align-items: center;
                    justify-content: center;
                    gap: 12px;
                    margin-top: 20px;
                    padding: 12px 16px;
                    background: rgba(128, 128, 128, 0.15);
                    backdrop-filter: blur(10px);
                    -webkit-backdrop-filter: blur(10px);
                    border: 1px solid rgba(255, 255, 255, 0.2);
                    border-radius: 12px;
                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
                    width: 100%;
                    max-width: 240px; /* Match Search Properties button width */
                    box-sizing: border-box;
                }
                .bne-hero__app-promo.bne-promo-visible {
                    display: flex;
                }
                .bne-hero__app-qr {
                    width: 60px;
                    height: 60px;
                    border-radius: 6px;
                    background: #fff;
                    flex-shrink: 0;
                }
                .bne-hero__app-text {
                    display: flex;
                    flex-direction: column;
                    gap: 4px;
                }
                .bne-hero__app-label {
                    font-size: 12px;
                    color: rgba(255, 255, 255, 0.9);
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .bne-hero__app-badge img {
                    height: 28px;
                    width: auto;
                    display: block;
                }
                .bne-hero__app-badge:hover {
                    opacity: 0.8;
                }
                @media (max-width: 600px) {
                    .bne-hero__app-promo {
                        display: none !important;
                    }
                }
                </style>
                <script>
                (function() {
                    var promo = document.getElementById('bne-hero-app-promo');
                    if (!promo) return;
                    var ua = navigator.userAgent;
                    var isIOS = /iPhone|iPad|iPod/.test(ua);
                    var isAndroid = /Android/.test(ua);
                    // Show on desktop only (not mobile)
                    if (!isIOS && !isAndroid) {
                        promo.classList.add('bne-promo-visible');
                    }
                })();
                </script>
            </div>
        </div>

        <?php if ($show_quick_search) : ?>
            <!-- Quick Property Search Form -->
            <form id="bne-hero-search-form" class="bne-hero-search bne-glass--strong bne-animate" data-search-url="<?php echo esc_url($search_page_url); ?>">
                <div class="bne-hero-search__inner">
                    <h2 class="bne-hero-search__title">Find Your Dream Home</h2>

                    <div class="bne-hero-search__fields">
                        <!-- Location Input with Autocomplete -->
                        <div class="bne-hero-search__field bne-hero-search__field--location">
                            <label for="hero-search-location" class="screen-reader-text">
                                <?php esc_html_e('Location', 'flavor-flavor-flavor'); ?>
                            </label>
                            <div class="bne-hero-search__input-wrapper">
                                <input
                                    type="text"
                                    id="hero-search-location"
                                    name="location"
                                    class="bne-hero-search__input bne-hero-search__input--no-icon"
                                    placeholder="<?php esc_attr_e('City, ZIP, Neighborhood, or Address', 'flavor-flavor-flavor'); ?>"
                                    autocomplete="off"
                                    data-autocomplete="true"
                                >
                                <!-- Autocomplete Dropdown -->
                                <div class="bne-hero-search__autocomplete" id="hero-search-autocomplete" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Property Type Select (Dynamic) -->
                        <div class="bne-hero-search__field bne-hero-search__field--type">
                            <label for="hero-search-type" class="screen-reader-text">
                                <?php esc_html_e('Property Type', 'flavor-flavor-flavor'); ?>
                            </label>
                            <div class="bne-hero-search__select-wrapper">
                                <svg class="bne-hero-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                                </svg>
                                <select id="hero-search-type" name="property_type" class="bne-hero-search__select">
                                    <option value=""><?php esc_html_e('All Types', 'flavor-flavor-flavor'); ?></option>
                                    <?php foreach ($property_types as $type) : ?>
                                        <option value="<?php echo esc_attr($type['value']); ?>">
                                            <?php echo esc_html($type['label']); ?>
                                            <?php if ($type['count'] !== null) : ?>
                                                (<?php echo number_format($type['count']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Price Range Select -->
                        <div class="bne-hero-search__field bne-hero-search__field--price">
                            <label for="hero-search-price" class="screen-reader-text">
                                <?php esc_html_e('Price Range', 'flavor-flavor-flavor'); ?>
                            </label>
                            <div class="bne-hero-search__select-wrapper">
                                <svg class="bne-hero-search__icon" aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                                </svg>
                                <select id="hero-search-price" name="price_range" class="bne-hero-search__select">
                                    <option value=""><?php esc_html_e('Any Price', 'flavor-flavor-flavor'); ?></option>
                                    <option value="0-300000"><?php esc_html_e('Under $300K', 'flavor-flavor-flavor'); ?></option>
                                    <option value="300000-500000"><?php esc_html_e('$300K - $500K', 'flavor-flavor-flavor'); ?></option>
                                    <option value="500000-750000"><?php esc_html_e('$500K - $750K', 'flavor-flavor-flavor'); ?></option>
                                    <option value="750000-1000000"><?php esc_html_e('$750K - $1M', 'flavor-flavor-flavor'); ?></option>
                                    <option value="1000000-2000000"><?php esc_html_e('$1M - $2M', 'flavor-flavor-flavor'); ?></option>
                                    <option value="2000000-"><?php esc_html_e('$2M+', 'flavor-flavor-flavor'); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Search Button -->
                        <div class="bne-hero-search__field bne-hero-search__field--submit">
                            <button type="submit" class="bne-btn bne-btn--primary bne-btn--lg bne-hero-search__btn">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="20" height="20">
                                    <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                                </svg>
                                <span><?php esc_html_e('Search', 'flavor-flavor-flavor'); ?></span>
                            </button>
                        </div>
                    </div>

                    <p class="bne-hero-search__hint">
                        <?php esc_html_e('Or browse', 'flavor-flavor-flavor'); ?>
                        <a href="<?php echo esc_url($search_page_url); ?>"><?php esc_html_e('all listings', 'flavor-flavor-flavor'); ?></a>
                    </p>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
