<?php
/**
 * Homepage Services Section
 *
 * Displays the 6 value proposition cards.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

$services = array(
    array(
        'title'       => 'Sell Your Home',
        'description' => 'Get top dollar for your property with our proven marketing strategy and expert negotiation skills.',
        'icon'        => 'home-sale',
        'link'        => '/sellers/',
    ),
    array(
        'title'       => 'Buy a Home',
        'description' => 'Find your perfect home with personalized search and expert guidance through every step.',
        'icon'        => 'home-buy',
        'link'        => '/buyers/',
    ),
    array(
        'title'       => 'Rent a Home',
        'description' => 'Discover quality rental properties in the best neighborhoods across Greater Boston.',
        'icon'        => 'home-rent',
        'link'        => '/rentals/',
    ),
    array(
        'title'       => 'Strategy Consultation',
        'description' => 'Get personalized advice on timing, pricing, and market conditions for your real estate goals.',
        'icon'        => 'strategy',
        'link'        => '/consultation/',
    ),
    array(
        'title'       => 'Free Evaluation',
        'description' => 'Know your home\'s worth with a complimentary market analysis from our expert team.',
        'icon'        => 'evaluation',
        'link'        => '/home-value/',
    ),
    array(
        'title'       => 'Marketing Presentation',
        'description' => 'See how we showcase your property to attract qualified buyers and maximize exposure.',
        'icon'        => 'marketing',
        'link'        => '/marketing/',
    ),
);
?>

<section class="bne-section bne-services">
    <div class="bne-container">
        <h2 class="bne-section-title">How Can We Help You?</h2>

        <div class="bne-grid bne-grid--3 bne-services__grid">
            <?php foreach ($services as $service) : ?>
                <a href="<?php echo esc_url(home_url($service['link'])); ?>" class="bne-service-card">
                    <div class="bne-service-card__icon">
                        <?php echo bne_get_service_icon($service['icon']); ?>
                    </div>
                    <h3 class="bne-service-card__title"><?php echo esc_html($service['title']); ?></h3>
                    <p class="bne-service-card__description"><?php echo esc_html($service['description']); ?></p>
                    <span class="bne-service-card__link">
                        Learn More
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                            <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
                        </svg>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php
/**
 * Get service icon SVG
 *
 * @param string $icon Icon name
 * @return string SVG markup
 */
function bne_get_service_icon($icon) {
    $icons = array(
        'home-sale' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M19 9.3V4h-3v2.6L12 3 2 12h3v8h5v-6h4v6h5v-8h3l-3-2.7zm-9 .7c0-1.1.9-2 2-2s2 .9 2 2h-4z"/></svg>',
        'home-buy' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3zm0 8.5c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>',
        'home-rent' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14h-2v-4H8l4-4 4 4h-2v4z"/></svg>',
        'strategy' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M9 21c0 .55.45 1 1 1h4c.55 0 1-.45 1-1v-1H9v1zm3-19C8.14 2 5 5.14 5 9c0 2.38 1.19 4.47 3 5.74V17c0 .55.45 1 1 1h6c.55 0 1-.45 1-1v-2.26c1.81-1.27 3-3.36 3-5.74 0-3.86-3.14-7-7-7zm2.85 11.1l-.85.6V16h-4v-2.3l-.85-.6C7.8 12.16 7 10.63 7 9c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.63-.8 3.16-2.15 4.1z"/></svg>',
        'evaluation' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>',
        'marketing' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="48" height="48"><path d="M18 11v2h4v-2h-4zm-2 6.61c.96.71 2.21 1.65 3.2 2.39.4-.53.8-1.07 1.2-1.6-.99-.74-2.24-1.68-3.2-2.4-.4.54-.8 1.08-1.2 1.61zM20.4 5.6c-.4-.53-.8-1.07-1.2-1.6-.99.74-2.24 1.68-3.2 2.4.4.53.8 1.07 1.2 1.6.96-.72 2.21-1.65 3.2-2.4zM4 9c-1.1 0-2 .9-2 2v2c0 1.1.9 2 2 2h1v4h2v-4h1l5 3V6L8 9H4zm11.5 3c0-1.33-.58-2.53-1.5-3.35v6.69c.92-.81 1.5-2.01 1.5-3.34z"/></svg>',
    );

    return isset($icons[$icon]) ? $icons[$icon] : '';
}
?>
