<?php
/**
 * MLS Listings Display - Enhanced Filter Matcher
 *
 * Matches listings against saved search filters with support for all 45+ filter types.
 * Uses early-exit pattern for performance optimization.
 *
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 6.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Enhanced_Filter_Matcher {

    /**
     * Match a listing against saved search filters
     *
     * Supports 50+ filter types including:
     * - Price range (price_min, price_max)
     * - Bedrooms/Bathrooms (beds[], beds_min, baths_min)
     * - Location (City, selected_cities, Neighborhood, PostalCode, county)
     * - Property type (home_type, property_types, PropertyType, PropertySubType)
     * - Structure type (structure_type, architectural_style)
     * - Size (sqft_min/max, lot_size_min/max)
     * - Year built (year_built_min/max)
     * - Parking (garage_spaces_min, parking_total_min)
     * - Agent (list_agent_mls_id, buyer_agent_mls_id, list_office_mls_id)
     * - Days on market (dom_min, dom_max)
     * - Status (status[])
     * - Boolean features (waterfront, pool, fireplace, basement, HOA, pets, etc.)
     * - Polygon boundaries
     *
     * @param array $listing Listing data
     * @param array|object $search Saved search object or array with filters and polygon_shapes
     * @return bool True if listing matches all criteria
     */
    public static function matches($listing, $search) {
        // Handle both object and array input
        $filters_raw = is_array($search) ? ($search['filters'] ?? null) : ($search->filters ?? null);
        $filters = is_array($filters_raw) ? $filters_raw : json_decode($filters_raw, true);

        // Empty filters means match all
        if (empty($filters)) {
            return true;
        }

        // Price range (most common - check first)
        if (!self::matches_price($listing, $filters)) {
            return false;
        }

        // Bedrooms/Bathrooms
        if (!self::matches_rooms($listing, $filters)) {
            return false;
        }

        // Location filters (city, neighborhood, zip, county)
        if (!self::matches_location($listing, $filters)) {
            return false;
        }

        // Property type and sub-type
        if (!self::matches_property_type($listing, $filters)) {
            return false;
        }

        // Structure type and architectural style
        if (!self::matches_structure($listing, $filters)) {
            return false;
        }

        // Size (sqft, lot size)
        if (!self::matches_size($listing, $filters)) {
            return false;
        }

        // Year built
        if (!self::matches_year_built($listing, $filters)) {
            return false;
        }

        // Parking (garage, total)
        if (!self::matches_parking($listing, $filters)) {
            return false;
        }

        // Agent/Office filters
        if (!self::matches_agent($listing, $filters)) {
            return false;
        }

        // Days on market
        if (!self::matches_days_on_market($listing, $filters)) {
            return false;
        }

        // Status filter
        if (!self::matches_status($listing, $filters)) {
            return false;
        }

        // Boolean features (waterfront, pool, etc.)
        if (!self::matches_features($listing, $filters)) {
            return false;
        }

        // v6.60.0: Rental-specific filters (pets allowed, laundry, lease term)
        if (!self::matches_rental_filters($listing, $filters)) {
            return false;
        }

        // v6.67.4: School quality filters (requires BMN Schools integration)
        if (!self::matches_school_criteria($listing, $filters)) {
            return false;
        }

        // Polygon boundaries (most expensive - check last)
        if (!self::matches_polygon($listing, $search)) {
            return false;
        }

        return true;
    }

    /**
     * Price range matching
     */
    private static function matches_price($listing, $filters) {
        $price = self::get_value($listing, ['list_price', 'ListPrice']);
        
        if (!empty($filters['price_min']) && $price < floatval($filters['price_min'])) {
            return false;
        }
        
        if (!empty($filters['price_max']) && $price > floatval($filters['price_max'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Bedrooms/Bathrooms matching
     */
    private static function matches_rooms($listing, $filters) {
        $beds = intval(self::get_value($listing, ['bedrooms_total', 'BedroomsTotal']));
        $baths = floatval(self::get_value($listing, ['bathrooms_total', 'BathroomsTotalInteger']));

        // beds - handle both array format (web) and single integer format (iOS)
        if (!empty($filters['beds'])) {
            if (is_array($filters['beds'])) {
                // Array format: specific bedroom counts like [2, 3, 4]
                $matched = false;
                foreach ($filters['beds'] as $bed_filter) {
                    // Handle "5+" format
                    if (strpos($bed_filter, '+') !== false) {
                        $min_beds = intval($bed_filter);
                        if ($beds >= $min_beds) {
                            $matched = true;
                            break;
                        }
                    } else {
                        if ($beds == intval($bed_filter)) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched) {
                    return false;
                }
            } else {
                // Single integer format (iOS): minimum beds threshold
                if ($beds < intval($filters['beds'])) {
                    return false;
                }
            }
        }

        // beds_min (alternative key)
        if (!empty($filters['beds_min']) && $beds < intval($filters['beds_min'])) {
            return false;
        }

        // baths_min
        if (!empty($filters['baths_min']) && $baths < floatval($filters['baths_min'])) {
            return false;
        }

        return true;
    }

    /**
     * Location matching (city, neighborhood, zip)
     * Note: Filters are skipped if listing data doesn't contain the field (graceful degradation)
     */
    private static function matches_location($listing, $filters) {
        // City matching (available in summary table)
        $city_filters = [];
        if (!empty($filters['selected_cities'])) {
            $city_filters = array_merge($city_filters, (array)$filters['selected_cities']);
        }
        if (!empty($filters['City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['City']);
        }
        if (!empty($filters['keyword_City'])) {
            $city_filters = array_merge($city_filters, (array)$filters['keyword_City']);
        }

        if (!empty($city_filters)) {
            $listing_city = self::get_value($listing, ['city', 'City']);
            if ($listing_city !== null) {
                $city_filters = array_map('strtolower', $city_filters);
                if (!in_array(strtolower($listing_city), $city_filters)) {
                    return false;
                }
            }
        }

        // Neighborhood matching (NOT in summary table - skip if unavailable)
        $neighborhood_filters = [];
        if (!empty($filters['selected_neighborhoods'])) {
            $neighborhood_filters = array_merge($neighborhood_filters, (array)$filters['selected_neighborhoods']);
        }
        if (!empty($filters['Neighborhood'])) {
            $neighborhood_filters = array_merge($neighborhood_filters, (array)$filters['Neighborhood']);
        }
        if (!empty($filters['neighborhood'])) {
            // iOS uses lowercase 'neighborhood'
            $neighborhood_filters = array_merge($neighborhood_filters, (array)$filters['neighborhood']);
        }
        if (!empty($filters['keyword_Neighborhood'])) {
            $neighborhood_filters = array_merge($neighborhood_filters, (array)$filters['keyword_Neighborhood']);
        }

        if (!empty($neighborhood_filters)) {
            $listing_neighborhood = self::get_value($listing, ['subdivision_name', 'Subdivision', 'mls_area_major', 'mls_area_minor']);
            // Skip filter if data not available
            if ($listing_neighborhood !== null) {
                $neighborhood_filters = array_map('strtolower', $neighborhood_filters);
                if (!in_array(strtolower($listing_neighborhood), $neighborhood_filters)) {
                    return false;
                }
            }
        }

        // Zip code matching (available in summary table)
        // iOS uses 'zip', web uses 'PostalCode' or 'keyword_PostalCode'
        if (!empty($filters['PostalCode']) || !empty($filters['keyword_PostalCode']) || !empty($filters['zip'])) {
            $listing_zip = self::get_value($listing, ['postal_code', 'PostalCode']);
            if ($listing_zip !== null) {
                $zip_filters = array_merge(
                    (array)($filters['PostalCode'] ?? []),
                    (array)($filters['keyword_PostalCode'] ?? []),
                    (array)($filters['zip'] ?? [])
                );
                if (!in_array($listing_zip, $zip_filters)) {
                    return false;
                }
            }
        }

        // County matching (available in summary table)
        $county_filters = [];
        if (!empty($filters['county'])) {
            $county_filters = array_merge($county_filters, (array)$filters['county']);
        }
        if (!empty($filters['County'])) {
            $county_filters = array_merge($county_filters, (array)$filters['County']);
        }
        if (!empty($filters['selected_counties'])) {
            $county_filters = array_merge($county_filters, (array)$filters['selected_counties']);
        }

        if (!empty($county_filters)) {
            $listing_county = self::get_value($listing, ['county', 'County', 'CountyOrParish']);
            if ($listing_county !== null) {
                $county_filters = array_map('strtolower', $county_filters);
                if (!in_array(strtolower($listing_county), $county_filters)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Property type matching (includes property type, sub-type, and home type)
     */
    private static function matches_property_type($listing, $filters) {
        // Property Type (Residential, Commercial, Land, etc.)
        $property_type_filters = [];
        if (!empty($filters['PropertyType'])) {
            $property_type_filters = array_merge($property_type_filters, (array)$filters['PropertyType']);
        }
        if (!empty($filters['property_type'])) {
            $property_type_filters = array_merge($property_type_filters, (array)$filters['property_type']);
        }

        if (!empty($property_type_filters)) {
            $listing_property_type = self::get_value($listing, ['property_type', 'PropertyType']);
            $property_type_filters = array_unique(array_map('strtolower', $property_type_filters));
            if (!in_array(strtolower($listing_property_type ?? ''), $property_type_filters)) {
                return false;
            }
        }

        // Property Sub-Type / Home Type (Single Family, Condo, Townhouse, etc.)
        $sub_type_filters = [];
        if (!empty($filters['home_type'])) {
            $sub_type_filters = array_merge($sub_type_filters, (array)$filters['home_type']);
        }
        if (!empty($filters['property_types'])) {
            $sub_type_filters = array_merge($sub_type_filters, (array)$filters['property_types']);
        }
        if (!empty($filters['PropertySubType'])) {
            $sub_type_filters = array_merge($sub_type_filters, (array)$filters['PropertySubType']);
        }
        if (!empty($filters['property_sub_type'])) {
            $sub_type_filters = array_merge($sub_type_filters, (array)$filters['property_sub_type']);
        }

        if (!empty($sub_type_filters)) {
            $listing_sub_type = self::get_value($listing, ['property_sub_type', 'PropertySubType']);
            $sub_type_filters = array_unique(array_map('strtolower', $sub_type_filters));
            if (!in_array(strtolower($listing_sub_type ?? ''), $sub_type_filters)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Size matching (sqft, lot size)
     */
    private static function matches_size($listing, $filters) {
        // Square footage
        $sqft = floatval(self::get_value($listing, ['building_area_total', 'LivingArea']));
        
        if (!empty($filters['sqft_min']) && $sqft > 0 && $sqft < floatval($filters['sqft_min'])) {
            return false;
        }
        if (!empty($filters['sqft_max']) && $sqft > floatval($filters['sqft_max'])) {
            return false;
        }

        // Lot size
        $lot_size = floatval(self::get_value($listing, ['lot_size_acres', 'LotSizeAcres']));
        
        if (!empty($filters['lot_size_min']) && $lot_size > 0 && $lot_size < floatval($filters['lot_size_min'])) {
            return false;
        }
        if (!empty($filters['lot_size_max']) && $lot_size > floatval($filters['lot_size_max'])) {
            return false;
        }

        return true;
    }

    /**
     * Year built matching
     */
    private static function matches_year_built($listing, $filters) {
        $year = intval(self::get_value($listing, ['year_built', 'YearBuilt']));
        
        if ($year === 0) {
            return true; // Skip if no year data
        }

        if (!empty($filters['year_built_min']) && $year < intval($filters['year_built_min'])) {
            return false;
        }
        if (!empty($filters['year_built_max']) && $year > intval($filters['year_built_max'])) {
            return false;
        }

        return true;
    }

    /**
     * Parking matching (garage, total)
     * Note: Filters are skipped if listing data doesn't contain the field (graceful degradation)
     */
    private static function matches_parking($listing, $filters) {
        // Garage spaces (available in summary table)
        if (!empty($filters['garage_spaces_min'])) {
            $garage = self::get_value($listing, ['garage_spaces', 'GarageSpaces']);
            // Only apply if data is available
            if ($garage !== null) {
                if (intval($garage) < intval($filters['garage_spaces_min'])) {
                    return false;
                }
            }
        }

        // Total parking (NOT in summary table - skip if unavailable)
        if (!empty($filters['parking_total_min'])) {
            $parking = self::get_value($listing, ['parking_total', 'ParkingTotal']);
            // Skip filter if data not available
            if ($parking !== null) {
                if (intval($parking) < intval($filters['parking_total_min'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Structure type and architectural style matching
     * Note: Filters are skipped if listing data doesn't contain the field (graceful degradation)
     */
    private static function matches_structure($listing, $filters) {
        // Structure Type (Detached, Attached, etc.)
        $structure_filters = [];
        if (!empty($filters['structure_type'])) {
            $structure_filters = array_merge($structure_filters, (array)$filters['structure_type']);
        }
        if (!empty($filters['StructureType'])) {
            $structure_filters = array_merge($structure_filters, (array)$filters['StructureType']);
        }

        if (!empty($structure_filters)) {
            $listing_structure = self::get_value($listing, ['structure_type', 'StructureType']);
            // Skip filter if data not available (graceful degradation)
            if ($listing_structure !== null) {
                $structure_filters = array_unique(array_map('strtolower', $structure_filters));
                if (!in_array(strtolower($listing_structure), $structure_filters)) {
                    return false;
                }
            }
        }

        // Architectural Style (Colonial, Cape, Ranch, etc.)
        $style_filters = [];
        if (!empty($filters['architectural_style'])) {
            $style_filters = array_merge($style_filters, (array)$filters['architectural_style']);
        }
        if (!empty($filters['ArchitecturalStyle'])) {
            $style_filters = array_merge($style_filters, (array)$filters['ArchitecturalStyle']);
        }

        if (!empty($style_filters)) {
            $listing_style = self::get_value($listing, ['architectural_style', 'ArchitecturalStyle']);
            // Skip filter if data not available
            if ($listing_style !== null) {
                $style_filters = array_unique(array_map('strtolower', $style_filters));
                if (!in_array(strtolower($listing_style), $style_filters)) {
                    return false;
                }
            }
        }

        // Stories
        if (!empty($filters['stories_min'])) {
            $stories = self::get_value($listing, ['stories_total', 'StoriesTotal', 'stories']);
            // Only apply if data available and > 0
            if ($stories !== null && intval($stories) > 0) {
                if (intval($stories) < intval($filters['stories_min'])) {
                    return false;
                }
            }
        }
        if (!empty($filters['stories_max'])) {
            $stories = self::get_value($listing, ['stories_total', 'StoriesTotal', 'stories']);
            if ($stories !== null && intval($stories) > 0) {
                if (intval($stories) > intval($filters['stories_max'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Agent and Office matching
     * Note: Filters are skipped if listing data doesn't contain the field (graceful degradation)
     */
    private static function matches_agent($listing, $filters) {
        // List Agent MLS ID
        if (!empty($filters['list_agent_mls_id']) || !empty($filters['ListAgentMlsId']) || !empty($filters['list_agent_id'])) {
            $listing_agent = self::get_value($listing, ['list_agent_mls_id', 'ListAgentMlsId']);
            // Skip filter if data not available
            if ($listing_agent !== null) {
                $agent_filters = array_merge(
                    (array)($filters['list_agent_mls_id'] ?? []),
                    (array)($filters['ListAgentMlsId'] ?? []),
                    (array)($filters['list_agent_id'] ?? [])
                );
                if (!in_array($listing_agent, $agent_filters)) {
                    return false;
                }
            }
        }

        // Buyer Agent MLS ID
        if (!empty($filters['buyer_agent_mls_id']) || !empty($filters['BuyerAgentMlsId']) || !empty($filters['buyer_agent_id'])) {
            $listing_buyer_agent = self::get_value($listing, ['buyer_agent_mls_id', 'BuyerAgentMlsId']);
            // Skip filter if data not available
            if ($listing_buyer_agent !== null) {
                $agent_filters = array_merge(
                    (array)($filters['buyer_agent_mls_id'] ?? []),
                    (array)($filters['BuyerAgentMlsId'] ?? []),
                    (array)($filters['buyer_agent_id'] ?? [])
                );
                if (!in_array($listing_buyer_agent, $agent_filters)) {
                    return false;
                }
            }
        }

        // List Office MLS ID
        if (!empty($filters['list_office_mls_id']) || !empty($filters['ListOfficeMlsId']) || !empty($filters['list_office_id'])) {
            $listing_office = self::get_value($listing, ['list_office_mls_id', 'ListOfficeMlsId']);
            // Skip filter if data not available
            if ($listing_office !== null) {
                $office_filters = array_merge(
                    (array)($filters['list_office_mls_id'] ?? []),
                    (array)($filters['ListOfficeMlsId'] ?? []),
                    (array)($filters['list_office_id'] ?? [])
                );
                if (!in_array($listing_office, $office_filters)) {
                    return false;
                }
            }
        }

        // Buyer Office MLS ID
        if (!empty($filters['buyer_office_mls_id']) || !empty($filters['BuyerOfficeMlsId'])) {
            $listing_buyer_office = self::get_value($listing, ['buyer_office_mls_id', 'BuyerOfficeMlsId']);
            // Skip filter if data not available
            if ($listing_buyer_office !== null) {
                $office_filters = array_merge(
                    (array)($filters['buyer_office_mls_id'] ?? []),
                    (array)($filters['BuyerOfficeMlsId'] ?? [])
                );
                if (!in_array($listing_buyer_office, $office_filters)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Days on market matching
     * Supports multiple key formats:
     * - iOS: min_dom, max_dom
     * - Web: dom_min, dom_max, days_on_market_min, days_on_market_max
     */
    private static function matches_days_on_market($listing, $filters) {
        // DOM minimum - check all possible key formats
        if (!empty($filters['dom_min']) || !empty($filters['min_dom']) || !empty($filters['days_on_market_min'])) {
            $dom_min = intval($filters['dom_min'] ?? $filters['min_dom'] ?? $filters['days_on_market_min']);
            $listing_dom = intval(self::get_value($listing, ['days_on_market', 'DaysOnMarket', 'mlspin_market_time_property']));
            if ($listing_dom < $dom_min) {
                return false;
            }
        }

        // DOM maximum - check all possible key formats
        if (!empty($filters['dom_max']) || !empty($filters['max_dom']) || !empty($filters['days_on_market_max'])) {
            $dom_max = intval($filters['dom_max'] ?? $filters['max_dom'] ?? $filters['days_on_market_max']);
            $listing_dom = intval(self::get_value($listing, ['days_on_market', 'DaysOnMarket', 'mlspin_market_time_property']));
            if ($listing_dom > 0 && $listing_dom > $dom_max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Status matching
     */
    private static function matches_status($listing, $filters) {
        if (empty($filters['status'])) {
            return true;
        }

        $status_filters = (array)$filters['status'];
        $listing_status = self::get_value($listing, ['standard_status', 'StandardStatus']);
        
        if (!empty($status_filters) && !in_array($listing_status, $status_filters)) {
            return false;
        }

        return true;
    }

    /**
     * Boolean features matching
     * Supports 25+ amenity/feature filters
     */
    private static function matches_features($listing, $filters) {
        // Comprehensive feature mappings: filter_key => [possible db columns]
        $feature_mappings = [
            // Water features
            'WaterfrontYN' => ['waterfront_yn', 'WaterfrontYN'],
            'waterfront' => ['waterfront_yn', 'WaterfrontYN'],
            'has_waterfront' => ['waterfront_yn', 'WaterfrontYN'],

            // Views
            'ViewYN' => ['view_yn', 'ViewYN'],
            'has_view' => ['view_yn', 'ViewYN'],

            // Pool & Spa
            'SpaYN' => ['spa_yn', 'SpaYN'],
            'has_spa' => ['spa_yn', 'SpaYN'],
            'has_pool' => ['has_pool', 'pool_private_yn', 'PoolPrivateYN'],
            'PoolPrivateYN' => ['has_pool', 'pool_private_yn', 'PoolPrivateYN'],

            // Climate control
            'CoolingYN' => ['cooling_yn', 'CoolingYN'],
            'has_cooling' => ['cooling_yn', 'CoolingYN'],
            'has_ac' => ['cooling_yn', 'CoolingYN'],

            // Fireplace
            'has_fireplace' => ['has_fireplace', 'fireplace_yn', 'FireplaceYN'],
            'FireplaceYN' => ['has_fireplace', 'fireplace_yn', 'FireplaceYN'],

            // Basement
            'has_basement' => ['has_basement', 'basement_yn'],
            'basement' => ['has_basement', 'basement_yn'],

            // HOA
            'has_hoa' => ['has_hoa', 'hoa_yn'],
            'hoa' => ['has_hoa', 'hoa_yn'],

            // Pet friendly
            'pet_friendly' => ['pet_friendly', 'pets_allowed', 'PetsAllowed'],
            'pets_allowed' => ['pet_friendly', 'pets_allowed', 'PetsAllowed'],

            // Garage
            'has_garage' => ['garage_yn', 'GarageYN'],
            'garage_yn' => ['garage_yn', 'GarageYN'],
            'GarageYN' => ['garage_yn', 'GarageYN'],
            'attached_garage' => ['attached_garage_yn', 'AttachedGarageYN'],
            'AttachedGarageYN' => ['attached_garage_yn', 'AttachedGarageYN'],

            // Property attached (townhouse/condo)
            'SeniorCommunityYN' => ['senior_community_yn', 'SeniorCommunityYN'],
            'senior_community' => ['senior_community_yn', 'SeniorCommunityYN'],
            '55_plus' => ['senior_community_yn', 'SeniorCommunityYN'],

            'PropertyAttachedYN' => ['property_attached_yn', 'PropertyAttachedYN'],
            'attached' => ['property_attached_yn', 'PropertyAttachedYN'],

            // Horse property
            'horse_yn' => ['horse_yn', 'HorseYN'],
            'HorseYN' => ['horse_yn', 'HorseYN'],
            'has_horse_facilities' => ['horse_yn', 'HorseYN'],

            // Home warranty
            'home_warranty_yn' => ['home_warranty_yn', 'HomeWarrantyYN'],
            'HomeWarrantyYN' => ['home_warranty_yn', 'HomeWarrantyYN'],
            'has_warranty' => ['home_warranty_yn', 'HomeWarrantyYN'],

            // Virtual tour
            'has_virtual_tour' => ['virtual_tour_url'],

            // New construction
            'new_construction' => ['new_construction_yn', 'NewConstructionYN'],
            'NewConstructionYN' => ['new_construction_yn', 'NewConstructionYN'],

            // Green features
            'green_certified' => ['green_certification', 'GreenBuildingCertification'],

            // MLSPIN-specific features (iOS keys)
            'MLSPIN_WATERVIEW_FLAG' => ['mlspin_waterview_flag', 'MLSPIN_WATERVIEW_FLAG'],
            'MLSPIN_LENDER_OWNED' => ['mlspin_lender_owned', 'MLSPIN_LENDER_OWNED'],
            'MLSPIN_DPR_Flag' => ['mlspin_dpr_flag', 'MLSPIN_DPR_Flag'],
            // Outdoor space uses patio_and_porch_features (not mlspin_outdoor_space_available which has no data)
            'MLSPIN_OUTDOOR_SPACE_AVAILABLE' => ['patio_and_porch_features'],
        ];

        foreach ($feature_mappings as $filter_key => $db_columns) {
            if (!empty($filters[$filter_key]) && self::is_truthy($filters[$filter_key])) {
                $value = self::get_value($listing, $db_columns);

                // Skip filter if field doesn't exist in listing (graceful degradation)
                // This handles cases where summary table doesn't have the field
                if ($value === null) {
                    continue;
                }

                // For virtual_tour_url, check if URL exists rather than boolean
                if ($filter_key === 'has_virtual_tour') {
                    if (empty($value)) {
                        return false;
                    }
                }
                // For outdoor space, check if patio_and_porch_features has actual data (not empty, not just "[]")
                elseif ($filter_key === 'MLSPIN_OUTDOOR_SPACE_AVAILABLE') {
                    if (empty($value) || $value === '[]' || $value === 'null') {
                        return false;
                    }
                } else {
                    if (!self::is_truthy($value)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * v6.60.0: Rental-specific filter matching (Phase 1)
     *
     * Matches listings against rental filters:
     * - pets_allowed: 0 = No pets, 1 = Pets OK, null = Any
     * - laundry_features: Array of laundry types to match (OR logic)
     * - lease_term: Array of lease terms to match (OR logic)
     */
    private static function matches_rental_filters($listing, $filters) {
        // Pets allowed filter
        // pets_allowed in filters: 0 = user wants no-pets, 1 = user wants pets allowed, null/not set = any
        if (isset($filters['pets_allowed']) && $filters['pets_allowed'] !== null && $filters['pets_allowed'] !== '') {
            $user_wants_pets = (int) $filters['pets_allowed'];
            $listing_pets = self::get_value($listing, ['pets_allowed', 'pet_friendly', 'PetsAllowed']);

            if ($user_wants_pets === 1) {
                // User wants pets allowed - listing must allow pets (or be unspecified)
                // Exclude listings that explicitly say no pets (pets_allowed = 0)
                if ($listing_pets !== null && (int) $listing_pets === 0) {
                    return false;
                }
            } else {
                // User wants no pets - listing must explicitly say no pets
                if ($listing_pets === null || (int) $listing_pets !== 0) {
                    return false;
                }
            }
        }

        // Laundry features filter (OR logic - match any selected type)
        if (!empty($filters['laundry_features'])) {
            $required_laundry = is_array($filters['laundry_features'])
                ? $filters['laundry_features']
                : array($filters['laundry_features']);

            $listing_laundry = self::get_value($listing, ['laundry_features', 'laundry', 'LaundryFeatures']);

            // If listing has no laundry data, it doesn't match
            if (empty($listing_laundry)) {
                return false;
            }

            // Check if ANY of the required laundry types are present
            $matched = false;
            foreach ($required_laundry as $laundry_type) {
                if (stripos($listing_laundry, $laundry_type) !== false) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        // Lease term filter (OR logic - match any selected term)
        if (!empty($filters['lease_term'])) {
            $required_terms = is_array($filters['lease_term'])
                ? $filters['lease_term']
                : array($filters['lease_term']);

            $listing_term = self::get_value($listing, ['lease_term', 'lease_terms', 'LeaseTerm']);

            // If listing has no lease term data, it doesn't match
            if (empty($listing_term)) {
                return false;
            }

            // Check if ANY of the required lease terms are present
            $matched = false;
            foreach ($required_terms as $term) {
                if (stripos($listing_term, $term) !== false) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * v6.67.4: School quality filter matching
     *
     * Matches listings against school-related filters:
     * - school_grade: District average grade (A, B, C, etc.)
     * - school_district_id: Specific school district ID
     * - near_a_elementary, near_ab_elementary: Proximity to A/A-B elementary schools (1mi)
     * - near_a_middle, near_ab_middle: Proximity to A/A-B middle schools (1mi)
     * - near_a_high, near_ab_high: Proximity to A/A-B high schools (1mi)
     * - near_top_elementary, near_top_high: Legacy filters (2mi/3mi)
     *
     * Requires BMN Schools Integration to be loaded. If not available,
     * gracefully skips school filters (matches all).
     *
     * @param array $listing Listing data
     * @param array $filters Search filters
     * @return bool True if listing matches school criteria or no school filters set
     */
    private static function matches_school_criteria($listing, $filters) {
        // Check if any school filters are set
        $school_filter_keys = [
            'school_grade',
            'school_district_id',
            'near_a_elementary',
            'near_ab_elementary',
            'near_a_middle',
            'near_ab_middle',
            'near_a_high',
            'near_ab_high',
            'near_top_elementary',  // Legacy
            'near_top_high',        // Legacy
        ];

        $has_school_filters = false;
        foreach ($school_filter_keys as $key) {
            if (!empty($filters[$key])) {
                $has_school_filters = true;
                break;
            }
        }

        // No school filters set - match all
        if (!$has_school_filters) {
            return true;
        }

        // Check if BMN Schools Integration is available
        // v6.68.7: Debug logging to diagnose school filter bypass issue
        if (!class_exists('MLD_BMN_Schools_Integration')) {
            // Graceful degradation: skip school filters if integration not loaded
            error_log('[MLD School Filter] BMN_Schools_Integration class NOT available - school filters bypassed');
            error_log('[MLD School Filter] Filter requested: school_grade=' . ($filters['school_grade'] ?? 'none'));
            error_log('[MLD School Filter] Listing city: ' . ($city ?? 'unknown') . ', listing_id: ' . ($listing['listing_id'] ?? 'unknown'));
            return true;
        }
        error_log('[MLD School Filter] BMN_Schools_Integration class available - checking school filters');

        // Get coordinates from listing
        $lat = floatval(self::get_value($listing, ['latitude', 'Latitude']));
        $lng = floatval(self::get_value($listing, ['longitude', 'Longitude']));
        $city = self::get_value($listing, ['city', 'City']);

        // Get schools integration instance
        $schools_integration = MLD_BMN_Schools_Integration::get_instance();

        // 1. School Grade filter (district average)
        if (!empty($filters['school_grade'])) {
            if (empty($city)) {
                // Can't check district grade without city
                error_log('[MLD School Filter] No city available for listing - cannot check district grade');
                return false;
            }

            // v6.68.14: Use get_district_grade_for_city() for consistency with API display
            // This uses district_rankings table (same as property detail page shows)
            $district_info = $schools_integration->get_district_grade_for_city($city);
            $district_grade = $district_info ? $district_info['grade'] : null;
            error_log('[MLD School Filter] City: ' . $city . ', District grade: ' . ($district_grade ?: 'NULL') . ', Required: ' . $filters['school_grade']);

            if (!$district_grade) {
                // No district data for this city - doesn't match
                error_log('[MLD School Filter] No district data for city: ' . $city . ' - listing excluded');
                return false;
            }

            $grade_passes = self::grade_meets_minimum($district_grade, $filters['school_grade']);
            error_log('[MLD School Filter] Grade check: ' . $district_grade . ' >= ' . $filters['school_grade'] . ' = ' . ($grade_passes ? 'PASS' : 'FAIL'));

            if (!$grade_passes) {
                return false;
            }
        }

        // 2. School District ID filter
        if (!empty($filters['school_district_id'])) {
            if (!$lat || !$lng) {
                return false;
            }

            $district = $schools_integration->get_district_for_point($lat, $lng);
            if (!$district || $district['id'] != $filters['school_district_id']) {
                return false;
            }
        }

        // 3. Proximity filters - require coordinates
        if (!$lat || !$lng) {
            // If any proximity filter is set but no coords, fail
            $proximity_filters = [
                'near_a_elementary', 'near_ab_elementary',
                'near_a_middle', 'near_ab_middle',
                'near_a_high', 'near_ab_high',
                'near_top_elementary', 'near_top_high',
            ];

            foreach ($proximity_filters as $key) {
                if (!empty($filters[$key])) {
                    return false;
                }
            }
        } else {
            // Check proximity filters

            // Elementary (K-4): within 1 mile of A-rated (includes A+, A, A-)
            if (!empty($filters['near_a_elementary'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'elementary', 1.0, 'A-')) {
                    return false;
                }
            }

            // Elementary (K-4): within 1 mile of A or B rated (includes all B and A grades)
            if (!empty($filters['near_ab_elementary'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'elementary', 1.0, 'B-')) {
                    return false;
                }
            }

            // Middle (4-8): within 1 mile of A-rated
            if (!empty($filters['near_a_middle'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'middle', 1.0, 'A-')) {
                    return false;
                }
            }

            // Middle (4-8): within 1 mile of A or B rated
            if (!empty($filters['near_ab_middle'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'middle', 1.0, 'B-')) {
                    return false;
                }
            }

            // High (9-12): within 1 mile of A-rated
            if (!empty($filters['near_a_high'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'high', 1.0, 'A-')) {
                    return false;
                }
            }

            // High (9-12): within 1 mile of A or B rated
            if (!empty($filters['near_ab_high'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'high', 1.0, 'B-')) {
                    return false;
                }
            }

            // Legacy filters (v6.29) - 2mi for elementary, 3mi for high
            if (!empty($filters['near_top_elementary'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'elementary', 2.0, 'A')) {
                    return false;
                }
            }

            if (!empty($filters['near_top_high'])) {
                if (!$schools_integration->property_near_top_school($lat, $lng, 'high', 3.0, 'A')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a grade meets minimum requirement
     *
     * Handles single-letter grades (A, B, C) as well as grades with
     * modifiers (A+, A-, B+, etc.). When the minimum is a single letter,
     * it includes all variants of that letter.
     *
     * @param string $grade The grade to check (e.g., 'A', 'B+', 'A-')
     * @param string $min_grade Minimum required grade (e.g., 'A', 'B')
     * @return bool True if grade meets or exceeds minimum
     */
    private static function grade_meets_minimum($grade, $min_grade) {
        // Grade ordering: higher number = better grade
        $grade_order = [
            'A+' => 12, 'A' => 11, 'A-' => 10,
            'B+' => 9,  'B' => 8,  'B-' => 7,
            'C+' => 6,  'C' => 5,  'C-' => 4,
            'D+' => 3,  'D' => 2,  'D-' => 1,
            'F' => 0
        ];

        // When min_grade is a single letter (A, B, C, D), include all variants
        // So "A" means A+, A, A- all pass; "B" means B+, B, B- all pass, etc.
        // Treat single letter as the "-" variant for comparison threshold
        if (strlen($min_grade) === 1 && $min_grade !== 'F') {
            $min_grade = $min_grade . '-';
        }

        $grade_value = $grade_order[$grade] ?? 0;
        $min_value = $grade_order[$min_grade] ?? 0;

        return $grade_value >= $min_value;
    }

    /**
     * Polygon boundary matching using ray-casting algorithm
     */
    private static function matches_polygon($listing, $search) {
        // Handle both object and array input
        $polygon_raw = is_array($search) ? ($search['polygon_shapes'] ?? null) : ($search->polygon_shapes ?? null);

        if (empty($polygon_raw)) {
            return true;
        }

        $lat = floatval(self::get_value($listing, ['latitude', 'Latitude']));
        $lng = floatval(self::get_value($listing, ['longitude', 'Longitude']));

        if (!$lat || !$lng) {
            return false; // Can't match without coordinates
        }

        $polygons = is_array($polygon_raw)
            ? $polygon_raw
            : json_decode($polygon_raw, true);
            
        if (empty($polygons)) {
            return true;
        }

        // Check if point is inside ANY polygon (OR logic)
        foreach ($polygons as $polygon) {
            $coordinates = $polygon['coordinates'] ?? [];
            if (!empty($coordinates) && self::point_in_polygon($lat, $lng, $coordinates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ray-casting algorithm for point-in-polygon test
     */
    private static function point_in_polygon($lat, $lng, $polygon) {
        if (empty($polygon)) {
            return false;
        }

        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = floatval($polygon[$i]['lat'] ?? $polygon[$i][0] ?? 0);
            $xi = floatval($polygon[$i]['lng'] ?? $polygon[$i][1] ?? 0);
            $yj = floatval($polygon[$j]['lat'] ?? $polygon[$j][0] ?? 0);
            $xj = floatval($polygon[$j]['lng'] ?? $polygon[$j][1] ?? 0);

            if ((($yi > $lat) != ($yj > $lat)) &&
                ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Helper: Get value from listing with multiple possible keys
     */
    private static function get_value($listing, $keys) {
        foreach ($keys as $key) {
            if (isset($listing[$key]) && $listing[$key] !== '' && $listing[$key] !== null) {
                return $listing[$key];
            }
        }
        return null;
    }

    /**
     * Helper: Check if a value is truthy (handles various formats)
     */
    private static function is_truthy($value) {
        if ($value === true || $value === 1 || $value === '1' || 
            $value === 'yes' || $value === 'Yes' || $value === 'YES' ||
            $value === 'true' || $value === 'True' || $value === 'TRUE') {
            return true;
        }
        return false;
    }
}
