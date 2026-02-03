<?php
/**
 * Facts & Features Icons Helper
 * Returns SVG icons for different property features
 */

if (!function_exists('mld_get_feature_icon')) {
    function mld_get_feature_icon($category) {
        $icons = [
            'interior' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',

            'exterior' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>',

            'parking' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"></path><path d="M9 17V7h4a3 3 0 0 1 0 6H9"></path></svg>',

            'utilities' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>',

            'location' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',

            'construction' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>',

            'rooms' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="3" x2="12" y2="21"></line><line x1="3" y1="12" x2="21" y2="12"></line></svg>',

            'lot' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>',

            'association' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',

            'financial' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>',

            'flooring' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>',

            'heating' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"></path><path d="M20 12H4"></path><path d="M20 6H4"></path><path d="M20 18H4"></path></svg>',

            'cooling' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v10m0 10v-10m0 0l3.5-3.5M12 12l-3.5-3.5M12 12l3.5 3.5M12 12l-3.5 3.5"></path><circle cx="12" cy="12" r="3"></circle></svg>',

            'fireplace' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5Z"></path></svg>',

            'appliances' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line></svg>',

            'default' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>'
        ];

        return $icons[$category] ?? $icons['default'];
    }
}

if (!function_exists('mld_get_icon_for_field')) {
    function mld_get_icon_for_field($field) {
        $field_icon_map = [
            // Interior
            'flooring' => 'flooring',
            'heating' => 'heating',
            'heating_yn' => 'heating',
            'cooling' => 'cooling',
            'cooling_yn' => 'cooling',
            'fireplace_features' => 'fireplace',
            'fireplace_yn' => 'fireplace',
            'fireplaces_total' => 'fireplace',
            'appliances' => 'appliances',
            'interior_features' => 'interior',
            'kitchen_features' => 'appliances',
            'laundry_features' => 'appliances',
            'basement_yn' => 'interior',
            'basement' => 'interior',
            'attic_yn' => 'interior',
            'window_features' => 'interior',
            'door_features' => 'interior',
            'insulation' => 'interior',
            'accessibility_features' => 'interior',

            // Exterior
            'exterior_features' => 'exterior',
            'roof' => 'exterior',
            'foundation_details' => 'construction',
            'foundation_area' => 'construction',
            'architectural_style' => 'exterior',
            'property_condition' => 'exterior',
            'construction_materials' => 'construction',
            'siding' => 'exterior',
            'patio_and_porch_features' => 'exterior',
            'pool_features' => 'exterior',
            'pool_private_yn' => 'exterior',
            'spa_features' => 'exterior',
            'spa_yn' => 'exterior',
            'fencing' => 'exterior',
            'fence_yn' => 'exterior',
            'landscaping_features' => 'lot',
            'vegetation' => 'lot',
            'waterfront_yn' => 'location',
            'waterfront_features' => 'location',
            'water_body_name' => 'location',
            'view_yn' => 'location',
            'view_description' => 'location',

            // Parking
            'parking_features' => 'parking',
            'garage_yn' => 'parking',
            'garage_spaces' => 'parking',
            'carport_spaces' => 'parking',
            'carport_yn' => 'parking',
            'parking_total' => 'parking',
            'covered_spaces' => 'parking',
            'open_parking_spaces' => 'parking',
            'open_parking_yn' => 'parking',
            'driveway' => 'parking',

            // Utilities
            'utilities' => 'utilities',
            'water_source' => 'utilities',
            'sewer' => 'utilities',
            'electric' => 'utilities',
            'electric_on_property_yn' => 'utilities',
            'gas' => 'utilities',

            // Location
            'community_features' => 'association',
            'association_yn' => 'association',
            'association_name' => 'association',
            'association_fee' => 'financial',
            'association_fee_frequency' => 'financial',
            'association_fee_includes' => 'association',
            'condo_association' => 'association',
            'master_association' => 'association',

            // Rooms
            'bedroom_features' => 'rooms',
            'master_bedroom_level' => 'rooms',
            'dining_features' => 'rooms',
            'family_room_features' => 'rooms',
            'living_room_features' => 'rooms',
            'recreation_room_features' => 'rooms',
            'den_features' => 'rooms',
            'loft_features' => 'rooms',
            'bathroom_features' => 'rooms',

            // Lot
            'lot_size_square_feet' => 'lot',
            'lot_size_acres' => 'lot',
            'lot_features' => 'lot',
            'land_lease_yn' => 'lot',
            'land_lease_amount' => 'financial',
            'land_lease_expiration_date' => 'lot',

            // Financial
            'tax_assessed_value' => 'financial',
            'tax_annual_amount' => 'financial',
            'tax_year' => 'financial',

            // Default
            'default' => 'default'
        ];

        return mld_get_feature_icon($field_icon_map[$field] ?? 'default');
    }
}