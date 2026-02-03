<?php
/**
 * Header Template
 *
 * @package flavor_flavor_flavor
 * @version 1.1.2
 */

if (!defined('ABSPATH')) {
    exit;
}

$phone_number = get_theme_mod('bne_phone_number', '(617) 955-2224');
$hide_site_title = (bool) get_theme_mod('bne_hide_site_title', 0);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="bne-site">
    <a class="skip-link screen-reader-text" href="#main">
        <?php esc_html_e('Skip to content', 'flavor-flavor-flavor'); ?>
    </a>

    <!-- Mobile Drawer Overlay -->
    <div class="bne-drawer-overlay" aria-hidden="true"></div>

    <!-- Mobile Drawer Navigation -->
    <aside id="mobile-drawer"
           class="bne-drawer"
           role="dialog"
           aria-modal="true"
           aria-label="<?php esc_attr_e('Mobile Navigation', 'flavor-flavor-flavor'); ?>"
           aria-hidden="true"
           tabindex="-1">

        <!-- Drawer Header -->
        <div class="bne-drawer__header">
            <?php if (has_custom_logo()) : ?>
                <div class="bne-drawer__logo">
                    <?php the_custom_logo(); ?>
                </div>
            <?php endif; ?>
            <button class="bne-drawer__close" aria-label="<?php esc_attr_e('Close menu', 'flavor-flavor-flavor'); ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>

        <!-- Drawer Navigation -->
        <nav class="bne-drawer__nav" aria-label="<?php esc_attr_e('Mobile Menu', 'flavor-flavor-flavor'); ?>">
            <?php
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'menu_id'        => 'drawer-menu',
                'menu_class'     => 'bne-drawer__menu',
                'container'      => false,
                'fallback_cb'    => false,
            ));
            ?>
        </nav>

        <!-- Drawer User Menu (Collapsible v1.5.0) -->
        <div class="bne-drawer__user">
            <?php if (is_user_logged_in()) :
                $drawer_user = wp_get_current_user();
                $drawer_avatar_url = bne_get_user_avatar_url($drawer_user->ID, 48);
                $drawer_display_name = $drawer_user->display_name ?: $drawer_user->user_login;
            ?>
                <!-- Collapsible User Toggle -->
                <button type="button" class="bne-drawer__user-toggle" aria-expanded="false" aria-controls="bne-drawer-user-menu">
                    <img src="<?php echo esc_url($drawer_avatar_url); ?>"
                         alt="<?php echo esc_attr($drawer_display_name); ?>"
                         class="bne-drawer__user-avatar">
                    <div class="bne-drawer__user-info">
                        <span class="bne-drawer__user-name"><?php echo esc_html($drawer_display_name); ?></span>
                    </div>
                    <svg class="bne-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                    </svg>
                </button>
                <!-- Collapsible User Menu Items -->
                <nav id="bne-drawer-user-menu" class="bne-drawer__user-nav bne-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'flavor-flavor-flavor'); ?>">
                    <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                        </svg>
                        <span>My Dashboard</span>
                    </a>
                    <a href="<?php echo esc_url(home_url('/my-dashboard/#favorites')); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                        </svg>
                        <span>Favorites</span>
                    </a>
                    <a href="<?php echo esc_url(home_url('/my-dashboard/#searches')); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                        <span>Saved Searches</span>
                    </a>
                    <a href="<?php echo esc_url(get_edit_profile_url()); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        <span>Edit Profile</span>
                    </a>
                    <?php if (current_user_can('manage_options')) : ?>
                    <a href="<?php echo esc_url(admin_url()); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                        </svg>
                        <span>Admin</span>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="bne-drawer__user-item bne-drawer__user-item--logout">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                        </svg>
                        <span>Log Out</span>
                    </a>
                </nav>
            <?php else : ?>
                <!-- Guest User - Collapsible Login/Register -->
                <button type="button" class="bne-drawer__user-toggle bne-drawer__user-toggle--guest" aria-expanded="false" aria-controls="bne-drawer-user-menu">
                    <span class="bne-drawer__user-avatar bne-drawer__user-avatar--guest">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </span>
                    <div class="bne-drawer__user-info">
                        <span class="bne-drawer__user-name">Login / Register</span>
                    </div>
                    <svg class="bne-drawer__user-chevron" viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                        <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                    </svg>
                </button>
                <nav id="bne-drawer-user-menu" class="bne-drawer__user-nav bne-drawer__user-nav--collapsed" aria-label="<?php esc_attr_e('Account Menu', 'flavor-flavor-flavor'); ?>">
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="bne-drawer__user-item bne-drawer__user-item--primary">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/>
                        </svg>
                        <span>Log In</span>
                    </a>
                    <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="bne-drawer__user-item">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
                            <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                        <span>Create Account</span>
                    </a>
                </nav>
            <?php endif; ?>
        </div>
    </aside>

    <header id="masthead" class="bne-header">
        <div class="bne-container">
            <div class="bne-header__wrapper">
                <!-- Logo -->
                <div class="bne-header__logo">
                    <?php if (has_custom_logo()) : ?>
                        <?php the_custom_logo(); ?>
                    <?php elseif (!$hide_site_title) : ?>
                        <a href="<?php echo esc_url(home_url('/')); ?>" class="bne-header__site-title">
                            <?php bloginfo('name'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Navigation -->
                <nav id="site-navigation" class="bne-header__nav bne-header__nav--desktop" aria-label="<?php esc_attr_e('Primary Menu', 'flavor-flavor-flavor'); ?>">
                    <button class="bne-header__menu-toggle" aria-controls="mobile-drawer" aria-expanded="false">
                        <span class="screen-reader-text"><?php esc_html_e('Menu', 'flavor-flavor-flavor'); ?></span>
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                        </svg>
                    </button>

                    <?php
                    wp_nav_menu(array(
                        'theme_location' => 'primary',
                        'menu_id'        => 'primary-menu',
                        'menu_class'     => 'bne-header__menu',
                        'container'      => false,
                        'fallback_cb'    => false,
                    ));
                    ?>
                </nav>

                <!-- Phone -->
                <div class="bne-header__phone">
                    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $phone_number)); ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" aria-hidden="true">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                        <span><?php echo esc_html($phone_number); ?></span>
                    </a>
                </div>

                <!-- User Profile -->
                <div class="bne-header__profile">
                    <?php if (is_user_logged_in()) :
                        $current_user = wp_get_current_user();
                        $avatar_url = bne_get_user_avatar_url($current_user->ID, 40);
                        $display_name = $current_user->display_name ?: $current_user->user_login;
                    ?>
                        <button class="bne-profile__toggle" aria-expanded="false" aria-haspopup="true">
                            <img src="<?php echo esc_url($avatar_url); ?>"
                                 alt="<?php echo esc_attr($display_name); ?>"
                                 class="bne-profile__avatar">
                            <span class="bne-profile__name"><?php echo esc_html($display_name); ?></span>
                            <svg class="bne-profile__chevron" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                                <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                            </svg>
                        </button>
                        <div class="bne-profile__dropdown" aria-hidden="true">
                            <a href="<?php echo esc_url(home_url('/my-dashboard/')); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                                </svg>
                                My Dashboard
                            </a>
                            <a href="<?php echo esc_url(home_url('/my-dashboard/#favorites')); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                </svg>
                                Favorites
                            </a>
                            <a href="<?php echo esc_url(home_url('/my-dashboard/#searches')); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                                </svg>
                                Saved Searches
                            </a>
                            <a href="<?php echo esc_url(get_edit_profile_url()); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                                Edit Profile
                            </a>
                            <?php if (current_user_can('manage_options')) : ?>
                            <a href="<?php echo esc_url(admin_url()); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M19.14 12.94c.04-.31.06-.63.06-.94 0-.31-.02-.63-.06-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                                </svg>
                                Admin
                            </a>
                            <?php endif; ?>
                            <div class="bne-profile__divider"></div>
                            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="bne-profile__item bne-profile__item--logout">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                                </svg>
                                Log Out
                            </a>
                        </div>
                    <?php else : ?>
                        <button class="bne-profile__toggle bne-profile__toggle--guest" aria-expanded="false" aria-haspopup="true">
                            <span class="bne-profile__avatar bne-profile__avatar--guest">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                            </span>
                            <span class="bne-profile__name">Account</span>
                            <svg class="bne-profile__chevron" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                                <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                            </svg>
                        </button>
                        <div class="bne-profile__dropdown" aria-hidden="true">
                            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/>
                                </svg>
                                Log In
                            </a>
                            <a href="<?php echo esc_url(home_url('/signup/')); ?>" class="bne-profile__item">
                                <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                    <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                                Create Account
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
