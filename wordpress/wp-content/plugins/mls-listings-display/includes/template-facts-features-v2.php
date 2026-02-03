<?php
/**
 * Modern Facts & Features Template Component
 * Clean, minimal design inspired by Zillow and Redfin
 */

// Include the new icon system
require_once MLD_PLUGIN_PATH . 'includes/facts-features-icons-v2.php';

// Helper function to format array fields
if (!function_exists('format_array_field')) {
    function format_array_field($value) {
        if (is_array($value)) {
            return implode(', ', array_filter($value));
        }
        return $value;
    }
}


/**
 * Render the key highlights section (tag-based)
 */
function mld_render_property_highlights($listing) {
    $highlights = [];

    // Gather key highlights
    if (!empty($listing['waterfront_yn']) && $listing['waterfront_yn'] === 'Y') {
        $highlights[] = ['label' => 'Waterfront', 'icon' => 'waterfront', 'class' => 'highlight-premium'];
    }

    if (!empty($listing['pool_private_yn']) && $listing['pool_private_yn'] === 'Y') {
        $highlights[] = ['label' => 'Pool', 'icon' => 'pool', 'class' => 'highlight-premium'];
    }

    if (!empty($listing['view_yn']) && $listing['view_yn'] === 'Y') {
        $highlights[] = ['label' => 'View', 'icon' => 'view', 'class' => 'highlight-premium'];
    }

    if (!empty($listing['garage_spaces']) && $listing['garage_spaces'] > 0) {
        $highlights[] = ['label' => $listing['garage_spaces'] . ' Car Garage', 'icon' => 'garage', 'class' => 'highlight-standard'];
    }

    if (!empty($listing['fireplace_yn']) && $listing['fireplace_yn'] === 'Y') {
        $highlights[] = ['label' => 'Fireplace', 'icon' => 'heating', 'class' => 'highlight-standard'];
    }

    if (!empty($listing['basement_yn']) && $listing['basement_yn'] === 'Y') {
        $highlights[] = ['label' => 'Basement', 'icon' => 'rooms', 'class' => 'highlight-standard'];
    }

    if (!empty($listing['cooling']) && stripos($listing['cooling'], 'central') !== false) {
        $highlights[] = ['label' => 'Central AC', 'icon' => 'cooling', 'class' => 'highlight-standard'];
    }

    $new_construction = false;
    if (!empty($listing['new_construction_yn']) && $listing['new_construction_yn'] === 'Y') {
        $new_construction = true;
    } elseif (!empty($listing['year_built']) && $listing['year_built'] >= date('Y')) {
        $new_construction = true;
    }

    if ($new_construction) {
        $highlights[] = ['label' => 'New Construction', 'icon' => 'new', 'class' => 'highlight-new'];
    }

    if (empty($highlights)) {
        return '';
    }

    ?>
    <div class="mld-property-highlights">
        <?php foreach ($highlights as $highlight): ?>
            <div class="mld-highlight-tag <?php echo esc_attr($highlight['class']); ?>">
                <span class="mld-highlight-label"><?php echo esc_html($highlight['label']); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Render the main facts grid
 */
function mld_render_facts_grid($listing, $is_mobile = false) {
    ?>
    <div class="mld-facts-main-grid <?php echo $is_mobile ? 'mld-facts-mobile' : ''; ?>">

        <!-- Home Facts Section -->
        <div class="mld-facts-section mld-facts-home mld-facts-span-full">
            <h3 class="mld-facts-section-title">Home Facts</h3>
            <div class="mld-facts-list">

                <?php
                // Primary facts - always show these first
                $primary_facts = [
                    'property_type' => ['label' => 'Type', 'icon' => 'exterior'],
                    'property_sub_type' => ['label' => 'Style', 'icon' => 'exterior'],
                    'year_built' => ['label' => 'Year Built', 'icon' => 'year'],
                    'living_area' => ['label' => 'Living Area', 'icon' => 'sqft'],
                    'lot_size_acres' => ['label' => 'Lot Size', 'icon' => 'lot'],
                    'lot_size_square_feet' => ['label' => 'Lot Size', 'icon' => 'lot'],
                    'stories' => ['label' => 'Stories', 'icon' => 'exterior'],
                    'bedrooms_total' => ['label' => 'Bedrooms', 'icon' => 'beds'],
                    'bathrooms_full' => ['label' => 'Full Baths', 'icon' => 'baths'],
                    'bathrooms_half' => ['label' => 'Half Baths', 'icon' => 'baths'],
                    'rooms_total' => ['label' => 'Total Rooms', 'icon' => 'rooms'],
                ];

                foreach ($primary_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    // Skip lot_size_square_feet if we have acres
                    if ($field === 'lot_size_square_feet' && !empty($listing['lot_size_acres'])) continue;

                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>

        <!-- Interior Section -->
        <div class="mld-facts-section mld-facts-interior">
            <h3 class="mld-facts-section-title">Interior</h3>
            <div class="mld-facts-list">
                <?php
                $interior_facts = [
                    'heating' => ['label' => 'Heating', 'icon' => 'heating'],
                    'cooling' => ['label' => 'Cooling', 'icon' => 'cooling'],
                    'flooring' => ['label' => 'Flooring', 'icon' => 'flooring'],
                    'appliances' => ['label' => 'Appliances', 'icon' => 'appliances'],
                    'fireplace_features' => ['label' => 'Fireplace', 'icon' => 'heating'],
                    'fireplaces_total' => ['label' => 'Fireplaces', 'icon' => 'heating'],
                    'basement' => ['label' => 'Basement', 'icon' => 'rooms'],
                    'laundry_features' => ['label' => 'Laundry', 'icon' => 'appliances'],
                    'interior_features' => ['label' => 'Features', 'icon' => 'default'],
                ];

                $has_interior = false;
                foreach ($interior_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    $has_interior = true;
                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }

                if (!$has_interior) {
                    echo '<div class="mld-no-data">No interior details available</div>';
                }
                ?>
            </div>
        </div>

        <!-- Exterior Section -->
        <div class="mld-facts-section mld-facts-exterior">
            <h3 class="mld-facts-section-title">Exterior</h3>
            <div class="mld-facts-list">
                <?php
                $exterior_facts = [
                    'construction_materials' => ['label' => 'Construction', 'icon' => 'exterior'],
                    'architectural_style' => ['label' => 'Architecture', 'icon' => 'exterior'],
                    'roof' => ['label' => 'Roof', 'icon' => 'exterior'],
                    'exterior_features' => ['label' => 'Features', 'icon' => 'exterior'],
                    'pool_features' => ['label' => 'Pool', 'icon' => 'pool'],
                    'spa_features' => ['label' => 'Spa', 'icon' => 'pool'],
                    'fencing' => ['label' => 'Fencing', 'icon' => 'fence'],
                    'patio_and_porch_features' => ['label' => 'Patio/Porch', 'icon' => 'exterior'],
                ];

                $has_exterior = false;
                foreach ($exterior_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    $has_exterior = true;
                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }

                if (!$has_exterior) {
                    echo '<div class="mld-no-data">No exterior details available</div>';
                }
                ?>
            </div>
        </div>

        <!-- Parking & Garage -->
        <div class="mld-facts-section mld-facts-parking">
            <h3 class="mld-facts-section-title">Parking</h3>
            <div class="mld-facts-list">
                <?php
                $parking_facts = [
                    'garage_spaces' => ['label' => 'Garage Spaces', 'icon' => 'garage'],
                    'garage_yn' => ['label' => 'Has Garage', 'format' => 'yn', 'icon' => 'garage'],
                    'carport_spaces' => ['label' => 'Carport Spaces', 'icon' => 'garage'],
                    'parking_features' => ['label' => 'Features', 'icon' => 'garage'],
                    'parking_total' => ['label' => 'Total Spaces', 'icon' => 'garage'],
                ];

                $has_parking = false;
                foreach ($parking_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    $has_parking = true;
                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }

                if (!$has_parking) {
                    echo '<div class="mld-no-data">No parking details available</div>';
                }
                ?>
            </div>
        </div>

        <!-- Utilities -->
        <div class="mld-facts-section mld-facts-utilities">
            <h3 class="mld-facts-section-title">Utilities</h3>
            <div class="mld-facts-list">
                <?php
                $utility_facts = [
                    'electric' => ['label' => 'Electric', 'icon' => 'electric'],
                    'water_source' => ['label' => 'Water', 'icon' => 'water'],
                    'sewer' => ['label' => 'Sewer', 'icon' => 'sewer'],
                    'gas' => ['label' => 'Gas', 'icon' => 'gas'],
                    'utilities' => ['label' => 'Other', 'icon' => 'default'],
                ];

                $has_utilities = false;
                foreach ($utility_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    $has_utilities = true;
                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }

                if (!$has_utilities) {
                    echo '<div class="mld-no-data">No utility details available</div>';
                }
                ?>
            </div>
        </div>

        <!-- Financial -->
        <div class="mld-facts-section mld-facts-financial">
            <h3 class="mld-facts-section-title">Financial</h3>
            <div class="mld-facts-list">
                <?php
                $financial_facts = [
                    'tax_annual_amount' => ['label' => 'Annual Tax', 'prefix' => '$', 'icon' => 'tax'],
                    'tax_assessed_value' => ['label' => 'Assessed Value', 'prefix' => '$', 'icon' => 'tax'],
                    'association_yn' => ['label' => 'HOA', 'format' => 'yn', 'icon' => 'hoa'],
                    'association_fee' => ['label' => 'HOA Fee', 'prefix' => '$', 'icon' => 'hoa'],
                    'association_fee_frequency' => ['label' => 'HOA Frequency', 'icon' => 'hoa'],
                ];

                $has_financial = false;
                foreach ($financial_facts as $field => $config) {
                    $value = $listing[$field] ?? null;
                    if (empty($value)) continue;

                    // Skip HOA fields if no association
                    if (in_array($field, ['association_fee', 'association_fee_frequency'])) {
                        if (empty($listing['association_yn']) || $listing['association_yn'] === 'N') {
                            continue;
                        }
                    }

                    $has_financial = true;
                    $formatted_value = mld_format_fact_value($value, $field, $config);
                    ?>
                    <div class="mld-fact-row">
                        <div class="mld-fact-content" style="display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center;">
                            <span class="mld-fact-label"><?php echo esc_html($config['label']); ?></span>
                            <span class="mld-fact-value"><?php echo esc_html($formatted_value); ?></span>
                        </div>
                    </div>
                    <?php
                }

                if (!$has_financial) {
                    echo '<div class="mld-no-data">No financial details available</div>';
                }
                ?>
            </div>
        </div>

    </div>
    <?php
}