<?php
/**
 * Validation service for exclusive listings
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Validator
 *
 * Validates input data for exclusive listings.
 * Ensures all required fields are present and values are valid.
 */
class EL_Validator {

    /**
     * Valid property types
     * @var array
     */
    const PROPERTY_TYPES = array(
        'Residential',
        'Commercial',
        'Land',
        'Multi-Family',
        'Rental',
    );

    /**
     * MLS-compatible property type aliases
     * Maps MLS format to form format for validation
     * See Pitfall #28 in CLAUDE.md
     * @var array
     */
    const PROPERTY_TYPE_ALIASES = array(
        'Residential Lease'     => 'Rental',
        'Commercial Lease'      => 'Commercial',
        'Commercial Sale'       => 'Commercial',
        'Residential Income'    => 'Multi-Family',
        'Business Opportunity'  => 'Commercial',
    );

    /**
     * Valid property sub-types (form values)
     * @var array
     */
    const PROPERTY_SUB_TYPES = array(
        'Single Family',
        'Condo',
        'Townhouse',
        'Multi-Family',
        'Land',
        'Commercial',
        'Apartment',
        'Mobile Home',
        'Farm',
        'Ranch',
        'Other',
    );

    /**
     * MLS-compatible property sub-type aliases
     * Maps MLS format to form format for validation
     * See Pitfall #28 in CLAUDE.md
     * @var array
     */
    const PROPERTY_SUB_TYPE_ALIASES = array(
        // Common MLS formats
        'Single Family Residence'                   => 'Single Family',
        'Condominium'                               => 'Condo',
        'Multi Family'                              => 'Multi-Family',
        'Attached (Townhouse/Rowhouse/Duplex)'      => 'Townhouse',
        'Duplex'                                    => 'Multi-Family',
        'Condex'                                    => 'Condo',
        'Stock Cooperative'                         => 'Condo',
        // Multi-family variants
        '2 Family - 2 Units Up/Down'                => 'Multi-Family',
        '2 Family - 2 Units Side by Side'           => 'Multi-Family',
        '2 Family - Rooming House'                  => 'Multi-Family',
        '3 Family'                                  => 'Multi-Family',
        '3 Family - 3 Units Up/Down'                => 'Multi-Family',
        '3 Family - 3 Units Side by Side'           => 'Multi-Family',
        '4 Family'                                  => 'Multi-Family',
        '4 Family - 4 Units Up/Down'                => 'Multi-Family',
        '4 Family - 4 Units Side by Side'           => 'Multi-Family',
        '4 Family - Rooming House'                  => 'Multi-Family',
        '5-9 Family'                                => 'Multi-Family',
        '5+ Family - 5+ Units Up/Down'              => 'Multi-Family',
        '5+ Family - 5+ Units Side by Side'         => 'Multi-Family',
        '5+ Family - Rooming House'                 => 'Multi-Family',
        // Land/Agriculture variants
        'Agriculture'                               => 'Farm',
        'Equestrian'                                => 'Farm',
        'Non-Buildable'                             => 'Land',
        // Other
        'Residential'                               => 'Other',
        'Parking'                                   => 'Other',
    );

    /**
     * Valid listing statuses
     * @var array
     */
    const STATUSES = array(
        'Active',
        'Pending',
        'Active Under Contract',
        'Closed',
        'Withdrawn',
        'Expired',
        'Canceled',
    );

    /**
     * Valid architectural styles
     * @var array
     */
    const ARCHITECTURAL_STYLES = array(
        'Colonial',
        'Cape Cod',
        'Ranch',
        'Contemporary',
        'Victorian',
        'Craftsman',
        'Tudor',
        'Mediterranean',
        'Modern',
        'Split-Level',
        'Townhouse',
        'Multi-Family',
        'Other',
    );

    /**
     * Valid heating types
     * @var array
     */
    const HEATING_TYPES = array(
        'Forced Air',
        'Baseboard',
        'Radiant',
        'Heat Pump',
        'Steam',
        'Electric',
        'Oil',
        'Gas',
        'Propane',
        'Wood',
        'Solar',
        'Geothermal',
        'None',
    );

    /**
     * Valid cooling types
     * @var array
     */
    const COOLING_TYPES = array(
        'Central AC',
        'Window Unit',
        'Ductless Mini-Split',
        'Evaporative Cooler',
        'Wall Unit',
        'None',
    );

    /**
     * Valid interior features
     * @var array
     */
    const INTERIOR_FEATURES = array(
        'Hardwood Floors',
        'Crown Molding',
        'Built-ins',
        'Skylights',
        'Vaulted Ceilings',
        'Open Floor Plan',
        'Wet Bar',
        'Home Office',
        'Walk-in Closet',
        'Pantry',
        'Mudroom',
        'Bonus Room',
    );

    /**
     * Valid appliances
     * @var array
     */
    const APPLIANCES = array(
        'Dishwasher',
        'Disposal',
        'Refrigerator',
        'Range/Oven',
        'Microwave',
        'Washer',
        'Dryer',
        'Freezer',
        'Wine Cooler',
        'Trash Compactor',
    );

    /**
     * Valid flooring types
     * @var array
     */
    const FLOORING_TYPES = array(
        'Hardwood',
        'Tile',
        'Carpet',
        'Laminate',
        'Vinyl',
        'Stone',
        'Concrete',
        'Bamboo',
        'Cork',
    );

    /**
     * Valid laundry features
     * @var array
     */
    const LAUNDRY_FEATURES = array(
        'In-Unit',
        'In Building',
        'Hookups Only',
        'Washer Included',
        'Dryer Included',
        'Stacked',
        'Laundry Room',
        'None',
    );

    /**
     * Valid basement types
     * @var array
     */
    const BASEMENT_TYPES = array(
        'Finished',
        'Partially Finished',
        'Unfinished',
        'Walk-Out',
        'Daylight',
        'Crawl Space',
        'Slab',
        'None',
    );

    /**
     * Valid construction materials
     * @var array
     */
    const CONSTRUCTION_MATERIALS = array(
        'Wood Frame',
        'Brick',
        'Stone',
        'Vinyl Siding',
        'Stucco',
        'Concrete',
        'Steel Frame',
        'Log',
        'Adobe',
    );

    /**
     * Valid roof types
     * @var array
     */
    const ROOF_TYPES = array(
        'Shingle',
        'Metal',
        'Slate',
        'Tile',
        'Flat',
        'Rubber',
        'Wood Shake',
        'Composite',
    );

    /**
     * Valid foundation types
     * @var array
     */
    const FOUNDATION_TYPES = array(
        'Concrete',
        'Block',
        'Stone',
        'Brick',
        'Poured',
        'Slab',
        'Pier',
        'Crawl Space',
    );

    /**
     * Valid exterior features
     * @var array
     */
    const EXTERIOR_FEATURES = array(
        'Deck',
        'Patio',
        'Porch',
        'Fence',
        'Pool',
        'Hot Tub',
        'Shed',
        'Garage',
        'Carport',
        'Garden',
        'Sprinkler System',
        'Outdoor Kitchen',
    );

    /**
     * Valid waterfront features
     * @var array
     */
    const WATERFRONT_FEATURES = array(
        'Beach',
        'Dock',
        'Boat Lift',
        'Seawall',
        'Lake',
        'River',
        'Ocean',
        'Pond',
        'Stream',
        'Canal',
    );

    /**
     * Valid view types
     * @var array
     */
    const VIEW_TYPES = array(
        'Water',
        'Ocean',
        'Lake',
        'Mountain',
        'Garden',
        'City',
        'Park',
        'Golf Course',
        'Woods',
        'Panoramic',
    );

    /**
     * Valid parking features
     * @var array
     */
    const PARKING_FEATURES = array(
        'Attached Garage',
        'Detached Garage',
        'Driveway',
        'Off-Street',
        'On-Street',
        'Covered',
        'Carport',
        'Tandem',
        'Underground',
        'Valet',
    );

    /**
     * Valid association fee frequencies
     * @var array
     */
    const ASSOCIATION_FEE_FREQUENCIES = array(
        'Monthly',
        'Quarterly',
        'Semi-Annually',
        'Annually',
    );

    /**
     * Predefined exclusive tag options
     * Agents can also enter custom text (max 50 chars)
     * @var array
     * @since 1.5.0
     */
    const EXCLUSIVE_TAGS = array(
        'Exclusive',
        'Coming Soon',
        'Off-Market',
        'Pocket Listing',
        'Pre-Market',
        'Private',
    );

    /**
     * Maximum length for exclusive_tag field
     * @var int
     * @since 1.5.0
     */
    const EXCLUSIVE_TAG_MAX_LENGTH = 50;

    /**
     * Valid association fee includes
     * @var array
     */
    const ASSOCIATION_FEE_INCLUDES = array(
        'Water',
        'Sewer',
        'Trash',
        'Snow Removal',
        'Landscaping',
        'Insurance',
        'Maintenance',
        'Parking',
        'Pool',
        'Gym',
        'Clubhouse',
        'Security',
    );

    /**
     * Required fields for creating a listing
     * @var array
     */
    const REQUIRED_FIELDS = array(
        'property_type',
        'list_price',
        'street_number',
        'street_name',
        'city',
        'state_or_province',
        'postal_code',
    );

    /**
     * Validate listing data for creation
     *
     * @since 1.0.0
     * @param array $data Input data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validate_create($data) {
        $errors = array();

        // Check required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($data[$field])) {
                $errors[$field] = sprintf('%s is required', self::humanize_field($field));
            }
        }

        // Validate property_type (accept both form values and MLS aliases - see Pitfall #28)
        if (!empty($data['property_type']) && !self::is_valid_property_type($data['property_type'])) {
            $errors['property_type'] = 'Invalid property type. Must be one of: ' . implode(', ', self::PROPERTY_TYPES);
        }

        // Validate property_sub_type (accept both form values and MLS aliases - see Pitfall #28)
        if (!empty($data['property_sub_type']) && !self::is_valid_property_sub_type($data['property_sub_type'])) {
            $errors['property_sub_type'] = 'Invalid property sub-type. Must be one of: ' . implode(', ', self::PROPERTY_SUB_TYPES);
        }

        // Validate status
        $status = self::extract_status($data);
        if (!empty($status) && !in_array($status, self::STATUSES)) {
            $errors['standard_status'] = 'Invalid status. Must be one of: ' . implode(', ', self::STATUSES);
        }

        // Validate price
        if (isset($data['list_price'])) {
            $price = self::sanitize_price($data['list_price']);
            if ($price <= 0) {
                $errors['list_price'] = 'Price must be greater than 0';
            }
        }

        // Validate state (2-letter code)
        if (!empty($data['state_or_province']) && strlen($data['state_or_province']) !== 2) {
            $errors['state_or_province'] = 'State must be a 2-letter code (e.g., MA)';
        }

        // Validate postal code
        if (!empty($data['postal_code']) && !preg_match('/^\d{5}(-\d{4})?$/', $data['postal_code'])) {
            $errors['postal_code'] = 'Invalid postal code format. Use 5-digit or 9-digit (12345 or 12345-6789)';
        }

        // Validate numeric fields
        $numeric_fields = array(
            'bedrooms_total' => 'Bedrooms',
            'bathrooms_total' => 'Bathrooms',
            'building_area_total' => 'Square footage',
            'lot_size_acres' => 'Lot size',
            'year_built' => 'Year built',
            'garage_spaces' => 'Garage spaces',
        );

        foreach ($numeric_fields as $field => $label) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                if (!is_numeric($data[$field]) || $data[$field] < 0) {
                    $errors[$field] = sprintf('%s must be a non-negative number', $label);
                }
            }
        }

        // Validate year_built range
        if (!empty($data['year_built'])) {
            $year = intval($data['year_built']);
            $current_year = intval(wp_date('Y'));
            if ($year < 1600 || $year > $current_year + 5) {
                $errors['year_built'] = sprintf('Year built must be between 1600 and %d', $current_year + 5);
            }
        }

        // Validate coordinates if provided
        $coord_errors = self::validate_coordinates($data);
        $errors = array_merge($errors, $coord_errors);

        // Validate exclusive_tag max length (v1.5.0)
        if (!empty($data['exclusive_tag']) && strlen($data['exclusive_tag']) > self::EXCLUSIVE_TAG_MAX_LENGTH) {
            $errors['exclusive_tag'] = sprintf('Badge text must be %d characters or less', self::EXCLUSIVE_TAG_MAX_LENGTH);
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
        );
    }

    /**
     * Validate listing data for update (partial update allowed)
     *
     * @since 1.0.0
     * @param array $data Input data to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validate_update($data) {
        $errors = array();

        // For updates, we only validate fields that are provided (required fields check is skipped)

        // Validate property_type if provided (accept both form values and MLS aliases - see Pitfall #28)
        if (!empty($data['property_type']) && !self::is_valid_property_type($data['property_type'])) {
            $errors['property_type'] = 'Invalid property type';
        }

        // Validate property_sub_type if provided (accept both form values and MLS aliases - see Pitfall #28)
        if (!empty($data['property_sub_type']) && !self::is_valid_property_sub_type($data['property_sub_type'])) {
            $errors['property_sub_type'] = 'Invalid property sub-type';
        }

        // Validate status if provided
        $status = self::extract_status($data);
        if (!empty($status) && !in_array($status, self::STATUSES)) {
            $errors['standard_status'] = 'Invalid status';
        }

        // Validate price if provided
        if (isset($data['list_price']) && $data['list_price'] !== '') {
            $price = self::sanitize_price($data['list_price']);
            if ($price <= 0) {
                $errors['list_price'] = 'Price must be greater than 0';
            }
        }

        // Validate state if provided
        if (!empty($data['state_or_province']) && strlen($data['state_or_province']) !== 2) {
            $errors['state_or_province'] = 'State must be a 2-letter code';
        }

        // Validate postal code if provided
        if (!empty($data['postal_code']) && !preg_match('/^\d{5}(-\d{4})?$/', $data['postal_code'])) {
            $errors['postal_code'] = 'Invalid postal code format';
        }

        // Validate numeric fields if provided
        $numeric_fields = array('bedrooms_total', 'bathrooms_total', 'building_area_total',
                                'lot_size_acres', 'year_built', 'garage_spaces');

        foreach ($numeric_fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                if (!is_numeric($data[$field]) || $data[$field] < 0) {
                    $errors[$field] = sprintf('%s must be a non-negative number', self::humanize_field($field));
                }
            }
        }

        // Validate coordinates if provided
        $coord_errors = self::validate_coordinates($data);
        $errors = array_merge($errors, $coord_errors);

        // Validate exclusive_tag max length (v1.5.0)
        if (!empty($data['exclusive_tag']) && strlen($data['exclusive_tag']) > self::EXCLUSIVE_TAG_MAX_LENGTH) {
            $errors['exclusive_tag'] = sprintf('Badge text must be %d characters or less', self::EXCLUSIVE_TAG_MAX_LENGTH);
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
        );
    }

    /**
     * Sanitize and normalize listing data
     *
     * @since 1.0.0
     * @since 1.4.0 Added 32 new property detail fields
     * @param array $data Raw input data
     * @return array Sanitized data
     */
    public static function sanitize($data) {
        $sanitized = array();

        // String fields - sanitize and trim
        $string_fields = array(
            'property_type', 'property_sub_type', 'standard_status', 'status',
            'street_number', 'street_name', 'unit_number', 'city',
            'state_or_province', 'postal_code', 'county', 'subdivision_name',
            // Tier 1 - Property Description
            'architectural_style', 'showing_instructions',
            // Tier 2 - Interior Details
            'basement',
            // Tier 3 - Exterior & Lot
            'roof', 'foundation_details',
            // Tier 4 - Financial
            'association_fee_frequency',
            // Custom badge/tag (v1.5.0)
            'exclusive_tag',
        );

        foreach ($string_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_text_field(trim($data[$field]));
            }
        }

        // Normalize status field name
        if (isset($sanitized['status']) && !isset($sanitized['standard_status'])) {
            $sanitized['standard_status'] = $sanitized['status'];
            unset($sanitized['status']);
        }

        // State to uppercase
        if (!empty($sanitized['state_or_province'])) {
            $sanitized['state_or_province'] = strtoupper($sanitized['state_or_province']);
        }

        // Price fields - remove formatting
        $price_fields = array('list_price', 'original_list_price', 'tax_annual_amount', 'association_fee');
        foreach ($price_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = self::sanitize_price($data[$field]);
            }
        }

        // Numeric fields
        $numeric_fields = array(
            'bedrooms_total', 'bathrooms_total', 'bathrooms_full', 'bathrooms_half',
            'building_area_total', 'lot_size_acres', 'year_built', 'garage_spaces',
            // Tier 1 - Property Description
            'stories_total',
            // Tier 3 - Exterior & Lot
            'parking_total',
            // Tier 4 - Financial
            'tax_year',
        );

        foreach ($numeric_fields as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                $sanitized[$field] = is_numeric($data[$field]) ? floatval($data[$field]) : null;
            }
        }

        // Coordinate fields
        if (isset($data['latitude']) && $data['latitude'] !== '') {
            $sanitized['latitude'] = floatval($data['latitude']);
        }
        if (isset($data['longitude']) && $data['longitude'] !== '') {
            $sanitized['longitude'] = floatval($data['longitude']);
        }

        // Boolean fields
        $boolean_fields = array(
            'has_pool', 'has_fireplace', 'has_basement', 'has_hoa', 'pet_friendly',
            'waterfront_yn', 'pool_private_yn', 'spa_yn',
            // Tier 2 - Interior Details (auto-set based on selections)
            'heating_yn', 'cooling_yn',
            // Tier 3 - Exterior & Lot
            'view_yn',
            // Tier 4 - Financial
            'association_yn',
        );

        foreach ($boolean_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = self::to_boolean($data[$field]) ? 1 : 0;
            }
        }

        // Multi-select fields (stored as comma-separated strings)
        $multi_select_fields = array(
            // Tier 2 - Interior Details
            'heating', 'cooling', 'interior_features', 'appliances', 'flooring', 'laundry_features',
            // Tier 3 - Exterior & Lot
            'construction_materials', 'exterior_features', 'waterfront_features', 'view', 'parking_features',
            // Tier 4 - Financial
            'association_fee_includes',
        );

        foreach ($multi_select_fields as $field) {
            if (isset($data[$field])) {
                if (is_array($data[$field])) {
                    // Sanitize each value and join with comma
                    $sanitized[$field] = implode(',', array_map('sanitize_text_field', $data[$field]));
                } else {
                    // Already a string, just sanitize
                    $sanitized[$field] = sanitize_text_field($data[$field]);
                }
            }
        }

        // Auto-set boolean fields based on multi-select values
        if (isset($sanitized['heating']) && !empty($sanitized['heating']) && $sanitized['heating'] !== 'None') {
            $sanitized['heating_yn'] = 1;
        }
        if (isset($sanitized['cooling']) && !empty($sanitized['cooling']) && $sanitized['cooling'] !== 'None') {
            $sanitized['cooling_yn'] = 1;
        }
        if (isset($sanitized['view']) && !empty($sanitized['view'])) {
            $sanitized['view_yn'] = 1;
        }
        if (isset($sanitized['association_fee']) && $sanitized['association_fee'] > 0) {
            $sanitized['association_yn'] = 1;
        }

        // Text fields (allow more content)
        $text_fields = array('public_remarks', 'private_remarks');
        foreach ($text_fields as $field) {
            if (isset($data[$field])) {
                $sanitized[$field] = sanitize_textarea_field($data[$field]);
            }
        }

        // URL fields
        if (isset($data['virtual_tour_url'])) {
            $sanitized['virtual_tour_url'] = esc_url_raw($data['virtual_tour_url']);
        }

        return $sanitized;
    }

    /**
     * Sanitize price value (remove formatting, convert to float)
     *
     * @since 1.0.0
     * @param mixed $price Raw price value
     * @return float Sanitized price
     */
    public static function sanitize_price($price) {
        if (is_numeric($price)) {
            return floatval($price);
        }

        // Remove currency symbols, commas, spaces
        $cleaned = preg_replace('/[^0-9.]/', '', $price);
        return floatval($cleaned);
    }

    /**
     * Convert value to boolean
     *
     * @since 1.0.0
     * @param mixed $value Value to convert
     * @return bool Boolean value
     */
    public static function to_boolean($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, array('true', '1', 'yes', 'on', 'y'));
        }

        return (bool) $value;
    }

    /**
     * Convert snake_case field name to human-readable label
     *
     * @since 1.0.0
     * @param string $field Field name
     * @return string Human-readable label
     */
    private static function humanize_field($field) {
        $labels = array(
            'property_type' => 'Property type',
            'property_sub_type' => 'Property sub-type',
            'standard_status' => 'Status',
            'list_price' => 'Price',
            'street_number' => 'Street number',
            'street_name' => 'Street name',
            'unit_number' => 'Unit number',
            'city' => 'City',
            'state_or_province' => 'State',
            'postal_code' => 'Postal code',
            'bedrooms_total' => 'Bedrooms',
            'bathrooms_total' => 'Bathrooms',
            'building_area_total' => 'Square footage',
            'lot_size_acres' => 'Lot size',
            'year_built' => 'Year built',
            'garage_spaces' => 'Garage spaces',
        );

        return isset($labels[$field]) ? $labels[$field] : ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Check if property type is valid (form value or MLS alias)
     *
     * @param string $value Property type value
     * @return bool True if valid
     */
    private static function is_valid_property_type($value) {
        return in_array($value, self::PROPERTY_TYPES) ||
               array_key_exists($value, self::PROPERTY_TYPE_ALIASES);
    }

    /**
     * Check if property sub-type is valid (form value or MLS alias)
     *
     * @param string $value Property sub-type value
     * @return bool True if valid
     */
    private static function is_valid_property_sub_type($value) {
        return in_array($value, self::PROPERTY_SUB_TYPES) ||
               array_key_exists($value, self::PROPERTY_SUB_TYPE_ALIASES);
    }

    /**
     * Extract status from data array (handles standard_status and status keys)
     *
     * @param array $data Input data
     * @return string|null Status value or null
     */
    private static function extract_status($data) {
        if (isset($data['standard_status'])) {
            return $data['standard_status'];
        }
        if (isset($data['status'])) {
            return $data['status'];
        }
        return null;
    }

    /**
     * Validate latitude and longitude coordinates
     *
     * @param array $data Input data containing latitude and/or longitude
     * @return array Validation errors (empty if valid)
     */
    private static function validate_coordinates($data) {
        $errors = array();

        if (isset($data['latitude']) && $data['latitude'] !== '') {
            $lat = floatval($data['latitude']);
            if ($lat < -90 || $lat > 90) {
                $errors['latitude'] = 'Latitude must be between -90 and 90';
            }
        }

        if (isset($data['longitude']) && $data['longitude'] !== '') {
            $lng = floatval($data['longitude']);
            if ($lng < -180 || $lng > 180) {
                $errors['longitude'] = 'Longitude must be between -180 and 180';
            }
        }

        return $errors;
    }

    /**
     * Get list of valid property types
     *
     * @since 1.0.0
     * @return array Property types
     */
    public static function get_property_types() {
        return self::PROPERTY_TYPES;
    }

    /**
     * Get list of valid property sub-types
     *
     * @since 1.0.0
     * @return array Property sub-types
     */
    public static function get_property_sub_types() {
        return self::PROPERTY_SUB_TYPES;
    }

    /**
     * Get list of valid statuses
     *
     * @since 1.0.0
     * @return array Statuses
     */
    public static function get_statuses() {
        return self::STATUSES;
    }

    /**
     * Get list of required fields
     *
     * @since 1.0.0
     * @return array Required field names
     */
    public static function get_required_fields() {
        return self::REQUIRED_FIELDS;
    }

    /**
     * Get list of valid architectural styles
     *
     * @since 1.4.0
     * @return array Architectural styles
     */
    public static function get_architectural_styles() {
        return self::ARCHITECTURAL_STYLES;
    }

    /**
     * Get list of valid heating types
     *
     * @since 1.4.0
     * @return array Heating types
     */
    public static function get_heating_types() {
        return self::HEATING_TYPES;
    }

    /**
     * Get list of valid cooling types
     *
     * @since 1.4.0
     * @return array Cooling types
     */
    public static function get_cooling_types() {
        return self::COOLING_TYPES;
    }

    /**
     * Get list of valid interior features
     *
     * @since 1.4.0
     * @return array Interior features
     */
    public static function get_interior_features() {
        return self::INTERIOR_FEATURES;
    }

    /**
     * Get list of valid appliances
     *
     * @since 1.4.0
     * @return array Appliances
     */
    public static function get_appliances() {
        return self::APPLIANCES;
    }

    /**
     * Get list of valid flooring types
     *
     * @since 1.4.0
     * @return array Flooring types
     */
    public static function get_flooring_types() {
        return self::FLOORING_TYPES;
    }

    /**
     * Get list of valid laundry features
     *
     * @since 1.4.0
     * @return array Laundry features
     */
    public static function get_laundry_features() {
        return self::LAUNDRY_FEATURES;
    }

    /**
     * Get list of valid basement types
     *
     * @since 1.4.0
     * @return array Basement types
     */
    public static function get_basement_types() {
        return self::BASEMENT_TYPES;
    }

    /**
     * Get list of valid construction materials
     *
     * @since 1.4.0
     * @return array Construction materials
     */
    public static function get_construction_materials() {
        return self::CONSTRUCTION_MATERIALS;
    }

    /**
     * Get list of valid roof types
     *
     * @since 1.4.0
     * @return array Roof types
     */
    public static function get_roof_types() {
        return self::ROOF_TYPES;
    }

    /**
     * Get list of valid foundation types
     *
     * @since 1.4.0
     * @return array Foundation types
     */
    public static function get_foundation_types() {
        return self::FOUNDATION_TYPES;
    }

    /**
     * Get list of valid exterior features
     *
     * @since 1.4.0
     * @return array Exterior features
     */
    public static function get_exterior_features() {
        return self::EXTERIOR_FEATURES;
    }

    /**
     * Get list of valid waterfront features
     *
     * @since 1.4.0
     * @return array Waterfront features
     */
    public static function get_waterfront_features() {
        return self::WATERFRONT_FEATURES;
    }

    /**
     * Get list of valid view types
     *
     * @since 1.4.0
     * @return array View types
     */
    public static function get_view_types() {
        return self::VIEW_TYPES;
    }

    /**
     * Get list of valid parking features
     *
     * @since 1.4.0
     * @return array Parking features
     */
    public static function get_parking_features() {
        return self::PARKING_FEATURES;
    }

    /**
     * Get list of valid association fee frequencies
     *
     * @since 1.4.0
     * @return array Association fee frequencies
     */
    public static function get_association_fee_frequencies() {
        return self::ASSOCIATION_FEE_FREQUENCIES;
    }

    /**
     * Get list of valid association fee includes
     *
     * @since 1.4.0
     * @return array Association fee includes
     */
    public static function get_association_fee_includes() {
        return self::ASSOCIATION_FEE_INCLUDES;
    }

    /**
     * Get list of predefined exclusive tags
     *
     * @since 1.5.0
     * @return array Exclusive tags
     */
    public static function get_exclusive_tags() {
        return self::EXCLUSIVE_TAGS;
    }

    /**
     * Get all field options for iOS/admin forms
     *
     * @since 1.4.0
     * @return array All options organized by category
     */
    public static function get_all_options() {
        return array(
            // Basic fields
            'property_types' => self::PROPERTY_TYPES,
            'property_sub_types' => self::PROPERTY_SUB_TYPES,
            'statuses' => self::STATUSES,

            // Tier 1 - Property Description
            'architectural_styles' => self::ARCHITECTURAL_STYLES,

            // Tier 2 - Interior Details
            'heating_types' => self::HEATING_TYPES,
            'cooling_types' => self::COOLING_TYPES,
            'interior_features' => self::INTERIOR_FEATURES,
            'appliances' => self::APPLIANCES,
            'flooring_types' => self::FLOORING_TYPES,
            'laundry_features' => self::LAUNDRY_FEATURES,
            'basement_types' => self::BASEMENT_TYPES,

            // Tier 3 - Exterior & Lot
            'construction_materials' => self::CONSTRUCTION_MATERIALS,
            'roof_types' => self::ROOF_TYPES,
            'foundation_types' => self::FOUNDATION_TYPES,
            'exterior_features' => self::EXTERIOR_FEATURES,
            'waterfront_features' => self::WATERFRONT_FEATURES,
            'view_types' => self::VIEW_TYPES,
            'parking_features' => self::PARKING_FEATURES,

            // Tier 4 - Financial
            'association_fee_frequencies' => self::ASSOCIATION_FEE_FREQUENCIES,
            'association_fee_includes' => self::ASSOCIATION_FEE_INCLUDES,

            // Custom badge/tag options (v1.5.0)
            'exclusive_tags' => self::EXCLUSIVE_TAGS,
        );
    }

    /**
     * Normalize property type from MLS format to form format
     * Used for displaying correct dropdown selection in admin
     *
     * @since 1.4.3
     * @param string $value The property type value (may be MLS or form format)
     * @return string The normalized form value, or original if no mapping exists
     */
    public static function normalize_property_type($value) {
        if (empty($value)) {
            return '';
        }
        // If it's already a form value, return as-is
        if (in_array($value, self::PROPERTY_TYPES)) {
            return $value;
        }
        // If it's an MLS alias, return the mapped form value
        if (isset(self::PROPERTY_TYPE_ALIASES[$value])) {
            return self::PROPERTY_TYPE_ALIASES[$value];
        }
        // Unknown value, return as-is
        return $value;
    }

    /**
     * Normalize property sub-type from MLS format to form format
     * Used for displaying correct dropdown selection in admin
     *
     * @since 1.4.3
     * @param string $value The property sub-type value (may be MLS or form format)
     * @return string The normalized form value, or original if no mapping exists
     */
    public static function normalize_property_sub_type($value) {
        if (empty($value)) {
            return '';
        }
        // If it's already a form value, return as-is
        if (in_array($value, self::PROPERTY_SUB_TYPES)) {
            return $value;
        }
        // If it's an MLS alias, return the mapped form value
        if (isset(self::PROPERTY_SUB_TYPE_ALIASES[$value])) {
            return self::PROPERTY_SUB_TYPE_ALIASES[$value];
        }
        // Unknown value, return as-is
        return $value;
    }
}
