<?php
/**
 * Test Fixtures: Sample Listing Data
 *
 * Provides realistic test data for MLS listing tests.
 * Data is based on wp_bme_listing_summary table structure.
 *
 * @package MLSDisplay\Tests\Fixtures
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Fixtures;

/**
 * Class ListingData
 *
 * Provides sample listing data for testing
 */
class ListingData {

    /**
     * Get a single residential listing for testing
     *
     * @return array
     */
    public static function getSingleFamilyListing(): array {
        return [
            'listing_id'          => 12345678,
            'listing_key'         => 'abc123def456789012345678901234567890',
            'mls_id'              => 'MLS12345678',
            'property_type'       => 'Residential',
            'property_sub_type'   => 'Single Family Residence',
            'standard_status'     => 'Active',
            'list_price'          => 750000.00,
            'original_list_price' => 775000.00,
            'close_price'         => null,
            'price_per_sqft'      => 375.00,
            'bedrooms_total'      => 4,
            'bathrooms_total'     => 2.5,
            'bathrooms_full'      => 2,
            'bathrooms_half'      => 1,
            'building_area_total' => 2000,
            'lot_size_acres'      => 0.25,
            'year_built'          => 1985,
            'street_number'       => '123',
            'street_name'         => 'Main Street',
            'unit_number'         => null,
            'city'                => 'Boston',
            'state_or_province'   => 'MA',
            'postal_code'         => '02108',
            'county'              => 'Suffolk',
            'latitude'            => 42.35868700,
            'longitude'           => -71.05381800,
            'garage_spaces'       => 2,
            'has_pool'            => false,
            'has_fireplace'       => true,
            'has_basement'        => true,
            'has_hoa'             => false,
            'pet_friendly'        => true,
            'main_photo_url'      => 'https://example.com/photos/12345678/1.jpg',
            'photo_count'         => 25,
            'virtual_tour_url'    => 'https://example.com/tour/12345678',
            'listing_contract_date' => '2024-11-01',
            'close_date'          => null,
            'days_on_market'      => 25,
            'modification_timestamp' => '2024-11-25 10:00:00',
        ];
    }

    /**
     * Get a condo listing for testing
     *
     * @return array
     */
    public static function getCondoListing(): array {
        return [
            'listing_id'          => 23456789,
            'listing_key'         => 'xyz789abc123456789012345678901234567',
            'mls_id'              => 'MLS23456789',
            'property_type'       => 'Residential',
            'property_sub_type'   => 'Condominium',
            'standard_status'     => 'Active',
            'list_price'          => 550000.00,
            'original_list_price' => 550000.00,
            'close_price'         => null,
            'price_per_sqft'      => 458.33,
            'bedrooms_total'      => 2,
            'bathrooms_total'     => 2.0,
            'bathrooms_full'      => 2,
            'bathrooms_half'      => 0,
            'building_area_total' => 1200,
            'lot_size_acres'      => null,
            'year_built'          => 2015,
            'street_number'       => '456',
            'street_name'         => 'Harbor View',
            'unit_number'         => '12B',
            'city'                => 'Cambridge',
            'state_or_province'   => 'MA',
            'postal_code'         => '02139',
            'county'              => 'Middlesex',
            'latitude'            => 42.37383300,
            'longitude'           => -71.10946300,
            'garage_spaces'       => 1,
            'has_pool'            => true,
            'has_fireplace'       => false,
            'has_basement'        => false,
            'has_hoa'             => true,
            'pet_friendly'        => true,
            'main_photo_url'      => 'https://example.com/photos/23456789/1.jpg',
            'photo_count'         => 15,
            'virtual_tour_url'    => null,
            'listing_contract_date' => '2024-10-15',
            'close_date'          => null,
            'days_on_market'      => 41,
            'modification_timestamp' => '2024-11-20 14:30:00',
        ];
    }

    /**
     * Get a closed/sold listing for testing
     *
     * @return array
     */
    public static function getClosedListing(): array {
        return [
            'listing_id'          => 34567890,
            'listing_key'         => 'closed123sold4567890123456789012345',
            'mls_id'              => 'MLS34567890',
            'property_type'       => 'Residential',
            'property_sub_type'   => 'Single Family Residence',
            'standard_status'     => 'Closed',
            'list_price'          => 625000.00,
            'original_list_price' => 649000.00,
            'close_price'         => 620000.00,
            'price_per_sqft'      => 344.44,
            'bedrooms_total'      => 3,
            'bathrooms_total'     => 2.0,
            'bathrooms_full'      => 2,
            'bathrooms_half'      => 0,
            'building_area_total' => 1800,
            'lot_size_acres'      => 0.15,
            'year_built'          => 1970,
            'street_number'       => '789',
            'street_name'         => 'Oak Street',
            'unit_number'         => null,
            'city'                => 'Somerville',
            'state_or_province'   => 'MA',
            'postal_code'         => '02143',
            'county'              => 'Middlesex',
            'latitude'            => 42.38929700,
            'longitude'           => -71.09650000,
            'garage_spaces'       => 1,
            'has_pool'            => false,
            'has_fireplace'       => true,
            'has_basement'        => true,
            'has_hoa'             => false,
            'pet_friendly'        => true,
            'main_photo_url'      => 'https://example.com/photos/34567890/1.jpg',
            'photo_count'         => 20,
            'virtual_tour_url'    => null,
            'listing_contract_date' => '2024-08-01',
            'close_date'          => '2024-10-15',
            'days_on_market'      => 45,
            'modification_timestamp' => '2024-10-15 16:00:00',
        ];
    }

    /**
     * Get a pending listing for testing
     *
     * @return array
     */
    public static function getPendingListing(): array {
        return [
            'listing_id'          => 45678901,
            'listing_key'         => 'pend456contract78901234567890123456',
            'mls_id'              => 'MLS45678901',
            'property_type'       => 'Residential',
            'property_sub_type'   => 'Townhouse',
            'standard_status'     => 'Pending',
            'list_price'          => 485000.00,
            'original_list_price' => 499000.00,
            'close_price'         => null,
            'price_per_sqft'      => 323.33,
            'bedrooms_total'      => 3,
            'bathrooms_total'     => 1.5,
            'bathrooms_full'      => 1,
            'bathrooms_half'      => 1,
            'building_area_total' => 1500,
            'lot_size_acres'      => 0.05,
            'year_built'          => 2000,
            'street_number'       => '321',
            'street_name'         => 'Elm Avenue',
            'unit_number'         => '5',
            'city'                => 'Brookline',
            'state_or_province'   => 'MA',
            'postal_code'         => '02445',
            'county'              => 'Norfolk',
            'latitude'            => 42.33178900,
            'longitude'           => -71.12150000,
            'garage_spaces'       => 1,
            'has_pool'            => false,
            'has_fireplace'       => false,
            'has_basement'        => false,
            'has_hoa'             => true,
            'pet_friendly'        => false,
            'main_photo_url'      => 'https://example.com/photos/45678901/1.jpg',
            'photo_count'         => 18,
            'virtual_tour_url'    => 'https://example.com/tour/45678901',
            'listing_contract_date' => '2024-09-15',
            'close_date'          => null,
            'days_on_market'      => 30,
            'modification_timestamp' => '2024-11-15 09:00:00',
        ];
    }

    /**
     * Get a commercial listing for testing
     *
     * @return array
     */
    public static function getCommercialListing(): array {
        return [
            'listing_id'          => 56789012,
            'listing_key'         => 'comm567commercial890123456789012345',
            'mls_id'              => 'MLS56789012',
            'property_type'       => 'Commercial Sale',
            'property_sub_type'   => 'Office',
            'standard_status'     => 'Active',
            'list_price'          => 1250000.00,
            'original_list_price' => 1250000.00,
            'close_price'         => null,
            'price_per_sqft'      => 250.00,
            'bedrooms_total'      => null,
            'bathrooms_total'     => null,
            'bathrooms_full'      => null,
            'bathrooms_half'      => null,
            'building_area_total' => 5000,
            'lot_size_acres'      => 0.5,
            'year_built'          => 1990,
            'street_number'       => '100',
            'street_name'         => 'Commercial Way',
            'unit_number'         => null,
            'city'                => 'Boston',
            'state_or_province'   => 'MA',
            'postal_code'         => '02110',
            'county'              => 'Suffolk',
            'latitude'            => 42.35768700,
            'longitude'           => -71.05281800,
            'garage_spaces'       => 10,
            'has_pool'            => false,
            'has_fireplace'       => false,
            'has_basement'        => false,
            'has_hoa'             => false,
            'pet_friendly'        => false,
            'main_photo_url'      => 'https://example.com/photos/56789012/1.jpg',
            'photo_count'         => 10,
            'virtual_tour_url'    => null,
            'listing_contract_date' => '2024-06-01',
            'close_date'          => null,
            'days_on_market'      => 178,
            'modification_timestamp' => '2024-11-01 11:00:00',
        ];
    }

    /**
     * Get a land listing for testing
     *
     * @return array
     */
    public static function getLandListing(): array {
        return [
            'listing_id'          => 67890123,
            'listing_key'         => 'land678vacant901234567890123456789',
            'mls_id'              => 'MLS67890123',
            'property_type'       => 'Land',
            'property_sub_type'   => 'Residential',
            'standard_status'     => 'Active',
            'list_price'          => 350000.00,
            'original_list_price' => 395000.00,
            'close_price'         => null,
            'price_per_sqft'      => null,
            'bedrooms_total'      => null,
            'bathrooms_total'     => null,
            'bathrooms_full'      => null,
            'bathrooms_half'      => null,
            'building_area_total' => null,
            'lot_size_acres'      => 2.5,
            'year_built'          => null,
            'street_number'       => null,
            'street_name'         => 'Forest Road',
            'unit_number'         => null,
            'city'                => 'Newton',
            'state_or_province'   => 'MA',
            'postal_code'         => '02459',
            'county'              => 'Middlesex',
            'latitude'            => 42.33006800,
            'longitude'           => -71.20922200,
            'garage_spaces'       => null,
            'has_pool'            => false,
            'has_fireplace'       => false,
            'has_basement'        => false,
            'has_hoa'             => false,
            'pet_friendly'        => false,
            'main_photo_url'      => 'https://example.com/photos/67890123/1.jpg',
            'photo_count'         => 5,
            'virtual_tour_url'    => null,
            'listing_contract_date' => '2024-07-01',
            'close_date'          => null,
            'days_on_market'      => 148,
            'modification_timestamp' => '2024-10-01 08:00:00',
        ];
    }

    /**
     * Get a collection of active listings for pagination/search testing
     *
     * @param int $count Number of listings to generate
     * @return array
     */
    public static function getActiveListings(int $count = 10): array {
        $listings = [];
        $cities = ['Boston', 'Cambridge', 'Somerville', 'Brookline', 'Newton'];
        $propertyTypes = ['Single Family Residence', 'Condominium', 'Townhouse', 'Multi-Family'];

        for ($i = 0; $i < $count; $i++) {
            $baseId = 70000000 + $i;
            $cityIndex = $i % count($cities);
            $typeIndex = $i % count($propertyTypes);
            $price = 400000 + ($i * 50000);
            $bedrooms = ($i % 4) + 1;
            $sqft = 1000 + ($i * 200);

            $listings[] = [
                'listing_id'          => $baseId,
                'listing_key'         => md5("test_listing_{$baseId}"),
                'mls_id'              => "MLS{$baseId}",
                'property_type'       => 'Residential',
                'property_sub_type'   => $propertyTypes[$typeIndex],
                'standard_status'     => 'Active',
                'list_price'          => (float) $price,
                'original_list_price' => (float) $price,
                'close_price'         => null,
                'price_per_sqft'      => round($price / $sqft, 2),
                'bedrooms_total'      => $bedrooms,
                'bathrooms_total'     => (float) (($bedrooms > 2) ? 2.5 : 1.5),
                'bathrooms_full'      => ($bedrooms > 2) ? 2 : 1,
                'bathrooms_half'      => 1,
                'building_area_total' => $sqft,
                'lot_size_acres'      => round(0.1 + ($i * 0.05), 2),
                'year_built'          => 1960 + $i,
                'street_number'       => (string) (100 + $i),
                'street_name'         => 'Test Street',
                'unit_number'         => null,
                'city'                => $cities[$cityIndex],
                'state_or_province'   => 'MA',
                'postal_code'         => '0210' . $cityIndex,
                'county'              => $cityIndex < 2 ? 'Suffolk' : 'Middlesex',
                'latitude'            => 42.35 + ($i * 0.01),
                'longitude'           => -71.05 - ($i * 0.01),
                'garage_spaces'       => $i % 3,
                'has_pool'            => $i % 5 === 0,
                'has_fireplace'       => $i % 2 === 0,
                'has_basement'        => $i % 3 === 0,
                'has_hoa'             => $typeIndex === 1, // Condos have HOA
                'pet_friendly'        => $i % 4 !== 0,
                'main_photo_url'      => "https://example.com/photos/{$baseId}/1.jpg",
                'photo_count'         => 10 + $i,
                'virtual_tour_url'    => $i % 3 === 0 ? "https://example.com/tour/{$baseId}" : null,
                'listing_contract_date' => date('Y-m-d', strtotime("-{$i} days")),
                'close_date'          => null,
                'days_on_market'      => $i + 1,
                'modification_timestamp' => date('Y-m-d H:i:s', strtotime("-{$i} hours")),
            ];
        }

        return $listings;
    }

    /**
     * Get listings for map bounds testing
     * Creates listings in a specific geographic area
     *
     * @param float $centerLat Center latitude
     * @param float $centerLng Center longitude
     * @param int $count Number of listings
     * @return array
     */
    public static function getListingsInBounds(
        float $centerLat = 42.3601,
        float $centerLng = -71.0589,
        int $count = 5
    ): array {
        $listings = [];
        $spread = 0.02; // ~1.4 miles at Boston's latitude

        for ($i = 0; $i < $count; $i++) {
            $offsetLat = (rand(-100, 100) / 100) * $spread;
            $offsetLng = (rand(-100, 100) / 100) * $spread;

            $listing = self::getSingleFamilyListing();
            $listing['listing_id'] = 80000000 + $i;
            $listing['listing_key'] = md5("bounds_test_{$i}");
            $listing['latitude'] = $centerLat + $offsetLat;
            $listing['longitude'] = $centerLng + $offsetLng;
            $listing['list_price'] = 500000 + ($i * 100000);
            $listing['city'] = 'Boston';

            $listings[] = $listing;
        }

        return $listings;
    }

    /**
     * Get similar listings for CMA testing
     * Creates listings similar to a subject property
     *
     * @param array $subject Subject property to base comparables on
     * @param int $count Number of comparable listings
     * @return array
     */
    public static function getSimilarListings(array $subject, int $count = 5): array {
        $listings = [];
        $priceVariance = 0.15; // 15% price variance
        $sqftVariance = 0.20; // 20% sqft variance

        $basePrice = $subject['list_price'] ?? 500000;
        $baseSqft = $subject['building_area_total'] ?? 1500;
        $baseBeds = $subject['bedrooms_total'] ?? 3;

        for ($i = 0; $i < $count; $i++) {
            $priceMultiplier = 1 + ((rand(-100, 100) / 100) * $priceVariance);
            $sqftMultiplier = 1 + ((rand(-100, 100) / 100) * $sqftVariance);
            $beds = max(1, $baseBeds + rand(-1, 1));

            $listing = self::getSingleFamilyListing();
            $listing['listing_id'] = 90000000 + $i;
            $listing['listing_key'] = md5("similar_test_{$i}");
            $listing['list_price'] = round($basePrice * $priceMultiplier, 2);
            $listing['building_area_total'] = round($baseSqft * $sqftMultiplier);
            $listing['bedrooms_total'] = $beds;
            $listing['city'] = $subject['city'] ?? 'Boston';
            $listing['postal_code'] = $subject['postal_code'] ?? '02108';

            // Vary the status for some (for testing closed comps)
            if ($i % 3 === 0) {
                $listing['standard_status'] = 'Closed';
                $listing['close_price'] = $listing['list_price'] * 0.98;
                $listing['close_date'] = date('Y-m-d', strtotime("-" . ($i * 10) . " days"));
            }

            $listings[] = $listing;
        }

        return $listings;
    }

    /**
     * Get minimal listing data (only required fields)
     * Useful for testing edge cases
     *
     * @return array
     */
    public static function getMinimalListing(): array {
        return [
            'listing_id'     => 99999999,
            'listing_key'    => 'minimal12345678901234567890123456',
            'standard_status' => 'Active',
            'list_price'     => 100000.00,
            'property_type'  => 'Residential',
        ];
    }

    /**
     * Get listing with empty/null fields for edge case testing
     *
     * @return array
     */
    public static function getListingWithNulls(): array {
        return [
            'listing_id'          => 88888888,
            'listing_key'         => 'nulltest1234567890123456789012345',
            'mls_id'              => null,
            'property_type'       => 'Residential',
            'property_sub_type'   => null,
            'standard_status'     => 'Active',
            'list_price'          => 450000.00,
            'original_list_price' => null,
            'close_price'         => null,
            'price_per_sqft'      => null,
            'bedrooms_total'      => null,
            'bathrooms_total'     => null,
            'bathrooms_full'      => null,
            'bathrooms_half'      => null,
            'building_area_total' => null,
            'lot_size_acres'      => null,
            'year_built'          => null,
            'street_number'       => null,
            'street_name'         => null,
            'unit_number'         => null,
            'city'                => null,
            'state_or_province'   => null,
            'postal_code'         => null,
            'county'              => null,
            'latitude'            => null,
            'longitude'           => null,
            'garage_spaces'       => null,
            'has_pool'            => null,
            'has_fireplace'       => null,
            'has_basement'        => null,
            'has_hoa'             => null,
            'pet_friendly'        => null,
            'main_photo_url'      => null,
            'photo_count'         => 0,
            'virtual_tour_url'    => null,
            'listing_contract_date' => null,
            'close_date'          => null,
            'days_on_market'      => null,
            'modification_timestamp' => null,
        ];
    }
}
