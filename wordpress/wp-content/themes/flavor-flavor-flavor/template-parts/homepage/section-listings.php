<?php
/**
 * Homepage Newest Listings Section
 *
 * Displays a grid of the newest property listings.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get newest listings (8 for 2 rows of 4)
$listings = BNE_MLS_Helpers::get_newest_listings(8);
?>

<section class="bne-section bne-listings-section">
    <div class="bne-container">
        <h2 class="bne-section-title">Newest Listings</h2>
        <p class="bne-section-subtitle">Fresh on the market - explore our latest properties</p>

        <?php if (!empty($listings)) : ?>
            <div class="bne-listings-grid">
                <?php foreach ($listings as $listing) : ?>
                    <a href="<?php echo esc_url($listing['url']); ?>" class="bne-listing-card">
                        <div class="bne-listing-card__image-wrapper">
                            <img
                                src="<?php echo esc_url($listing['photo']); ?>"
                                alt="<?php echo esc_attr($listing['address']); ?>"
                                class="bne-listing-card__image"
                                loading="lazy"
                            >
                            <span class="bne-listing-card__price"><?php echo esc_html($listing['price']); ?></span>
                            <?php if ($listing['type']) : ?>
                                <span class="bne-listing-card__type"><?php echo esc_html($listing['type']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="bne-listing-card__content">
                            <h3 class="bne-listing-card__address"><?php echo esc_html($listing['address']); ?></h3>
                            <p class="bne-listing-card__location">
                                <?php echo esc_html($listing['city']); ?>, <?php echo esc_html($listing['state']); ?> <?php echo esc_html($listing['zip']); ?>
                            </p>
                            <div class="bne-listing-card__details">
                                <span class="bne-listing-card__detail">
                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                                        <path d="M7 14c1.66 0 3-1.34 3-3S8.66 8 7 8s-3 1.34-3 3 1.34 3 3 3zm0-4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm12-3h-8v8H3V5H1v15h2v-3h18v3h2v-9c0-2.21-1.79-4-4-4zm2 8h-8V9h6c1.1 0 2 .9 2 2v4z"/>
                                    </svg>
                                    <?php echo esc_html($listing['beds']); ?> bd
                                </span>
                                <span class="bne-listing-card__detail">
                                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                                        <path d="M7 7c0-1.1-.9-2-2-2s-2 .9-2 2 .9 2 2 2 2-.9 2-2zM5 18c-.55 0-1-.45-1-1H2c0 1.66 1.34 3 3 3s3-1.34 3-3H6c0 .55-.45 1-1 1zm8 0c-.55 0-1-.45-1-1h-2c0 1.66 1.34 3 3 3s3-1.34 3-3h-2c0 .55-.45 1-1 1zm6 0c-.55 0-1-.45-1-1h-2c0 1.66 1.34 3 3 3s3-1.34 3-3h-2c0 .55-.45 1-1 1zm2-7v-2c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v2H1v4h2v-2h18v2h2v-4h-2zm-2 0H5V9h14v2z"/>
                                    </svg>
                                    <?php echo esc_html($listing['baths']); ?> ba
                                </span>
                                <?php if ($listing['sqft_raw']) : ?>
                                    <span class="bne-listing-card__detail">
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" width="16" height="16">
                                            <path d="M19 12h-2v3h-3v2h5v-5zM7 9h3V7H5v5h2V9zm14-6H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16.01H3V4.99h18v14.02z"/>
                                        </svg>
                                        <?php echo esc_html($listing['sqft']); ?> sqft
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="bne-no-content">No listings available at this time.</p>
        <?php endif; ?>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(BNE_MLS_Helpers::get_search_page_url()); ?>" class="bne-btn bne-btn--primary bne-btn--lg">
                View All Listings
            </a>
        </div>
    </div>
</section>
