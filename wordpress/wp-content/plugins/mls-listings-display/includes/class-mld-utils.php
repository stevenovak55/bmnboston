<?php
/**
 * Utility functions for the MLS Listings Display plugin.
 * v7.0.0
 * - REFACTOR: Complete overhaul of the fields array to match the new normalized database schema from BME Pro.
 * - FEAT: Added all new fields from the `listing_details`, `listing_financial`, and `listing_features` tables.
 * - REMOVED: Obsolete JSON-blob fields like `ListAgentData`, `OpenHouseData`, etc.
 */
class MLD_Utils {

    /**
     * Safely decodes a JSON string. Remains useful for fields that are still stored as JSON.
     */
    public static function decode_json($json) {
        if (empty($json) || !is_string($json)) return null;
        $decoded = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    }

    /**
     * Calculate days on market based on property status and dates
     * 
     * @param array $listing The listing data array
     * @return mixed Returns number of days, or string with hours/minutes if less than 1 day, or null if cannot calculate
     */
    public static function calculate_days_on_market($listing) {
        $status = strtolower($listing['standard_status'] ?? '');
        $original_entry = $listing['original_entry_timestamp'] ?? null;
        $off_market_date = $listing['off_market_date'] ?? null;
        
        // If no original entry timestamp, we can't calculate
        if (empty($original_entry)) {
            return null;
        }
        
        $start_timestamp = strtotime($original_entry);
        if ($start_timestamp === false) {
            return null;
        }
        
        // For Active properties: calculate from original_entry_timestamp to now
        if ($status === 'active') {
            $current_timestamp = time();
            $diff_seconds = $current_timestamp - $start_timestamp;
            
            // If less than 24 hours, show hours and minutes
            if ($diff_seconds < 86400) {
                $hours = floor($diff_seconds / 3600);
                $minutes = floor(($diff_seconds % 3600) / 60);
                
                if ($hours > 0) {
                    return sprintf('%d hour%s %d minute%s', 
                        $hours, 
                        $hours != 1 ? 's' : '', 
                        $minutes,
                        $minutes != 1 ? 's' : ''
                    );
                } else {
                    return sprintf('%d minute%s', 
                        $minutes,
                        $minutes != 1 ? 's' : ''
                    );
                }
            }
            
            // Otherwise return days
            return floor($diff_seconds / 86400);
        }
        
        // For Closed, Pending, and Active Under Contract: calculate from original_entry to off_market_date
        if (in_array($status, ['closed', 'pending', 'active under contract'])) {
            if (empty($off_market_date)) {
                // Fallback to close_date for closed properties
                if ($status === 'closed' && !empty($listing['close_date'])) {
                    $off_market_date = $listing['close_date'];
                } else {
                    return null;
                }
            }
            
            $end_timestamp = strtotime($off_market_date);
            if ($end_timestamp === false) {
                return null;
            }
            
            $diff_seconds = $end_timestamp - $start_timestamp;
            return floor($diff_seconds / 86400);
        }
        
        // For other statuses, return null
        return null;
    }

    /**
     * Formats a value for display, handling arrays, booleans, and empty values.
     */
    public static function format_display_value($value, $na_string = 'N/A') {
        // First, check if the value is a string that looks like a JSON array/object
        if (is_string($value) && (strpos(trim($value), '[') === 0 || strpos(trim($value), '{') === 0)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (is_array($value)) {
            $filtered = array_filter($value, fn($item) => $item !== null && trim((string)$item) !== '');
            return empty($filtered) ? $na_string : esc_html(implode(', ', $filtered));
        }

        if (is_bool($value) || (is_numeric($value) && ($value == 1 || $value == 0))) {
            return $value ? 'Yes' : 'No';
        }
        if ($value === null || trim((string)$value) === '' || trim((string)$value) === '[]') {
            return $na_string;
        }
        if (is_string($value)) {
            $lower_value = strtolower(trim($value));
            if ($lower_value === 'yes') return 'Yes';
            if ($lower_value === 'no') return 'No';
        }

        return esc_html(trim((string)$value));
    }

    /**
     * Renders a grid item using the centralized field label.
     */
    public static function render_grid_item($field_id, $value) {
        $label = self::get_field_label($field_id);
        $formatted_value = self::format_display_value($value);

        if ($formatted_value !== 'N/A' && $formatted_value !== '') {
            echo '<div class="mld-grid-item"><strong>' . esc_html($label) . '</strong><span>' . $formatted_value . '</span></div>';
        }
    }

    /**
     * Gets the full categorized array of all field labels.
     * This is the new single source of truth for the property page structure.
     * @return array The categorized fields array.
     */
    public static function get_all_fields_by_category() {
        static $categorized_fields = null;
        if ($categorized_fields === null) {
            $categorized_fields = self::get_fields_array();
        }
        return $categorized_fields;
    }

    /**
     * Gets the display label for a single field ID.
     */
    public static function get_field_label($field_id) {
        $all_fields = self::get_all_fields_by_category();
        foreach ($all_fields as $category) {
            if (isset($category['fields'][$field_id])) {
                return $category['fields'][$field_id];
            }
        }
        // Fallback for any fields not in the main list
        return ucwords(str_replace(['_', 'Yn'], [' ', ''], preg_replace('/(?<!^)[A-Z]/', ' $0', $field_id)));
    }
    
    /**
     * Centralized private function to define the categorized fields array.
     * This has been updated to match the new normalized database schema.
     * @return array The categorized fields array.
     */
    private static function get_fields_array() {
        return [
            'Core Details' => [
                'title' => 'Core Details',
                'fields' => [
                    'StandardStatus' => 'Status',
                    'MlsStatus' => 'MLS Status',
                    'PropertyType' => 'Property Type',
                    'PropertySubType' => 'Property Sub-Type',
                    'BusinessType' => 'Business Type',
                    'ListPrice' => 'List Price',
                    'OriginalListPrice' => 'Original List Price',
                    'ClosePrice' => 'Close Price',
                    'Contingency' => 'Contingency',
                    'PublicRemarks' => 'Public Remarks',
                ]
            ],
            'Location' => [
                'title' => 'Location',
                'fields' => [
                    'UnparsedAddress' => 'Full Address',
                    'StreetNumber' => 'Street Number',
                    'StreetName' => 'Street Name',
                    'UnitNumber' => 'Unit',
                    'City' => 'City',
                    'StateOrProvince' => 'State/Province',
                    'PostalCode' => 'Postal Code',
                    'CountyOrParish' => 'County',
                    'MLSAreaMajor' => 'MLS Area Major',
                    'MLSAreaMinor' => 'MLS Area Minor',
                    'SubdivisionName' => 'Subdivision',
                    'BuildingName' => 'Building Name',
                ]
            ],
            'Property Characteristics' => [
                'title' => 'Property Characteristics',
                'fields' => [
                    'BedroomsTotal' => 'Total Bedrooms',
                    'BathroomsTotalInteger' => 'Total Bathrooms',
                    'BathroomsFull' => 'Full Bathrooms',
                    'BathroomsHalf' => 'Half Bathrooms',
                    'LivingArea' => 'Living Area (SqFt)',
                    'AboveGradeFinishedArea' => 'Above Grade Finished Area',
                    'BelowGradeFinishedArea' => 'Below Grade Finished Area',
                    'BuildingAreaTotal' => 'Total Building Area',
                    'LotSizeAcres' => 'Lot Size (Acres)',
                    'LotSizeSquareFeet' => 'Lot Size (SqFt)',
                    'YearBuilt' => 'Year Built',
                    'YearBuiltEffective' => 'Effective Year Built',
                    'StructureType' => 'Structure Type',
                    'ArchitecturalStyle' => 'Architectural Style',
                    'StoriesTotal' => 'Total Stories',
                    'Levels' => 'Levels',
                    'PropertyAttachedYN' => 'Attached Property',
                    'PropertyCondition' => 'Property Condition',
                    'MLSPIN_MARKET_TIME_PROPERTY' => 'Days on Market',
                    'NumberOfUnitsTotal' => 'Total Units',
                ]
            ],
            'Interior Features' => [
                'title' => 'Interior Features',
                'fields' => [
                    'InteriorFeatures' => 'Interior Features',
                    'Flooring' => 'Flooring',
                    'Appliances' => 'Appliances',
                    'FireplaceFeatures' => 'Fireplace Features',
                    'FireplacesTotal' => 'Total Fireplaces',
                    'FireplaceYN' => 'Fireplace',
                    'RoomsTotal' => 'Total Rooms',
                    'WindowFeatures' => 'Window Features',
                    'DoorFeatures' => 'Door Features',
                    'LaundryFeatures' => 'Laundry Features',
                    'SecurityFeatures' => 'Security Features',
                    'Basement' => 'Basement Details',
                    'EntryLevel' => 'Entry Level',
                    'EntryLocation' => 'Entry Location',
                ]
            ],
            'Exterior & Lot Features' => [
                'title' => 'Exterior & Lot Features',
                'fields' => [
                    'ExteriorFeatures' => 'Exterior Features',
                    'PatioAndPorchFeatures' => 'Patio/Porch Features',
                    'LotFeatures' => 'Lot Features',
                    'RoadSurfaceType' => 'Road Surface',
                    'RoadFrontageType' => 'Road Frontage',
                    'RoadResponsibility' => 'Road Responsibility',
                    'Fencing' => 'Fencing',
                    'OtherStructures' => 'Other Structures',
                    'PoolFeatures' => 'Pool Features',
                    'PoolPrivateYN' => 'Private Pool',
                    'WaterfrontYN' => 'Waterfront',
                    'WaterfrontFeatures' => 'Waterfront Features',
                    'View' => 'View',
                    'ViewYN' => 'Has View',
                    'MLSPIN_WATERVIEW_FLAG' => 'Water View',
                    'MLSPIN_WATERVIEW_FEATURES' => 'Water View Features',
                    'MLSPIN_OUTDOOR_SPACE_AVAILABLE' => 'Outdoor Space Available',
                ]
            ],
            'Construction & Utilities' => [
                'title' => 'Construction & Utilities',
                'fields' => [
                    'ConstructionMaterials' => 'Construction Materials',
                    'FoundationDetails' => 'Foundation Details',
                    'Roof' => 'Roof',
                    'Heating' => 'Heating',
                    'Cooling' => 'Cooling',
                    'CoolingYN' => 'Has Cooling',
                    'Utilities' => 'Utilities',
                    'Sewer' => 'Sewer',
                    'WaterSource' => 'Water Source',
                    'Electric' => 'Electric',
                    'ElectricOnPropertyYN' => 'Electric on Property',
                ]
            ],
            'Parking' => [
                'title' => 'Parking',
                'fields' => [
                    'GarageSpaces' => 'Garage Spaces',
                    'AttachedGarageYN' => 'Attached Garage',
                    'GarageYN' => 'Has Garage',
                    'CoveredSpaces' => 'Covered Spaces',
                    'ParkingTotal' => 'Total Parking Spaces',
                    'ParkingFeatures' => 'Parking Features',
                    'CarportYN' => 'Has Carport',
                ]
            ],
            'Community & HOA' => [
                'title' => 'Community & HOA',
                'fields' => [
                    'CommunityFeatures' => 'Community Features',
                    'AssociationYN' => 'Has HOA',
                    'AssociationFee' => 'HOA Fee',
                    'AssociationFeeFrequency' => 'HOA Fee Frequency',
                    'AssociationAmenities' => 'HOA Amenities',
                    'AssociationFeeIncludes' => 'HOA Fee Includes',
                    'PetsAllowed' => 'Pets Allowed',
                    'SeniorCommunityYN' => 'Senior Community',
                ]
            ],
            'Financial & Tax' => [
                'title' => 'Financial & Tax',
                'fields' => [
                    'TaxAnnualAmount' => 'Annual Tax Amount',
                    'TaxYear' => 'Tax Year',
                    'TaxAssessedValue' => 'Assessed Value',
                    'ParcelNumber' => 'Parcel Number',
                    'Zoning' => 'Zoning',
                    'MLSPIN_DPR_Flag' => 'Down Payment Resource',
                    'MLSPIN_LENDER_OWNED' => 'Lender Owned',
                    'MLSPIN_LEAD_PAINT' => 'Lead Paint',
                    'MLSPIN_TITLE5' => 'Title 5',
                ]
            ],
            'Rental Information' => [
                'title' => 'Rental Information',
                'fields' => [
                    'AvailabilityDate' => 'Available Date',
                    'MLSPIN_AvailableNow' => 'Available Now',
                    'LeaseTerm' => 'Lease Term',
                    'RentIncludes' => 'Rent Includes',
                    'MLSPIN_SEC_DEPOSIT' => 'Security Deposit',
                    'MLSPIN_DEPOSIT_REQD' => 'Deposit Required',
                    'MLSPIN_INSURANCE_REQD' => 'Insurance Required',
                    'MLSPIN_LAST_MON_REQD' => 'Last Month\'s Rent Required',
                    'MLSPIN_FIRST_MON_REQD' => 'First Month\'s Rent Required',
                    'MLSPIN_REFERENCES_REQD' => 'References Required',
                ]
            ],
            'School Information' => [
                'title' => 'School Information',
                'fields' => [
                    'ElementarySchool' => 'Elementary School',
                    'MiddleOrJuniorSchool' => 'Middle School',
                    'HighSchool' => 'High School',
                    'SchoolDistrict' => 'School District',
                ]
            ],
            'Timestamps' => [
                'title' => 'Listing Timestamps',
                'fields' => [
                    'ModificationTimestamp' => 'Last Modified',
                    'StatusChangeTimestamp' => 'Status Change Date',
                    'CloseDate' => 'Close Date',
                    'PurchaseContractDate' => 'Contract Date',
                    'ListingContractDate' => 'Listing Date',
                    'OriginalEntryTimestamp' => 'Original Entry Date',
                    'OffMarketDate' => 'Off Market Date',
                    'ExpirationDate' => 'Expiration Date',
                ]
            ],
            'Admin & Agent Info' => [
                'title' => 'Admin & Agent Info',
                'fields' => [
                    'ListingKey' => 'Listing Key',
                    'ListingId' => 'MLS Listing ID',
                    'PrivateRemarks' => 'Private Remarks',
                    'Disclosures' => 'Disclosures',
                    'ShowingInstructions' => 'Showing Instructions',
                    'ListAgentMlsId' => 'List Agent MLS ID',
                    'BuyerAgentMlsId' => 'Buyer Agent MLS ID',
                    'ListOfficeMlsId' => 'List Office MLS ID',
                    'BuyerOfficeMlsId' => 'Buyer Office MLS ID',
                ]
            ]
        ];
    }
}
