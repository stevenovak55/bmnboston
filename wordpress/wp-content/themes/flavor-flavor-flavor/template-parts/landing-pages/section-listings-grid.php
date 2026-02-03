<?php
/**
 * Landing Page Listings Grid Section
 *
 * Displays property listing cards in a responsive grid with enhanced data
 *
 * @package flavor_flavor_flavor
 * @version 1.3.2
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? '';
$listings = $data['listings'] ?? array();
$listing_count = $data['listing_count'] ?? 0;
$pagination = $data['pagination'] ?? array();
$active_filters = $data['active_filters'] ?? array();
$listing_type = $active_filters['listing_type'] ?? 'sale';

// Get current URL for pagination
$current_url = strtok($_SERVER['REQUEST_URI'], '?');
$query_params = $_GET;
unset($query_params['pg']);
?>

<section class="bne-landing-listings" id="listings">
    <div class="bne-landing-container">

        <?php if (empty($listings)) : ?>
            <div class="bne-landing-listings__empty">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <h3>No properties found</h3>
                <p>Try adjusting your filters or <a href="<?php echo esc_url($current_url); ?>">clear all filters</a> to see more results.</p>
            </div>
        <?php else : ?>

            <div class="bne-landing-listings__grid">
                <?php foreach ($listings as $listing) :
                    $listing_url = home_url('/property/' . $listing['listing_id'] . '/');
                    $address = trim($listing['street_address'] ?? '');
                    $unit = $listing['unit_number'] ?? '';
                    if (!empty($unit)) {
                        $address .= ' #' . $unit;
                    }
                    $city = $listing['city'] ?? '';
                    $state = $listing['state'] ?? 'MA';
                    $zip = $listing['postal_code'] ?? '';
                    $price = $listing['list_price'] ?? 0;
                    $original_price = $listing['original_list_price'] ?? 0;
                    $beds = $listing['beds'] ?? 0;
                    $baths = $listing['baths'] ?? 0;
                    $sqft = $listing['living_area'] ?? 0;
                    $photo = $listing['photo'] ?? '';
                    $photo_count = $listing['photo_count'] ?? 0;
                    $dom = $listing['days_on_market'] ?? 0;
                    $property_type = $listing['property_sub_type'] ?? $listing['property_type'] ?? '';
                    $year_built = $listing['year_built'] ?? 0;
                    $has_pool = $listing['has_pool'] ?? 0;
                    $has_fireplace = $listing['has_fireplace'] ?? 0;
                    $garage = $listing['garage_spaces'] ?? 0;

                    // Calculate price change
                    $price_reduced = ($original_price > 0 && $price < $original_price);
                ?>
                    <article class="bne-landing-listing-card">
                        <a href="<?php echo esc_url($listing_url); ?>" class="bne-landing-listing-card__link">
                            <div class="bne-landing-listing-card__image">
                                <?php if (!empty($photo)) : ?>
                                    <img src="<?php echo esc_url($photo); ?>"
                                         alt="<?php echo esc_attr($address); ?>"
                                         loading="lazy">
                                <?php else : ?>
                                    <div class="bne-landing-listing-card__no-image">
                                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                        </svg>
                                    </div>
                                <?php endif; ?>

                                <!-- Badges -->
                                <div class="bne-landing-listing-card__badges">
                                    <?php if ($dom <= 3) : ?>
                                        <span class="bne-landing-listing-card__badge bne-landing-listing-card__badge--new">New</span>
                                    <?php elseif ($dom <= 7) : ?>
                                        <span class="bne-landing-listing-card__badge bne-landing-listing-card__badge--recent">This Week</span>
                                    <?php endif; ?>

                                    <?php if ($price_reduced) : ?>
                                        <span class="bne-landing-listing-card__badge bne-landing-listing-card__badge--reduced">Price Reduced</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Photo count -->
                                <?php if ($photo_count > 1) : ?>
                                    <span class="bne-landing-listing-card__photo-count">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                            <polyline points="21 15 16 10 5 21"></polyline>
                                        </svg>
                                        <?php echo number_format($photo_count); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="bne-landing-listing-card__content">
                                <div class="bne-landing-listing-card__price">
                                    $<?php echo number_format($price); ?>
                                    <?php if ($listing_type === 'lease') : ?><span class="bne-landing-listing-card__price-period">/mo</span><?php endif; ?>
                                    <?php if ($price_reduced) : ?>
                                        <span class="bne-landing-listing-card__original-price">$<?php echo number_format($original_price); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="bne-landing-listing-card__details">
                                    <?php if ($beds > 0) : ?>
                                        <span class="bne-landing-listing-card__detail">
                                            <strong><?php echo esc_html($beds); ?></strong> bd
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($baths > 0) : ?>
                                        <span class="bne-landing-listing-card__detail">
                                            <strong><?php echo esc_html(number_format($baths, 1)); ?></strong> ba
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($sqft > 0) : ?>
                                        <span class="bne-landing-listing-card__detail">
                                            <strong><?php echo number_format($sqft); ?></strong> sqft
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <address class="bne-landing-listing-card__address">
                                    <span class="bne-landing-listing-card__street"><?php echo esc_html($address); ?></span>
                                    <span class="bne-landing-listing-card__city"><?php echo esc_html($city); ?>, <?php echo esc_html($state); ?> <?php echo esc_html($zip); ?></span>
                                </address>

                                <div class="bne-landing-listing-card__meta">
                                    <?php if (!empty($property_type)) : ?>
                                        <span class="bne-landing-listing-card__type"><?php echo esc_html($property_type); ?></span>
                                    <?php endif; ?>
                                    <?php if ($year_built > 0) : ?>
                                        <span class="bne-landing-listing-card__year">Built <?php echo esc_html($year_built); ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($has_pool || $has_fireplace || $garage > 0) : ?>
                                    <div class="bne-landing-listing-card__features">
                                        <?php if ($has_pool) : ?>
                                            <span class="bne-landing-listing-card__feature" title="Pool">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M2 12h20M2 18h20M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v0"></path>
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($has_fireplace) : ?>
                                            <span class="bne-landing-listing-card__feature" title="Fireplace">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M12 2c.5 5.5-2 8-5 10 3.5.5 6 3 6 7 0-4 2.5-6.5 6-7-3-2-5.5-4.5-7-10Z"></path>
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($garage > 0) : ?>
                                            <span class="bne-landing-listing-card__feature" title="<?php echo esc_attr($garage); ?> car garage">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16"></path>
                                                    <path d="M3 21h18"></path>
                                                    <path d="M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"></path>
                                                </svg>
                                                <?php echo esc_html($garage); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if (!empty($pagination) && $pagination['total_pages'] > 1) : ?>
                <nav class="bne-landing-pagination" aria-label="Pagination">
                    <?php
                    $current_page = $pagination['current_page'];
                    $total_pages = $pagination['total_pages'];

                    // Previous link
                    if ($current_page > 1) :
                        $prev_url = add_query_arg(array_merge($query_params, array('pg' => $current_page - 1)), $current_url);
                    ?>
                        <a href="<?php echo esc_url($prev_url); ?>#listings" class="bne-landing-pagination__link bne-landing-pagination__link--prev">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="m15 18-6-6 6-6"></path>
                            </svg>
                            Previous
                        </a>
                    <?php endif; ?>

                    <div class="bne-landing-pagination__pages">
                        <?php
                        // Show page numbers
                        $start = max(1, $current_page - 2);
                        $end = min($total_pages, $current_page + 2);

                        if ($start > 1) {
                            $url = add_query_arg(array_merge($query_params, array('pg' => 1)), $current_url);
                            echo '<a href="' . esc_url($url) . '#listings" class="bne-landing-pagination__page">1</a>';
                            if ($start > 2) {
                                echo '<span class="bne-landing-pagination__ellipsis">...</span>';
                            }
                        }

                        for ($i = $start; $i <= $end; $i++) {
                            $url = add_query_arg(array_merge($query_params, array('pg' => $i)), $current_url);
                            $active = $i === $current_page ? ' bne-landing-pagination__page--active' : '';
                            echo '<a href="' . esc_url($url) . '#listings" class="bne-landing-pagination__page' . $active . '">' . $i . '</a>';
                        }

                        if ($end < $total_pages) {
                            if ($end < $total_pages - 1) {
                                echo '<span class="bne-landing-pagination__ellipsis">...</span>';
                            }
                            $url = add_query_arg(array_merge($query_params, array('pg' => $total_pages)), $current_url);
                            echo '<a href="' . esc_url($url) . '#listings" class="bne-landing-pagination__page">' . $total_pages . '</a>';
                        }
                        ?>
                    </div>

                    <?php
                    // Next link
                    if ($current_page < $total_pages) :
                        $next_url = add_query_arg(array_merge($query_params, array('pg' => $current_page + 1)), $current_url);
                    ?>
                        <a href="<?php echo esc_url($next_url); ?>#listings" class="bne-landing-pagination__link bne-landing-pagination__link--next">
                            Next
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="m9 18 6-6-6-6"></path>
                            </svg>
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

            <!-- View All Link -->
            <?php if ($listing_count > count($listings) && empty($pagination)) : ?>
                <div class="bne-landing-listings__footer">
                    <a href="<?php echo esc_url(home_url('/property-search/?city=' . urlencode($name))); ?>" class="bne-landing-button bne-landing-button--primary">
                        See All <?php echo number_format($listing_count); ?> Properties
                    </a>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</section>
