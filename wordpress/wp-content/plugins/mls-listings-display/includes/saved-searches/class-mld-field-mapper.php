<?php
/**
 * MLS Listings Display - Field Name Mapper
 *
 * Maps database snake_case field names to PascalCase for templates
 *
 * DATA FLOW AND NAMING CONVENTIONS:
 * ==================================
 * This class handles the transformation of field names between different layers:
 *
 * 1. DATABASE LAYER (snake_case):
 *    - Bridge MLS Extractor stores data using snake_case: listing_id, list_price, street_number
 *    - MLD_Query retrieves data maintaining snake_case convention
 *
 * 2. PHP PROCESSING LAYER (snake_case):
 *    - Initial processing maintains database naming: $property['listing_id']
 *    - Service layer and repositories use snake_case
 *
 * 3. PRESENTATION LAYER (PascalCase):
 *    - Email templates expect PascalCase: ListingId, ListPrice, StreetNumber
 *    - Frontend JavaScript may receive either format depending on the endpoint
 *
 * IMPORTANT: Code that handles property data should defensively check for both
 * naming conventions using the null coalescing operator:
 *   $id = $property['ListingId'] ?? $property['listing_id'] ?? '';
 *
 * This is NOT a bug but an intentional pattern to handle data at different stages
 * of processing. Always transform data using this class before sending emails or
 * displaying to users.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Field_Mapper {

    /**
     * Filter to database column mapping
     * Maps frontend filter names to database column names for saved searches
     *
     * @since 4.3.0
     */
    private static $filter_to_column_map = [
        // Price filters
        'price_min' => 'list_price',
        'price_max' => 'list_price',

        // Property characteristics
        'beds' => 'bedrooms_total',
        'baths_min' => 'bathrooms_full',
        'garage_spaces_min' => 'garage_spaces',
        'parking_total_min' => 'parking_spaces_total',

        // Property types and status
        'home_type' => 'property_sub_type',
        'status' => 'standard_status',
        'property_type' => 'property_type',

        // Size and age
        'sqft_min' => 'building_area_total',
        'sqft_max' => 'building_area_total',
        'year_built_min' => 'year_built',
        'year_built_max' => 'year_built',
        'lot_size_min' => 'lot_size_area',
        'lot_size_max' => 'lot_size_area',

        // Boolean features (Y/N fields)
        'WaterfrontYN' => 'waterfront_yn',
        'ViewYN' => 'view_yn',
        'SpaYN' => 'spa_yn',
        'PropertyAttachedYN' => 'property_attached_yn',

        // MLSPIN specific fields
        'MLSPIN_WATERVIEW_FLAG' => 'mlspin_waterview_flag',
        'MLSPIN_LENDER_OWNED' => 'mlspin_lender_owned',
        'MLSPIN_AvailableNow' => 'mlspin_available_now',

        // Structure and style
        'structure_type' => 'structure_type',
        'architectural_style' => 'architectural_style',

        // Other
        'available_by' => 'available_date',
        'open_house_only' => 'open_house_flag',
        'entry_level_min' => 'entry_level',
        'entry_level_max' => 'entry_level',
    ];

    /**
     * Field mapping from snake_case to PascalCase
     */
    private static $field_map = [
        // Basic property info
        'listing_id' => 'ListingId',
        'list_price' => 'ListPrice',
        'close_price' => 'ClosePrice',
        'standard_status' => 'StandardStatus',
        'property_type' => 'PropertyType',
        'property_sub_type' => 'PropertySubType',
        'listing_key' => 'ListingKey',
        
        // Address fields
        'unparsed_address' => 'UnparsedAddress',
        'street_number' => 'StreetNumber',
        'street_name' => 'StreetName',
        'street_suffix' => 'StreetSuffix',
        'unit_number' => 'UnitNumber',
        'city' => 'City',
        'state_or_province' => 'StateOrProvince',
        'postal_code' => 'PostalCode',
        'county_or_parish' => 'CountyOrParish',
        
        // Property details
        'bedrooms_total' => 'BedroomsTotal',
        'bathrooms_total_integer' => 'BathroomsTotalInteger',
        'bathrooms_full' => 'BathroomsFull',
        'bathrooms_half' => 'BathroomsHalf',
        'living_area' => 'LivingArea',
        'lot_size_area' => 'LotSizeArea',
        'year_built' => 'YearBuilt',
        'garage_spaces' => 'GarageSpaces',
        'parking_total' => 'ParkingTotal',
        
        // Listing details
        'public_remarks' => 'PublicRemarks',
        'private_remarks' => 'PrivateRemarks',
        'days_on_market' => 'DaysOnMarket',
        'listing_date' => 'ListingDate',
        'modification_timestamp' => 'ModificationTimestamp',
        'status_change_timestamp' => 'StatusChangeTimestamp',
        'close_date' => 'CloseDate',
        'expiration_date' => 'ExpirationDate',
        
        // Agent/Office
        'list_agent_mls_id' => 'ListAgentMlsId',
        'list_agent_full_name' => 'ListAgentFullName',
        'list_office_mls_id' => 'ListOfficeMlsId',
        'list_office_name' => 'ListOfficeName',
        
        // Location
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        
        // Additional fields
        'property_style' => 'PropertyStyle',
        'stories_total' => 'StoriesTotal',
        'basement_yn' => 'BasementYN',
        'fireplace_yn' => 'FireplaceYN',
        'pool_private_yn' => 'PoolPrivateYN',
        'waterfront_yn' => 'WaterfrontYN',
        'hoa_fee' => 'HoaFee',
        'hoa_fee_frequency' => 'HoaFeeFrequency',
        'tax_annual_amount' => 'TaxAnnualAmount',
        'tax_year' => 'TaxYear',
        
        // Virtual tour fields
        'virtual_tour_url' => 'VirtualTourUrl',
        'virtual_tour_url2' => 'VirtualTourUrl2',
        
        // Media (commonly added)
        'photo_url' => 'PhotoUrl',
        'photo_count' => 'PhotoCount'
    ];
    
    /**
     * Map a single property array from snake_case to PascalCase
     * 
     * @param array $property Property data with snake_case keys
     * @return array Property data with PascalCase keys
     */
    public static function map_property_fields($property) {
        if (!is_array($property)) {
            return $property;
        }
        
        $mapped = [];
        
        foreach ($property as $key => $value) {
            // Check if we have a mapping for this field
            if (isset(self::$field_map[$key])) {
                $mapped[self::$field_map[$key]] = $value;
            } else {
                // Keep original key if no mapping exists
                $mapped[$key] = $value;
            }
        }
        
        // Handle special cases
        
        // Price field - use close_price for closed listings, list_price otherwise
        if (isset($property['standard_status']) && $property['standard_status'] === 'Closed') {
            if (isset($property['close_price'])) {
                $mapped['ListPrice'] = $property['close_price'];
            }
        }
        
        // Full address fallback
        if (empty($mapped['UnparsedAddress']) && !empty($mapped['StreetNumber'])) {
            $mapped['UnparsedAddress'] = self::build_address($mapped);
        }
        
        // Ensure critical fields have defaults
        $mapped['ListingId'] = $mapped['ListingId'] ?? $property['listing_id'] ?? '';
        $mapped['ListPrice'] = $mapped['ListPrice'] ?? 0;
        
        return $mapped;
    }
    
    /**
     * Map multiple properties
     * 
     * @param array $properties Array of properties
     * @return array Mapped properties
     */
    public static function map_properties($properties) {
        return array_map([self::class, 'map_property_fields'], $properties);
    }
    
    /**
     * Build full address from components
     * 
     * @param array $property Property data
     * @return string Full address
     */
    private static function build_address($property) {
        $parts = [];
        
        if (!empty($property['StreetNumber'])) {
            $parts[] = $property['StreetNumber'];
        }
        
        if (!empty($property['StreetName'])) {
            $parts[] = $property['StreetName'];
        }
        
        if (!empty($property['StreetSuffix'])) {
            $parts[] = $property['StreetSuffix'];
        }
        
        if (!empty($property['UnitNumber'])) {
            $parts[] = '#' . $property['UnitNumber'];
        }
        
        $street = implode(' ', $parts);
        
        $address_parts = array_filter([
            $street,
            $property['City'] ?? '',
            $property['StateOrProvince'] ?? '',
            $property['PostalCode'] ?? ''
        ]);
        
        return implode(', ', $address_parts);
    }
    
    /**
     * Get field mapping array
     * 
     * @return array Field mapping
     */
    public static function get_field_map() {
        return self::$field_map;
    }
    
    /**
     * Add custom field mapping
     * 
     * @param string $snake_case Snake case field name
     * @param string $pascal_case Pascal case field name
     */
    public static function add_field_mapping($snake_case, $pascal_case) {
        self::$field_map[$snake_case] = $pascal_case;
    }
    
    /**
     * Map field name from snake_case to PascalCase
     * 
     * @param string $field_name Field name in snake_case
     * @return string Field name in PascalCase
     */
    public static function map_field_name($field_name) {
        return self::$field_map[$field_name] ?? $field_name;
    }
    
    /**
     * Reverse map from PascalCase to snake_case
     *
     * @param string $field_name Field name in PascalCase
     * @return string Field name in snake_case
     */
    public static function reverse_map_field_name($field_name) {
        $reverse_map = array_flip(self::$field_map);
        return $reverse_map[$field_name] ?? $field_name;
    }

    /**
     * Map filter name to database column
     *
     * @param string $filter_name Frontend filter name
     * @return string Database column name
     * @since 4.3.0
     */
    public static function map_filter_to_column($filter_name) {
        return self::$filter_to_column_map[$filter_name] ?? $filter_name;
    }

    /**
     * Map multiple filters to database columns
     *
     * @param array $filters Array of filters from frontend
     * @return array Mapped filters for database query
     * @since 4.3.0
     */
    public static function map_filters_to_columns($filters) {
        $mapped = [];

        foreach ($filters as $filter_name => $value) {
            $column_name = self::map_filter_to_column($filter_name);
            $mapped[$column_name] = $value;
        }

        return $mapped;
    }

    /**
     * Get filter to column mapping array
     *
     * @return array Filter to column mapping
     * @since 4.3.0
     */
    public static function get_filter_column_map() {
        return self::$filter_to_column_map;
    }

    /**
     * Validate if a filter exists in the mapping
     *
     * @param string $filter_name Filter name to check
     * @return bool True if filter is mapped
     * @since 4.3.0
     */
    public static function is_valid_filter($filter_name) {
        return isset(self::$filter_to_column_map[$filter_name]);
    }


    /**
     * Get the database table for a given column
     * Used to determine which table to JOIN when filtering
     *
     * @param string $column_name Database column name
     * @return string Table identifier
     * @since 5.2.0
     */
    public static function get_table_for_column($column_name) {
        $column_to_table = [
            // Main listings table
            'listing_id' => 'listings',
            'list_price' => 'listings',
            'standard_status' => 'listings',
            'property_type' => 'listings',
            'property_sub_type' => 'listings',
            'listing_contract_date' => 'listings',
            'close_date' => 'listings',
            'modification_timestamp' => 'listings',

            // Location table
            'city' => 'listing_location',
            'state_or_province' => 'listing_location',
            'postal_code' => 'listing_location',
            'county' => 'listing_location',
            'latitude' => 'listing_location',
            'longitude' => 'listing_location',
            'street_number' => 'listing_location',
            'street_name' => 'listing_location',
            'unit_number' => 'listing_location',

            // Details table
            'bedrooms_total' => 'listing_details',
            'bathrooms_total_integer' => 'listing_details',
            'bathrooms_full' => 'listing_details',
            'bathrooms_half' => 'listing_details',
            'building_area_total' => 'listing_details',
            'living_area' => 'listing_details',
            'lot_size_acres' => 'listing_details',
            'year_built' => 'listing_details',
            'garage_spaces' => 'listing_details',
            'parking_spaces_total' => 'listing_details',

            // Financial table
            'association_fee' => 'listing_financial',
            'association_fee_frequency' => 'listing_financial',
            'tax_annual_amount' => 'listing_financial',
            'tax_assessed_value' => 'listing_financial',

            // Features table
            'waterfront_yn' => 'listing_features',
            'pool_private_yn' => 'listing_features',
            'view_yn' => 'listing_features',
            'spa_yn' => 'listing_features',
            'property_attached_yn' => 'listing_features',
        ];

        return $column_to_table[$column_name] ?? 'listings';
    }

}