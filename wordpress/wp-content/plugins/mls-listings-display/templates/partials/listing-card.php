<?php
/**
 * Template partial for a compact listing card.
 * Enhanced to match iOS app UI (v6.56.0)
 *
 * New features:
 * - Monthly mortgage estimate
 * - MLS number display
 * - Days on market
 * - Property type badge overlay
 * - Property highlights (Pool, Waterfront, etc.)
 * - Share button
 * - Single-line truncated address
 * - Price reduction amount badge
 * - Agent photo in recommendation badge
 *
 * Expects a $listing variable to be in scope.
 */

if (!isset($listing) || !is_array($listing)) {
    return;
}

// === Basic Fields ===
$listing_id = is_object($listing) ? ($listing->listing_id ?? '') : ($listing['listing_id'] ?? '');
$listing_key = is_object($listing) ? ($listing->listing_key ?? '') : ($listing['listing_key'] ?? '');
$list_price = (float)($listing['list_price'] ?? 0);

// Check if this is a lease/rental property
$property_type_raw = $listing['PropertyType'] ?? $listing['property_type'] ?? '';
$is_lease = in_array($property_type_raw, ['Residential Lease', 'Commercial Lease']);

// Format price with /mo suffix for lease properties
$price = '$' . number_format($list_price);
if ($is_lease) {
    $price .= '/mo';
}

// Address - single line truncated format (iOS style)
$street_address = trim(sprintf('%s %s%s',
    $listing['street_number'] ?? '',
    $listing['street_name'] ?? '',
    !empty($listing['unit_number']) ? ' #' . $listing['unit_number'] : ''
));
$city = is_object($listing) ? ($listing->city ?? '') : ($listing['city'] ?? '');
$full_address = $street_address . ', ' . $city;

// Beds/Baths/Sqft
$total_baths = ($listing['bathrooms_full'] ?? 0) + (($listing['bathrooms_half'] ?? 0) * 0.5);
$beds = (int)($listing['bedrooms_total'] ?? 0);
$sqft = (int)($listing['living_area'] ?? $listing['building_area_total'] ?? 0);

// Photo URL
if (is_object($listing)) {
    $photo_url = esc_url($listing->photo_url ?? $listing->main_photo_url ?? 'https://placehold.co/400x280/eee/ccc?text=No+Image');
} else {
    $photo_url = esc_url($listing['photo_url'] ?? $listing['main_photo_url'] ?? 'https://placehold.co/400x280/eee/ccc?text=No+Image');
}
$listing_url = home_url('/property/' . $listing_id);

// === Monthly Mortgage Estimate (iOS style) ===
// 20% down payment, 7% interest rate, 30-year term
// Skip for lease properties - they already show price per month
$monthly_estimate = '';
if ($list_price > 0 && !$is_lease) {
    $down_payment = $list_price * 0.20;
    $loan_amount = $list_price - $down_payment;
    $monthly_rate = 0.07 / 12;
    $num_payments = 30 * 12;

    if ($monthly_rate > 0) {
        $monthly_payment = $loan_amount * ($monthly_rate * pow(1 + $monthly_rate, $num_payments)) / (pow(1 + $monthly_rate, $num_payments) - 1);
        $monthly_estimate = 'Est. $' . number_format((int)$monthly_payment) . '/mo';
    }
}

// === Days on Market ===
$days_on_market = isset($listing['days_on_market']) ? (int)$listing['days_on_market'] : null;
$dom_text = '';
if ($days_on_market !== null && $days_on_market > 0) {
    $dom_text = $days_on_market . ' day' . ($days_on_market !== 1 ? 's' : '') . ' on market';
}

// === Property Type Badge ===
// Use property_subtype for more specific display (e.g., "Single Family Residence", "Townhouse")
// Fall back to property_type if subtype is not available
// Note: Data may use different field names depending on source (CamelCase vs snake_case)
$property_sub_type = $listing['PropertySubType'] ?? $listing['property_subtype'] ?? $listing['property_sub_type'] ?? '';
$property_type = $listing['PropertyType'] ?? $listing['property_type'] ?? '';
$property_type_display = !empty($property_sub_type) ? $property_sub_type : $property_type;

// === Price Reduction Badge ===
$price_reduced_html = '';
$original_price = isset($listing['original_list_price']) ? (float)$listing['original_list_price'] : 0;
if ($original_price > 0 && $original_price > $list_price) {
    $reduction = $original_price - $list_price;
    if ($reduction >= 1000) {
        $reduction_text = '-$' . number_format($reduction / 1000, 0) . 'K';
    } else {
        $reduction_text = '-$' . number_format($reduction);
    }
    $price_reduced_html = '<div class="mld-price-reduced-badge">' . esc_html($reduction_text) . '</div>';
}

// === New Listing Badge (within 7 days) ===
$new_listing_html = '';
if ($days_on_market !== null && $days_on_market <= 7) {
    $new_listing_html = '<div class="mld-new-listing-badge">New</div>';
}

// === Property Highlights (Pool, Waterfront, View, Garage, Fireplace) ===
$highlights = [];

// Check for highlights from listing data
if (!empty($listing['has_pool']) || !empty($listing['pool_private_yn'])) {
    $highlights[] = ['icon' => 'pool', 'label' => 'Pool', 'color' => '#06b6d4'];
}
if (!empty($listing['has_waterfront']) || !empty($listing['waterfront_yn'])) {
    $highlights[] = ['icon' => 'waterfront', 'label' => 'Waterfront', 'color' => '#3b82f6'];
}
if (!empty($listing['has_view']) || !empty($listing['view_yn'])) {
    $highlights[] = ['icon' => 'view', 'label' => 'View', 'color' => '#22c55e'];
}
if (!empty($listing['garage_spaces']) && (int)$listing['garage_spaces'] > 0) {
    $highlights[] = ['icon' => 'garage', 'label' => 'Garage', 'color' => '#6366f1'];
}
if (!empty($listing['has_fireplace']) || !empty($listing['fireplace_yn'])) {
    $highlights[] = ['icon' => 'fireplace', 'label' => 'Fireplace', 'color' => '#f97316'];
}

// Build highlights HTML
$highlights_html = '';
if (!empty($highlights)) {
    $highlights_html = '<div class="mld-card-highlights">';
    foreach ($highlights as $h) {
        $highlights_html .= '<span class="mld-highlight-chip" style="--highlight-color: ' . esc_attr($h['color']) . ';">';
        $highlights_html .= '<span class="mld-highlight-icon mld-icon-' . esc_attr($h['icon']) . '"></span>';
        $highlights_html .= esc_html($h['label']);
        $highlights_html .= '</span>';
    }
    $highlights_html .= '</div>';
}

// Optimize image with WebP support and responsive breakpoints
if (class_exists('MLD_Image_Optimizer')) {
    $optimized_image = MLD_Image_Optimizer::get_optimized_image_tag(
        $photo_url,
        $street_address,
        [
            'width' => 400,
            'height' => 280,
            'loading' => 'lazy',
            'class' => 'mld-card-image',
            'quality' => 85,
            'responsive' => true,
            'webp' => true
        ]
    );
} else {
    $optimized_image = '<img src="' . esc_url($photo_url) . '" alt="' . esc_attr($street_address) . '" class="mld-card-image" loading="lazy" width="400" height="280">';
}

// === District school rating ===
// Skip for commercial properties and business opportunity (schools not relevant)
$is_commercial = in_array($property_type_raw, ['Commercial Sale', 'Commercial Lease', 'Business Opportunity']);
$school_info_html = '';
if ($city && !$is_commercial && class_exists('MLD_BMN_Schools_Integration')) {
    $schools_integration = MLD_BMN_Schools_Integration::get_instance();
    if ($schools_integration) {
        $district_info = $schools_integration->get_district_grade_for_city($city);
        if ($district_info && !empty($district_info['grade'])) {
            $grade = $district_info['grade'];
            $percentile = $district_info['percentile'] ?? null;
            $grade_letter = strtolower(substr($grade, 0, 1));

            $badge_text = esc_html($grade);
            if ($percentile !== null) {
                $top_percent = 100 - (int)$percentile;
                $badge_text .= ' top ' . $top_percent . '%';
            }

            $school_info_html = '<div class="bme-card-school-info grade-' . esc_attr($grade_letter) . '"><span class="bme-school-icon">🎓</span> ' . $badge_text . ' Schools</div>';
        }
    }
}

// === Favorite/Hide action buttons ===
$action_buttons_html = '';
if (is_user_logged_in() && !empty($listing_id)) {
    $heart_outline_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="m8 2.748-.717-.737C5.6.281 2.514.878 1.4 3.053c-.523 1.023-.641 2.5.314 4.385.92 1.815 2.834 3.989 6.286 6.357 3.452-2.368 5.365-4.542 6.286-6.357.955-1.886.838-3.362.314-4.385C13.486.878 10.4.28 8.717 2.01L8 2.748zM8 15C-7.333 4.868 3.279-3.04 7.824 1.143c.06.055.119.112.176.171a3.12 3.12 0 0 1 .176-.17C12.72-3.042 23.333 4.867 8 15z"/></svg>';
    $close_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/></svg>';
    $share_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M11 2.5a2.5 2.5 0 1 1 .603 1.628l-6.718 3.12a2.499 2.499 0 0 1 0 1.504l6.718 3.12a2.5 2.5 0 1 1-.488.876l-6.718-3.12a2.5 2.5 0 1 1 0-3.256l6.718-3.12A2.5 2.5 0 0 1 11 2.5z"/></svg>';

    $action_buttons_html = '<div class="bme-card-actions mld-simple-card-actions">';
    $action_buttons_html .= '<button type="button" class="bme-hide-btn" data-mls="' . esc_attr($listing_id) . '" title="Hide property">' . $close_icon . '</button>';
    $action_buttons_html .= '<button type="button" class="bme-share-btn" data-url="' . esc_url($listing_url) . '" data-title="' . esc_attr($full_address) . '" title="Share property">' . $share_icon . '</button>';
    $action_buttons_html .= '<button type="button" class="bme-favorite-btn" data-mls="' . esc_attr($listing_id) . '" title="Save property">' . $heart_outline_icon . '</button>';
    $action_buttons_html .= '</div>';
}

// === "Recommended by [Agent]" badge with photo ===
$from_agent_badge_html = '';
if (!empty($listing_key) && class_exists('MLD_Mobile_REST_API')) {
    $agent_info = MLD_Mobile_REST_API::get_shared_agent_info($listing_key);
    if ($agent_info) {
        $agent_name = esc_html($agent_info['first_name'] ?: 'Agent');
        $agent_photo = esc_url($agent_info['photo_url'] ?: '');
        $photo_html = $agent_photo ? '<img src="' . $agent_photo . '" alt="' . $agent_name . '" class="mld-agent-badge-photo">' : '';
        $from_agent_badge_html = '<div class="mld-from-agent-badge">' . $photo_html . '<span>Recommended by ' . $agent_name . '</span></div>';
    }
}

// === Image Overlay Badges ===
$overlay_badges_html = '';

// v6.65.0: Exclusive Listing Badge (TOP-LEFT) - for exclusive listings (listing_id < 1,000,000)
// DEBUG: Temporary - shows listing IDs in HTML source
echo "<!-- DEBUG listing_id=" . intval($listing_id) . " -->\n";
$exclusive_badge_html = '';
if (intval($listing_id) > 0 && intval($listing_id) < 1000000) {
    $exclusive_tag = !empty($listing['exclusive_tag']) ? $listing['exclusive_tag'] : 'Exclusive';
    $exclusive_badge_html = '<div class="mld-exclusive-badge"><svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z"/></svg> ' . esc_html($exclusive_tag) . '</div>';
}

// Property type badge (bottom-left)
if (!empty($property_type_display)) {
    $overlay_badges_html .= '<div class="mld-property-type-badge">' . esc_html($property_type_display) . '</div>';
}

// Status badges container (for new listing, price reduced)
$status_badges_html = '';
if (!empty($new_listing_html) || !empty($price_reduced_html)) {
    $status_badges_html = '<div class="mld-status-badges">' . $new_listing_html . $price_reduced_html . '</div>';
}
?>
<a href="<?php echo esc_url($listing_url); ?>" class="mld-listing-card-simple"
   data-listing-id="<?php echo esc_attr($listing_id); ?>"
   data-listing-key="<?php echo esc_attr($listing_key); ?>"
   data-property-city="<?php echo esc_attr($city); ?>"
   data-property-price="<?php echo esc_attr($list_price); ?>">
    <div class="mld-card-simple-image">
        <?php echo $optimized_image; ?>
        <?php echo $action_buttons_html; ?>
        <?php echo $exclusive_badge_html; ?>
        <?php echo $from_agent_badge_html; ?>
        <?php echo $overlay_badges_html; ?>
        <?php echo $status_badges_html; ?>
    </div>
    <div class="mld-card-simple-details">
        <div class="mld-card-price-row">
            <div class="mld-card-simple-price"><?php echo esc_html($price); ?></div>
            <?php if (!empty($monthly_estimate)) : ?>
                <div class="mld-card-monthly-estimate"><?php echo esc_html($monthly_estimate); ?></div>
            <?php endif; ?>
        </div>
        <div class="mld-card-simple-address">
            <span class="mld-address-line" title="<?php echo esc_attr($full_address); ?>"><?php echo esc_html($full_address); ?></span>
        </div>
        <div class="mld-card-mls-number">MLS# <?php echo esc_html($listing_id); ?></div>
        <div class="mld-card-simple-specs">
            <span><strong><?php echo $beds; ?></strong> bd</span>
            <span class="mld-spec-divider">|</span>
            <span><strong><?php echo $total_baths; ?></strong> ba</span>
            <span class="mld-spec-divider">|</span>
            <span><strong><?php echo number_format($sqft); ?></strong> sqft</span>
        </div>
        <?php echo $school_info_html; ?>
        <?php echo $highlights_html; ?>
        <?php if (!empty($dom_text)) : ?>
            <div class="mld-card-dom"><?php echo esc_html($dom_text); ?></div>
        <?php endif; ?>
    </div>
</a>
