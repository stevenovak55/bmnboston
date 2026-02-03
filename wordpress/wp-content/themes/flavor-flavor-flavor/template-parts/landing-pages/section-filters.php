<?php
/**
 * Landing Page Filters Section
 *
 * Dynamic filter bar with options loaded from database
 *
 * @package flavor_flavor_flavor
 * @version 1.3.3
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? '';
$filter_options = $data['filter_options'] ?? array();
$active_filters = $data['active_filters'] ?? array();
$listing_count = $data['listing_count'] ?? 0;

// Get current URL for form action
$current_url = strtok($_SERVER['REQUEST_URI'], '?');
?>

<section class="bne-landing-filters" id="filters">
    <div class="bne-landing-container">
        <form method="get" action="<?php echo esc_url($current_url); ?>" class="bne-landing-filters__form">

            <!-- Mobile Header: Toggle button + For Sale/Rent selector -->
            <div class="bne-landing-filters__mobile-header">
                <button type="button" class="bne-landing-filters__mobile-toggle" aria-expanded="false" aria-controls="filters-row">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <line x1="4" y1="6" x2="20" y2="6"></line>
                        <line x1="4" y1="12" x2="20" y2="12"></line>
                        <line x1="4" y1="18" x2="20" y2="18"></line>
                    </svg>
                    <span>Filters</span>
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m6 9 6 6 6-6"></path>
                    </svg>
                </button>

                <!-- Mobile: For Sale / For Rent toggle (always visible on mobile) -->
                <div class="bne-landing-filter-group bne-landing-filter-group--type-mobile">
                    <div class="bne-landing-toggle-group">
                        <label class="bne-landing-toggle <?php echo ($active_filters['listing_type'] ?? 'sale') === 'sale' ? 'bne-landing-toggle--active' : ''; ?>">
                            <input type="radio" name="type" value="sale" <?php checked($active_filters['listing_type'] ?? 'sale', 'sale'); ?>>
                            <span>For Sale</span>
                        </label>
                        <label class="bne-landing-toggle <?php echo ($active_filters['listing_type'] ?? '') === 'lease' ? 'bne-landing-toggle--active' : ''; ?>">
                            <input type="radio" name="type" value="lease" <?php checked($active_filters['listing_type'] ?? '', 'lease'); ?>>
                            <span>For Rent</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="bne-landing-filters__row" id="filters-row">
                <!-- Listing Type Toggle (For Sale / For Rent) -->
                <div class="bne-landing-filter-group bne-landing-filter-group--type">
                    <div class="bne-landing-toggle-group">
                        <label class="bne-landing-toggle <?php echo ($active_filters['listing_type'] ?? 'sale') === 'sale' ? 'bne-landing-toggle--active' : ''; ?>">
                            <input type="radio" name="type" value="sale" <?php checked($active_filters['listing_type'] ?? 'sale', 'sale'); ?>>
                            <span>For Sale</span>
                        </label>
                        <label class="bne-landing-toggle <?php echo ($active_filters['listing_type'] ?? '') === 'lease' ? 'bne-landing-toggle--active' : ''; ?>">
                            <input type="radio" name="type" value="lease" <?php checked($active_filters['listing_type'] ?? '', 'lease'); ?>>
                            <span>For Rent</span>
                        </label>
                    </div>
                </div>

                <!-- Property Type -->
                <?php if (!empty($filter_options['property_sub_types'])) : ?>
                <div class="bne-landing-filter-group">
                    <label class="bne-landing-filter-label">Property Type</label>
                    <select name="sub_type" class="bne-landing-filter-select">
                        <option value="">All Types</option>
                        <?php foreach ($filter_options['property_sub_types'] as $sub_type) : ?>
                            <option value="<?php echo esc_attr($sub_type['value']); ?>"
                                <?php selected($active_filters['property_sub_type'] ?? '', $sub_type['value']); ?>>
                                <?php echo esc_html($sub_type['label']); ?>
                                (<?php echo number_format($sub_type['count']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Bedrooms -->
                <?php if (!empty($filter_options['bedrooms'])) : ?>
                <div class="bne-landing-filter-group">
                    <label class="bne-landing-filter-label">Beds</label>
                    <select name="beds" class="bne-landing-filter-select">
                        <option value="">Any</option>
                        <?php foreach ($filter_options['bedrooms'] as $bed) : ?>
                            <option value="<?php echo esc_attr($bed['value']); ?>"
                                <?php selected($active_filters['min_beds'] ?? '', $bed['value']); ?>>
                                <?php echo esc_html($bed['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Bathrooms -->
                <?php if (!empty($filter_options['bathrooms'])) : ?>
                <div class="bne-landing-filter-group">
                    <label class="bne-landing-filter-label">Baths</label>
                    <select name="baths" class="bne-landing-filter-select">
                        <option value="">Any</option>
                        <?php foreach ($filter_options['bathrooms'] as $bath) : ?>
                            <option value="<?php echo esc_attr($bath['value']); ?>"
                                <?php selected($active_filters['min_baths'] ?? '', $bath['value']); ?>>
                                <?php echo esc_html($bath['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Price Range -->
                <?php if (!empty($filter_options['price_ranges'])) : ?>
                <div class="bne-landing-filter-group bne-landing-filter-group--price">
                    <label class="bne-landing-filter-label">Price</label>
                    <div class="bne-landing-price-inputs">
                        <select name="min_price" class="bne-landing-filter-select bne-landing-filter-select--half">
                            <option value="">Min</option>
                            <?php
                            $price_points = array();
                            foreach ($filter_options['price_ranges'] as $range) {
                                if ($range['min'] > 0 && !in_array($range['min'], $price_points)) {
                                    $price_points[] = $range['min'];
                                }
                                if ($range['max'] > 0 && !in_array($range['max'], $price_points)) {
                                    $price_points[] = $range['max'];
                                }
                            }
                            sort($price_points);
                            foreach ($price_points as $price) :
                            ?>
                                <option value="<?php echo esc_attr($price); ?>"
                                    <?php selected($active_filters['min_price'] ?? '', $price); ?>>
                                    $<?php echo esc_html(bne_format_price_short($price)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="bne-landing-price-separator">-</span>
                        <select name="max_price" class="bne-landing-filter-select bne-landing-filter-select--half">
                            <option value="">Max</option>
                            <?php foreach ($price_points as $price) : ?>
                                <option value="<?php echo esc_attr($price); ?>"
                                    <?php selected($active_filters['max_price'] ?? '', $price); ?>>
                                    $<?php echo esc_html(bne_format_price_short($price)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Search Button -->
                <div class="bne-landing-filter-group bne-landing-filter-group--submit">
                    <button type="submit" class="bne-landing-filters__submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        Search
                    </button>
                </div>
            </div>

            <!-- Advanced Filters (Features) -->
            <?php if (!empty($filter_options['features'])) : ?>
            <div class="bne-landing-filters__advanced">
                <button type="button" class="bne-landing-filters__toggle-advanced" aria-expanded="false">
                    <span>More Filters</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m6 9 6 6 6-6"></path>
                    </svg>
                </button>

                <div class="bne-landing-filters__advanced-panel" hidden>
                    <div class="bne-landing-filters__features">
                        <span class="bne-landing-filter-label">Features:</span>
                        <?php foreach ($filter_options['features'] as $feature) : ?>
                            <label class="bne-landing-checkbox">
                                <input type="checkbox"
                                    name="features[]"
                                    value="<?php echo esc_attr($feature['value']); ?>"
                                    <?php checked(in_array($feature['value'], $active_filters['features'] ?? array())); ?>>
                                <span class="bne-landing-checkbox__label">
                                    <?php echo esc_html($feature['label']); ?>
                                    <span class="bne-landing-checkbox__count">(<?php echo number_format($feature['count']); ?>)</span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </form>

        <!-- Results Summary & Sort -->
        <div class="bne-landing-filters__summary">
            <div class="bne-landing-filters__count">
                <strong><?php echo number_format($listing_count); ?></strong>
                <?php echo $listing_count === 1 ? 'property' : 'properties'; ?>
                found in <?php echo esc_html($name); ?>
            </div>

            <div class="bne-landing-filters__sort">
                <label for="sort-select">Sort by:</label>
                <select id="sort-select" class="bne-landing-sort-select" onchange="window.location.href=this.value">
                    <?php
                    $base_url = add_query_arg(array_filter(array(
                        'type' => $active_filters['listing_type'] ?? '',
                        'sub_type' => $active_filters['property_sub_type'] ?? '',
                        'beds' => $active_filters['min_beds'] ?? '',
                        'baths' => $active_filters['min_baths'] ?? '',
                        'min_price' => $active_filters['min_price'] ?? '',
                        'max_price' => $active_filters['max_price'] ?? '',
                    )), $current_url);

                    $sort_options = array(
                        '' => 'Newest Updated',
                        'newest' => 'Newest Listed',
                        'price_asc' => 'Price (Low to High)',
                        'price_desc' => 'Price (High to Low)',
                        'beds_desc' => 'Bedrooms',
                        'sqft_desc' => 'Square Feet',
                    );

                    foreach ($sort_options as $value => $label) :
                        $url = add_query_arg('sort', $value, $base_url);
                    ?>
                        <option value="<?php echo esc_url($url); ?>"
                            <?php selected($active_filters['sort'] ?? '', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Active Filters Tags -->
        <?php
        $has_active_filters = !empty($active_filters['property_sub_type'])
            || !empty($active_filters['min_beds'])
            || !empty($active_filters['min_baths'])
            || !empty($active_filters['min_price'])
            || !empty($active_filters['max_price'])
            || !empty($active_filters['features']);

        if ($has_active_filters) :
        ?>
        <div class="bne-landing-filters__active">
            <span class="bne-landing-filters__active-label">Active filters:</span>

            <?php if (!empty($active_filters['property_sub_type'])) : ?>
                <a href="<?php echo esc_url(remove_query_arg('sub_type')); ?>" class="bne-landing-filter-tag">
                    <?php echo esc_html($active_filters['property_sub_type']); ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"></path>
                    </svg>
                </a>
            <?php endif; ?>

            <?php if (!empty($active_filters['min_beds'])) : ?>
                <a href="<?php echo esc_url(remove_query_arg('beds')); ?>" class="bne-landing-filter-tag">
                    <?php echo esc_html($active_filters['min_beds']); ?>+ beds
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"></path>
                    </svg>
                </a>
            <?php endif; ?>

            <?php if (!empty($active_filters['min_baths'])) : ?>
                <a href="<?php echo esc_url(remove_query_arg('baths')); ?>" class="bne-landing-filter-tag">
                    <?php echo esc_html($active_filters['min_baths']); ?>+ baths
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"></path>
                    </svg>
                </a>
            <?php endif; ?>

            <?php if (!empty($active_filters['min_price']) || !empty($active_filters['max_price'])) : ?>
                <a href="<?php echo esc_url(remove_query_arg(array('min_price', 'max_price'))); ?>" class="bne-landing-filter-tag">
                    <?php
                    if (!empty($active_filters['min_price']) && !empty($active_filters['max_price'])) {
                        echo '$' . bne_format_price_short($active_filters['min_price']) . ' - $' . bne_format_price_short($active_filters['max_price']);
                    } elseif (!empty($active_filters['min_price'])) {
                        echo '$' . bne_format_price_short($active_filters['min_price']) . '+';
                    } else {
                        echo 'Up to $' . bne_format_price_short($active_filters['max_price']);
                    }
                    ?>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"></path>
                    </svg>
                </a>
            <?php endif; ?>

            <?php if (!empty($active_filters['features'])) : ?>
                <?php foreach ($active_filters['features'] as $feature) : ?>
                    <a href="<?php echo esc_url(add_query_arg('features', array_diff($active_filters['features'], array($feature)))); ?>" class="bne-landing-filter-tag">
                        <?php echo esc_html(ucfirst($feature)); ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12"></path>
                        </svg>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <a href="<?php echo esc_url($current_url); ?>" class="bne-landing-filter-clear">
                Clear all
            </a>
        </div>
        <?php endif; ?>

    </div>
</section>

<script>
// Toggle advanced filters and mobile filters
document.addEventListener('DOMContentLoaded', function() {
    // Advanced filters toggle
    var advancedToggleBtn = document.querySelector('.bne-landing-filters__toggle-advanced');
    var advancedPanel = document.querySelector('.bne-landing-filters__advanced-panel');

    if (advancedToggleBtn && advancedPanel) {
        advancedToggleBtn.addEventListener('click', function() {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !expanded);
            advancedPanel.hidden = expanded;
        });
    }

    // Mobile filters toggle
    var mobileToggleBtn = document.querySelector('.bne-landing-filters__mobile-toggle');
    var filtersRow = document.querySelector('.bne-landing-filters__row');

    if (mobileToggleBtn && filtersRow) {
        mobileToggleBtn.addEventListener('click', function() {
            var expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !expanded);
            filtersRow.classList.toggle('is-open', !expanded);
        });
    }

    // Auto-submit on toggle change (For Sale / For Rent)
    var toggleInputs = document.querySelectorAll('.bne-landing-toggle input');
    toggleInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });
});
</script>
