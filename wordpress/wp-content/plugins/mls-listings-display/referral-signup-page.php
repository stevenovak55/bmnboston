<?php
/**
 * Template: Referral Signup Page
 *
 * Displays agent introduction and registration form for referral signups.
 * Accessed via /signup?ref=CODE
 *
 * @package MLS_Listings_Display
 * @since 6.52.0
 *
 * Variables available:
 * - $referral_code (string) - The referral code from URL
 * - $agent_data (array|null) - Agent information if code is valid
 */

if (!defined('ABSPATH')) {
    exit;
}

$site_name = get_bloginfo('name');
$has_agent = !empty($agent_data);
?>

<div class="mld-signup-page">
    <div class="mld-signup-container">

        <?php if ($has_agent): ?>
        <!-- Agent Introduction -->
        <div class="mld-signup-agent">
            <div class="mld-signup-agent__photo-wrapper">
                <?php if (!empty($agent_data['photo_url'])): ?>
                    <img src="<?php echo esc_url($agent_data['photo_url']); ?>"
                         alt="<?php echo esc_attr($agent_data['name']); ?>"
                         class="mld-signup-agent__photo">
                <?php else: ?>
                    <div class="mld-signup-agent__avatar">
                        <?php echo esc_html(strtoupper(substr($agent_data['name'], 0, 1))); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mld-signup-agent__intro">
                <p class="mld-signup-agent__invited">You've been invited by</p>
                <h1 class="mld-signup-agent__name"><?php echo esc_html($agent_data['name']); ?></h1>
                <?php if (!empty($agent_data['office_name'])): ?>
                    <p class="mld-signup-agent__office"><?php echo esc_html($agent_data['office_name']); ?></p>
                <?php endif; ?>
            </div>

            <p class="mld-signup-agent__message">
                Create your free account to start your home search with personalized guidance from
                <?php echo esc_html($agent_data['name']); ?>.
            </p>

            <div class="mld-signup-agent__benefits">
                <div class="mld-signup-benefit">
                    <span class="mld-signup-benefit__icon">&#128269;</span>
                    <span class="mld-signup-benefit__text">Save searches & get instant alerts</span>
                </div>
                <div class="mld-signup-benefit">
                    <span class="mld-signup-benefit__icon">&#128151;</span>
                    <span class="mld-signup-benefit__text">Save your favorite properties</span>
                </div>
                <div class="mld-signup-benefit">
                    <span class="mld-signup-benefit__icon">&#128197;</span>
                    <span class="mld-signup-benefit__text">Schedule tours with one click</span>
                </div>
                <div class="mld-signup-benefit">
                    <span class="mld-signup-benefit__icon">&#128172;</span>
                    <span class="mld-signup-benefit__text">Direct messaging with your agent</span>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Generic Signup (no valid referral code) -->
        <div class="mld-signup-header">
            <h1 class="mld-signup-header__title">Create Your Account</h1>
            <p class="mld-signup-header__subtitle">
                Join <?php echo esc_html($site_name); ?> to save searches, track favorites, and find your perfect home.
            </p>
        </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <div class="mld-signup-form-wrapper">
            <form id="mld-signup-form" class="mld-signup-form" method="post">

                <div class="mld-signup-form__row mld-signup-form__row--half">
                    <div class="mld-signup-form__field">
                        <label for="signup-first-name" class="mld-signup-form__label">
                            First Name <span class="required">*</span>
                        </label>
                        <input type="text"
                               id="signup-first-name"
                               name="first_name"
                               class="mld-signup-form__input"
                               required
                               autocomplete="given-name">
                    </div>
                    <div class="mld-signup-form__field">
                        <label for="signup-last-name" class="mld-signup-form__label">
                            Last Name
                        </label>
                        <input type="text"
                               id="signup-last-name"
                               name="last_name"
                               class="mld-signup-form__input"
                               autocomplete="family-name">
                    </div>
                </div>

                <div class="mld-signup-form__field">
                    <label for="signup-email" class="mld-signup-form__label">
                        Email Address <span class="required">*</span>
                    </label>
                    <input type="email"
                           id="signup-email"
                           name="email"
                           class="mld-signup-form__input"
                           required
                           autocomplete="email">
                </div>

                <div class="mld-signup-form__field">
                    <label for="signup-phone" class="mld-signup-form__label">
                        Phone Number
                    </label>
                    <input type="tel"
                           id="signup-phone"
                           name="phone"
                           class="mld-signup-form__input"
                           autocomplete="tel">
                </div>

                <div class="mld-signup-form__row mld-signup-form__row--half">
                    <div class="mld-signup-form__field">
                        <label for="signup-password" class="mld-signup-form__label">
                            Password <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="signup-password"
                               name="password"
                               class="mld-signup-form__input"
                               required
                               minlength="6"
                               autocomplete="new-password">
                    </div>
                    <div class="mld-signup-form__field">
                        <label for="signup-password-confirm" class="mld-signup-form__label">
                            Confirm Password <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="signup-password-confirm"
                               name="password_confirm"
                               class="mld-signup-form__input"
                               required
                               minlength="6"
                               autocomplete="new-password">
                    </div>
                </div>

                <?php if (!$has_agent): ?>
                <!-- Referral Code field (only shown when no agent pre-linked via URL) -->
                <div class="mld-signup-form__field">
                    <label for="signup-referral-code" class="mld-signup-form__label">
                        Referral Code
                    </label>
                    <input type="text"
                           id="signup-referral-code"
                           name="referral_code"
                           class="mld-signup-form__input"
                           placeholder="Enter referral code if you have one"
                           value="<?php echo esc_attr($referral_code); ?>"
                           style="text-transform: uppercase;">
                    <p class="mld-signup-form__hint">If you were referred by an agent, enter their code here</p>
                </div>
                <?php else: ?>
                <!-- Hidden referral code when agent is pre-linked via URL -->
                <input type="hidden" name="referral_code" value="<?php echo esc_attr($referral_code); ?>">
                <?php endif; ?>

                <div class="mld-signup-form__error" id="signup-error" style="display: none;"></div>

                <button type="submit" class="mld-signup-form__submit" id="signup-submit">
                    <span class="mld-signup-form__submit-text">Create My Account</span>
                    <span class="mld-signup-form__submit-loading" style="display: none;">
                        <svg class="mld-spinner" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4"></circle>
                        </svg>
                        Creating account...
                    </span>
                </button>

                <p class="mld-signup-form__terms">
                    By creating an account, you agree to our
                    <a href="<?php echo esc_url(home_url('/terms/')); ?>">Terms of Service</a> and
                    <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a>.
                </p>
            </form>

            <div class="mld-signup-divider">
                <span>Already have an account?</span>
            </div>

            <a href="<?php echo esc_url(wp_login_url(home_url('/my-dashboard/'))); ?>" class="mld-signup-form__login-link">
                Sign In
            </a>
        </div>

        <!-- iOS App Download Section -->
        <div class="mld-signup-app">
            <div class="mld-signup-app__divider">
                <span>Or get our mobile app</span>
            </div>

            <div class="mld-signup-app__content">
                <div class="mld-signup-app__icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="48" height="48">
                        <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
                    </svg>
                </div>

                <div class="mld-signup-app__text">
                    <h3 class="mld-signup-app__title">Download the BMN Boston App</h3>
                    <p class="mld-signup-app__description">
                        Search properties, get instant notifications, and connect with
                        <?php echo $has_agent ? esc_html($agent_data['name']) : 'your agent'; ?>
                        - all from your iPhone.
                    </p>
                </div>

                <?php
                // App Store URL
                $app_store_url = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';

                // Build deep link URL with referral code for the app
                $deep_link_url = 'bmnboston://signup';
                if (!empty($referral_code)) {
                    $deep_link_url .= '?ref=' . urlencode($referral_code);
                }
                ?>

                <div class="mld-signup-app__buttons">
                    <a href="<?php echo esc_url($app_store_url); ?>"
                       class="mld-signup-app__store-badge"
                       target="_blank"
                       rel="noopener noreferrer">
                        <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83"
                             alt="Download on the App Store"
                             class="mld-signup-app__store-image">
                    </a>
                </div>

                <?php if (!empty($referral_code)): ?>
                <p class="mld-signup-app__note">
                    <strong>Your referral code:</strong> <?php echo esc_html($referral_code); ?><br>
                    <span class="mld-signup-app__note-hint">Enter this code when you sign up in the app to connect with <?php echo $has_agent ? esc_html($agent_data['name']) : 'your agent'; ?>.</span>
                </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($has_agent && !empty($agent_data['phone'])): ?>
        <!-- Agent Contact Info -->
        <div class="mld-signup-agent-contact">
            <p class="mld-signup-agent-contact__text">
                Questions? Contact <?php echo esc_html($agent_data['name']); ?> directly:
            </p>
            <div class="mld-signup-agent-contact__methods">
                <?php if (!empty($agent_data['phone'])): ?>
                    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $agent_data['phone'])); ?>"
                       class="mld-signup-agent-contact__link">
                        <span class="mld-signup-agent-contact__icon">&#128222;</span>
                        <?php echo esc_html($agent_data['phone']); ?>
                    </a>
                <?php endif; ?>
                <?php if (!empty($agent_data['email'])): ?>
                    <a href="mailto:<?php echo esc_attr($agent_data['email']); ?>"
                       class="mld-signup-agent-contact__link">
                        <span class="mld-signup-agent-contact__icon">&#9993;</span>
                        <?php echo esc_html($agent_data['email']); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
