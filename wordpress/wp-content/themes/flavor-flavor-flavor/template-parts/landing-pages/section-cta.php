<?php
/**
 * Landing Page CTA Section
 *
 * Call-to-action section for contacting agent
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? 'this area';

// Get agent info from theme customizer
$agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
$agent_phone = get_theme_mod('bne_phone_number', '');
$agent_photo = get_theme_mod('bne_agent_photo', '');
?>

<section class="bne-landing-cta">
    <div class="bne-landing-container">
        <div class="bne-landing-cta__content">
            <div class="bne-landing-cta__text">
                <h2 class="bne-landing-cta__title">
                    Ready to Find Your Home in <?php echo esc_html($name); ?>?
                </h2>
                <p class="bne-landing-cta__description">
                    Get expert guidance on buying or selling in <?php echo esc_html($name); ?>.
                    Schedule a consultation to discuss your real estate goals.
                </p>
                <div class="bne-landing-cta__buttons">
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="bne-landing-button bne-landing-button--primary">
                        Schedule Consultation
                    </a>
                    <?php if (!empty($agent_phone)) : ?>
                        <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9]/', '', $agent_phone)); ?>" class="bne-landing-button bne-landing-button--secondary">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                            Call <?php echo esc_html($agent_phone); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($agent_photo)) : ?>
                <div class="bne-landing-cta__agent">
                    <img src="<?php echo esc_url($agent_photo); ?>" alt="<?php echo esc_attr($agent_name); ?>" class="bne-landing-cta__agent-photo">
                    <div class="bne-landing-cta__agent-info">
                        <span class="bne-landing-cta__agent-name"><?php echo esc_html($agent_name); ?></span>
                        <span class="bne-landing-cta__agent-title">Real Estate Agent</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
