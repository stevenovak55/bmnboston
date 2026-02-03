<?php
/**
 * Modern Facts & Features Icons - Cleaner, Minimal Design
 * Inspired by Zillow, Redfin, and modern UI patterns
 */

if (!function_exists('mld_get_feature_icon_v2')) {
    function mld_get_feature_icon_v2($category) {
        $icons = [
            // Primary home features - simple, recognizable icons
            'beds' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 13h18v8H3z"/><path d="M3 13v-2a2 2 0 012-2h14a2 2 0 012 2v2"/><path d="M7 9V6a2 2 0 012-2h6a2 2 0 012 2v3"/></svg>',

            'baths' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M7 12h10a4 4 0 014 4v3a1 1 0 01-1 1H4a1 1 0 01-1-1v-3a4 4 0 014-4z"/><path d="M7 12V5a2 2 0 012-2h6a2 2 0 012 2v7"/><path d="M12 12v8"/></svg>',

            'sqft' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16"/><path d="M9 4v16"/><path d="M15 4v16"/><path d="M4 9h16"/><path d="M4 15h16"/></svg>',

            'garage' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 11l9-7 9 7v9a2 2 0 01-2 2H5a2 2 0 01-2-2v-9z"/><rect x="7" y="13" width="10" height="6"/><path d="M7 16h10"/></svg>',

            'lot' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="8" width="18" height="12"/><path d="M3 8l9-5 9 5"/><circle cx="12" cy="14" r="2"/></svg>',

            'year' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',

            // Interior features
            'kitchen' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16"/><rect x="8" y="8" width="8" height="8"/><path d="M8 4v4"/><path d="M16 4v4"/></svg>',

            'heating' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2v20M8 5l4-3 4 3M8 12l4-3 4 3M8 19l4-3 4 3"/></svg>',

            'cooling' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="7" width="18" height="10" rx="1"/><path d="M7 7v10M11 7v10M15 7v10M19 7v10"/></svg>',

            'flooring' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 18h16M4 6h16"/><rect x="7" y="6" width="4" height="12"/><rect x="13" y="6" width="4" height="12"/></svg>',

            'appliances' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16" rx="1"/><circle cx="12" cy="12" r="3"/><path d="M12 4v2"/></svg>',

            // Exterior features
            'exterior' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 11l9-7 9 7M5 10v10a1 1 0 001 1h12a1 1 0 001-1V10"/></svg>',

            'pool' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><ellipse cx="12" cy="12" rx="9" ry="5"/><path d="M3 12v4c0 2.76 4.03 5 9 5s9-2.24 9-5v-4"/></svg>',

            'view' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 11l6-8 6 10 6-8 6 8"/><path d="M3 17h18v4H3z"/></svg>',

            'fence' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 8v12M8 8v12M12 8v12M16 8v12M20 8v12M4 12h16"/></svg>',

            'waterfront' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 16c2.5-2.5 5.5-2.5 8 0s5.5 2.5 8 0M3 20c2.5-2.5 5.5-2.5 8 0s5.5 2.5 8 0"/><circle cx="12" cy="8" r="3"/></svg>',

            // Property info
            'location' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>',

            'hoa' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l2 7h7l-5.5 4 2 7L12 16l-5.5 4 2-7L3 9h7l2-7z"/></svg>',

            'price' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 10h6"/></svg>',

            'tax' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h6M9 11h6M9 15h4"/></svg>',

            // Utilities
            'electric' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>',

            'water' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/></svg>',

            'sewer' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 17v4h18v-4M5 17v-7a7 7 0 0114 0v7"/></svg>',

            'gas' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2c-3 0-6 3-6 7 0 2 0 4 1 5l5 7 5-7c1-1 1-3 1-5 0-4-3-7-6-7z"/><circle cx="12" cy="9" r="2"/></svg>',

            // Rooms
            'rooms' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18"/><path d="M3 12h18M12 3v18"/></svg>',

            'primary' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="10" width="18" height="11"/><path d="M5 10V7a5 5 0 0110 0v3"/></svg>',

            // Default
            'default' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>',

            // Status indicators
            'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 6L9 17l-5-5"/></svg>',

            'star' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',

            'new' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2v6m0 4v6m0 4h.01M17 12h-10"/></svg>'
        ];

        return $icons[$category] ?? $icons['default'];
    }
}

if (!function_exists('mld_get_field_icon_v2')) {
    function mld_get_field_icon_v2($field) {
        // Map fields to appropriate icons
        $icon_map = [
            // Bedrooms
            'bedrooms_total' => 'beds',
            'bedrooms_above_grade' => 'beds',
            'bedrooms_below_grade' => 'beds',
            'master_bedroom_level' => 'primary',
            'main_level_bedrooms' => 'beds',

            // Bathrooms
            'bathrooms_full' => 'baths',
            'bathrooms_half' => 'baths',
            'bathrooms_total' => 'baths',
            'bathrooms_total_integer' => 'baths',
            'main_level_bathrooms' => 'baths',

            // Square footage
            'living_area' => 'sqft',
            'above_grade_finished_area' => 'sqft',
            'below_grade_finished_area' => 'sqft',
            'gross_area' => 'sqft',

            // Garage/Parking
            'garage_spaces' => 'garage',
            'garage_yn' => 'garage',
            'carport_spaces' => 'garage',
            'parking_total' => 'garage',
            'parking_features' => 'garage',

            // Lot
            'lot_size_acres' => 'lot',
            'lot_size_square_feet' => 'lot',
            'lot_features' => 'lot',

            // Year
            'year_built' => 'year',
            'year_built_effective' => 'year',

            // Kitchen
            'appliances' => 'appliances',
            'kitchen_features' => 'kitchen',

            // HVAC
            'heating' => 'heating',
            'heating_yn' => 'heating',
            'cooling' => 'cooling',
            'cooling_yn' => 'cooling',

            // Flooring
            'flooring' => 'flooring',

            // Pool
            'pool_features' => 'pool',
            'pool_private_yn' => 'pool',
            'spa_features' => 'pool',
            'spa_yn' => 'pool',

            // View
            'view' => 'view',
            'view_yn' => 'view',
            'view_description' => 'view',

            // Water
            'waterfront_yn' => 'waterfront',
            'waterfront_features' => 'waterfront',
            'water_body_name' => 'waterfront',

            // Location
            'community_features' => 'location',
            'neighborhood' => 'location',
            'subdivision_name' => 'location',

            // HOA
            'association_yn' => 'hoa',
            'association_fee' => 'hoa',
            'association_name' => 'hoa',

            // Financial
            'list_price' => 'price',
            'tax_annual_amount' => 'tax',
            'tax_assessed_value' => 'tax',

            // Utilities
            'electric' => 'electric',
            'electric_on_property_yn' => 'electric',
            'water_source' => 'water',
            'sewer' => 'sewer',
            'gas' => 'gas',

            // Rooms
            'rooms_total' => 'rooms',
            'room_type' => 'rooms',

            // Fence
            'fencing' => 'fence',
            'fence_yn' => 'fence',

            // Exterior
            'exterior_features' => 'exterior',
            'construction_materials' => 'exterior',
            'architectural_style' => 'exterior',
            'roof' => 'exterior'
        ];

        $icon_name = $icon_map[$field] ?? 'default';
        return mld_get_feature_icon_v2($icon_name);
    }
}

if (!function_exists('mld_format_fact_value')) {
    function mld_format_fact_value($value, $field = '', $config = []) {
        // Handle JSON strings that look like arrays
        if (is_string($value) && (strpos($value, '[') === 0 || strpos($value, '{') === 0)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        // Handle arrays - properly format them
        if (is_array($value)) {
            // Filter out empty values
            $filtered = array_filter($value, function($item) {
                return !empty($item) && $item !== '';
            });

            if (empty($filtered)) {
                return 'None';
            }

            // Clean up individual items
            $cleaned = array_map(function($item) {
                // Remove any quotes that might be in the string
                $item = str_replace(['"', "'"], '', $item);
                // Capitalize first letter of each word
                return ucwords(strtolower(trim($item)));
            }, $filtered);

            // Join with commas
            return implode(', ', $cleaned);
        }

        // Handle Y/N values
        if (isset($config['format']) && $config['format'] === 'yn') {
            return ($value === 'Y' || $value === '1') ? 'Yes' : 'No';
        }

        // Handle numeric values with formatting
        if (is_numeric($value) && $value > 0) {
            if (isset($config['prefix'])) {
                return $config['prefix'] . number_format($value);
            }
            if (isset($config['suffix'])) {
                $decimals = in_array($field, ['lot_size_acres']) ? 2 : 0;
                return number_format($value, $decimals) . $config['suffix'];
            }
            if (in_array($field, ['living_area', 'lot_size_square_feet', 'above_grade_finished_area'])) {
                return number_format($value) . ' sq ft';
            }
            if ($field === 'lot_size_acres') {
                return number_format($value, 2) . ' acres';
            }
        }

        // Handle dates
        if (isset($config['format']) && $config['format'] === 'date' && !empty($value)) {
            return date('M j, Y', strtotime($value));
        }

        // Clean up string values
        if (is_string($value)) {
            // Remove brackets and quotes if they exist
            $value = str_replace(['[', ']', '"', "'"], '', $value);
            $value = trim($value);
        }

        return !empty($value) ? $value : 'None';
    }
}