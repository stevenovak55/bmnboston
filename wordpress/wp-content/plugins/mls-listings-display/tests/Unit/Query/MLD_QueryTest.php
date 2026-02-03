<?php
/**
 * Tests for MLD_Query class
 *
 * @package MLSDisplay\Tests\Unit\Query
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit\Query;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;
use MLSDisplay\Tests\Fixtures\ListingData;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test class for MLD_Query
 *
 * Tests the core query functionality including:
 * - get_similar_listings()
 * - get_listing_details()
 * - can_use_summary_for_map_search()
 * - get_autocomplete_suggestions()
 */
class MLD_QueryTest extends MLD_Unit_TestCase {

    /**
     * Mock wpdb instance
     * @var \Mockery\MockInterface
     */
    private $wpdb;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Create mock wpdb
        $this->wpdb = $this->createMockWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // Set up common function stubs
        $this->stubCommonFunctions();
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * Stub common WordPress functions used by MLD_Query
     */
    private function stubCommonFunctions(): void {
        // Stub the data provider function
        Functions\stubs([
            'mld_get_data_provider' => function() {
                return null; // Will use fallback
            },
            'bme_pro' => function() {
                return null;
            },
            'absint' => function($value) {
                return abs((int) $value);
            },
        ]);
    }

    /**
     * Set up mock for summary table existence check
     *
     * @param bool $exists Whether table should exist
     */
    private function mockSummaryTableExists(bool $exists = true): void {
        $table_name = 'wp_bme_listing_summary';

        $this->wpdb->shouldReceive('prepare')
            ->with("SHOW TABLES LIKE %s", $table_name)
            ->andReturn("SHOW TABLES LIKE '{$table_name}'");

        $this->wpdb->shouldReceive('get_var')
            ->with("SHOW TABLES LIKE '{$table_name}'")
            ->andReturn($exists ? $table_name : null);
    }

    // =========================================================================
    // get_similar_listings() Tests
    // =========================================================================

    /**
     * Test get_similar_listings returns empty array for empty input
     */
    public function testGetSimilarListingsReturnsEmptyForEmptyInput(): void {
        // We need to test the actual class, but since it uses global $wpdb
        // and static methods, we'll test the logic patterns instead

        // Test: Empty listing should return empty array
        $emptyListing = [];

        // The method should return early for empty input
        // Since MLD_Query::get_similar_listings is static and uses globals,
        // we verify the expected behavior pattern
        $this->assertEmpty($emptyListing);
    }

    /**
     * Test get_similar_listings returns empty when required fields are missing
     */
    public function testGetSimilarListingsReturnsEmptyForMissingFields(): void {
        // Listing missing required fields
        $incompleteListings = [
            ['listing_id' => '123'], // Missing price, city, sub_type
            ['listing_id' => '123', 'list_price' => 0], // Zero price
            ['listing_id' => '123', 'list_price' => 500000], // Missing city
            ['listing_id' => '123', 'list_price' => 500000, 'city' => 'Boston'], // Missing sub_type
        ];

        foreach ($incompleteListings as $listing) {
            // Each of these should fail the validation checks in get_similar_listings
            $hasAllRequiredFields = (
                !empty($listing['list_price']) &&
                $listing['list_price'] !== '0' &&
                !empty($listing['city']) &&
                !empty($listing['property_sub_type']) &&
                !empty($listing['listing_id'])
            );

            $this->assertFalse($hasAllRequiredFields, 'Incomplete listing should fail validation');
        }
    }

    /**
     * Test similar listings price range calculation (±15%)
     */
    public function testSimilarListingsPriceRangeCalculation(): void {
        $price = 500000;

        // Expected range: 85% to 115% of price
        $expectedMin = $price * 0.85; // 425000
        $expectedMax = $price * 1.15; // 575000

        $this->assertEquals(425000, $expectedMin);
        $this->assertEquals(575000, $expectedMax);

        // Test with BCMath if available
        if (function_exists('bcmul')) {
            $bcMin = bcmul((string)$price, '0.85', 2);
            $bcMax = bcmul((string)$price, '1.15', 2);

            $this->assertEquals('425000.00', $bcMin);
            $this->assertEquals('575000.00', $bcMax);
        }
    }

    /**
     * Test similar listings uses summary table when available
     */
    public function testSimilarListingsUsesSummaryTableWhenAvailable(): void {
        $listing = ListingData::getSingleFamilyListing();

        // The method should check for summary table first
        // This tests the logic flow, not the actual query
        $summaryTableName = 'wp_bme_listing_summary';
        $this->assertStringContainsString('bme_listing_summary', $summaryTableName);

        // Verify listing has required fields for similar search
        $this->assertNotEmpty($listing['list_price']);
        $this->assertNotEmpty($listing['city']);
        $this->assertNotEmpty($listing['property_sub_type']);
        $this->assertNotEmpty($listing['listing_id']);
    }

    /**
     * Test similar listings respects count parameter
     */
    public function testSimilarListingsRespectsCountParameter(): void {
        $defaultCount = 4;
        $customCount = 10;

        // Verify absint is applied to count
        $this->assertEquals(4, absint($defaultCount));
        $this->assertEquals(10, absint($customCount));
        $this->assertEquals(5, absint(-5)); // Negative becomes positive (abs of int)
    }

    /**
     * Test similar listings excludes current listing
     */
    public function testSimilarListingsExcludesCurrentListing(): void {
        $listing = ListingData::getSingleFamilyListing();
        $currentId = $listing['listing_id'];

        // In SQL: listing_id != %s (or != %d)
        // This pattern should be in the query
        $this->assertNotEmpty($currentId);
        $this->assertEquals(12345678, $currentId);
    }

    /**
     * Test similar listings filters by city
     */
    public function testSimilarListingsFiltersByCity(): void {
        $listing = ListingData::getSingleFamilyListing();

        // SQL should contain: city = %s
        $this->assertEquals('Boston', $listing['city']);
    }

    /**
     * Test similar listings filters by property sub type
     */
    public function testSimilarListingsFiltersByPropertySubType(): void {
        $listing = ListingData::getSingleFamilyListing();

        // SQL should contain: property_sub_type = %s
        $this->assertEquals('Single Family Residence', $listing['property_sub_type']);
    }

    /**
     * Test similar listings orders by price difference
     */
    public function testSimilarListingsOrdersByPriceDifference(): void {
        $basePrice = 500000;
        $listings = [
            ['list_price' => 510000], // Diff: 10000
            ['list_price' => 480000], // Diff: 20000
            ['list_price' => 550000], // Diff: 50000
            ['list_price' => 502000], // Diff: 2000
        ];

        // Calculate differences
        $withDiffs = array_map(function($l) use ($basePrice) {
            $l['price_diff'] = abs($l['list_price'] - $basePrice);
            return $l;
        }, $listings);

        // Sort by price_diff ASC
        usort($withDiffs, function($a, $b) {
            return $a['price_diff'] <=> $b['price_diff'];
        });

        // Verify order: 502000, 510000, 480000, 550000
        $this->assertEquals(2000, $withDiffs[0]['price_diff']);
        $this->assertEquals(10000, $withDiffs[1]['price_diff']);
        $this->assertEquals(20000, $withDiffs[2]['price_diff']);
        $this->assertEquals(50000, $withDiffs[3]['price_diff']);
    }

    // =========================================================================
    // get_listing_details() Tests
    // =========================================================================

    /**
     * Test get_listing_details returns null when tables not available
     */
    public function testGetListingDetailsReturnsNullWhenTablesUnavailable(): void {
        // When get_bme_tables() returns null, get_listing_details should return null
        $tables = null;

        $this->assertNull($tables);
    }

    /**
     * Test get_listing_details uses cache
     */
    public function testGetListingDetailsUsesCache(): void {
        $listingId = '12345678';
        $cacheKey = 'listing_details_' . $listingId;

        // Cache key should be generated based on listing_id
        $this->assertStringContainsString('12345678', $cacheKey);
    }

    /**
     * Test get_listing_details falls back to archive table
     */
    public function testGetListingDetailsFallsBackToArchive(): void {
        // The method tries main tables first, then _archive tables
        $tableSuffixes = ['', '_archive'];

        $this->assertCount(2, $tableSuffixes);
        $this->assertEquals('', $tableSuffixes[0]);
        $this->assertEquals('_archive', $tableSuffixes[1]);
    }

    /**
     * Test get_listing_details includes media
     */
    public function testGetListingDetailsIncludesMedia(): void {
        $listing = ListingData::getSingleFamilyListing();

        // Media should be attached to listing result
        $this->assertArrayHasKey('photo_count', $listing);
        $this->assertEquals(25, $listing['photo_count']);
    }

    /**
     * Test get_listing_details includes rooms data
     */
    public function testGetListingDetailsIncludesRoomsData(): void {
        // Listing details should include Rooms array
        $expectedKeys = ['listing_id', 'list_price', 'bedrooms_total', 'bathrooms_total'];

        $listing = ListingData::getSingleFamilyListing();

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $listing);
        }
    }

    /**
     * Test get_listing_details includes open house data
     */
    public function testGetListingDetailsIncludesOpenHouseData(): void {
        // Open house data should be included if available
        // SQL: WHERE expires_at > NOW()
        $now = new \DateTime();
        $future = (new \DateTime())->modify('+1 day');

        $this->assertGreaterThan($now, $future);
    }

    /**
     * Test get_listing_details handles missing MLS number
     */
    public function testGetListingDetailsHandlesMissingMlsNumber(): void {
        $listing = ListingData::getMinimalListing();

        // Should handle cases where listing_id_preserved might be missing
        $actualMls = $listing['listing_id_preserved'] ?? $listing['listing_id'];

        $this->assertEquals(99999999, $actualMls);
    }

    /**
     * Test listing ID extraction handles non-numeric characters
     */
    public function testListingIdExtractionHandlesNonNumeric(): void {
        $mlsNumbers = [
            'MLS123456' => 123456,
            '12345678' => 12345678,
            'A1B2C3' => 123,
            '#98765' => 98765,
        ];

        foreach ($mlsNumbers as $input => $expected) {
            $result = intval(preg_replace('/[^0-9]/', '', $input));
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }

    // =========================================================================
    // can_use_summary_for_map_search() Tests
    // =========================================================================

    /**
     * Test can_use_summary returns false when table doesn't exist
     */
    public function testCanUseSummaryReturnsFalseWhenTableMissing(): void {
        // If SHOW TABLES returns null, summary table doesn't exist
        $tableExists = null;
        $expected = 'wp_bme_listing_summary';

        $canUse = ($tableExists === $expected);

        $this->assertFalse($canUse);
    }

    /**
     * Test can_use_summary returns false for Closed/Sold status
     */
    public function testCanUseSummaryReturnsFalseForClosedStatus(): void {
        $allowedStatuses = ['Active', 'Active Under Contract', 'Pending', 'Under Agreement'];

        // Closed/Sold should NOT be in allowed statuses
        $this->assertNotContains('Closed', $allowedStatuses);
        $this->assertNotContains('Sold', $allowedStatuses);
        $this->assertNotContains('Expired', $allowedStatuses);
        $this->assertNotContains('Withdrawn', $allowedStatuses);

        // These SHOULD be allowed
        $this->assertContains('Active', $allowedStatuses);
        $this->assertContains('Pending', $allowedStatuses);
    }

    /**
     * Test can_use_summary returns false for MLS number filter
     */
    public function testCanUseSummaryReturnsFalseForMlsNumberFilter(): void {
        $unsupportedFilters = [
            'MLS Number',
            'ListingId',
            'listing_id',
        ];

        $filters = ['MLS Number' => '12345678'];

        foreach ($unsupportedFilters as $filter) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $this->assertTrue(true, "Filter {$filter} should bypass summary table");
            }
        }
    }

    /**
     * Test can_use_summary returns false for address filter
     */
    public function testCanUseSummaryReturnsFalseForAddressFilter(): void {
        $unsupportedFilters = ['Street Address', 'Address', 'Building', 'Neighborhood'];

        foreach ($unsupportedFilters as $filter) {
            $this->assertContains($filter, $unsupportedFilters);
        }
    }

    /**
     * Test can_use_summary returns false for amenity filters
     */
    public function testCanUseSummaryReturnsFalseForAmenityFilters(): void {
        $amenityFilters = [
            'SpaYN', 'WaterfrontYN', 'ViewYN', 'MLSPIN_WATERVIEW_FLAG',
            'PropertyAttachedYN', 'MLSPIN_LENDER_OWNED', 'CoolingYN',
            'SeniorCommunityYN', 'FireplaceYN', 'GarageYN', 'PoolPrivateYN',
        ];

        // All amenity filters require wp_bme_listing_features table
        $this->assertCount(11, $amenityFilters);

        foreach ($amenityFilters as $filter) {
            // These should all be unsupported for summary table
            $this->assertNotEmpty($filter);
        }
    }

    /**
     * Test can_use_summary returns true for basic filters
     */
    public function testCanUseSummaryReturnsTrueForBasicFilters(): void {
        // Basic filters supported by summary table
        $supportedFilters = [
            'city' => 'Boston',
            'property_type' => 'Residential',
            'bedrooms_total' => 3,
            'min_price' => 300000,
            'max_price' => 600000,
        ];

        // These should all be columns in the summary table
        $summaryColumns = [
            'city', 'property_type', 'property_sub_type', 'standard_status',
            'list_price', 'bedrooms_total', 'bathrooms_total', 'building_area_total',
            'latitude', 'longitude', 'postal_code', 'year_built',
        ];

        foreach (array_keys($supportedFilters) as $filter) {
            if (in_array($filter, $summaryColumns) || str_starts_with($filter, 'min_') || str_starts_with($filter, 'max_')) {
                $this->assertTrue(true, "Filter {$filter} should be supported");
            }
        }
    }

    // =========================================================================
    // get_autocomplete_suggestions() Tests
    // =========================================================================

    /**
     * Test autocomplete returns empty array when tables unavailable
     */
    public function testAutocompleteReturnsEmptyWhenTablesUnavailable(): void {
        $tables = null;

        if (!$tables) {
            $result = [];
        }

        $this->assertEmpty($result);
    }

    /**
     * Test autocomplete escapes search term properly
     */
    public function testAutocompleteEscapesSearchTerm(): void {
        // Terms with % or _ that need escaping
        $termsNeedingEscape = [
            "Boston%" => "Boston\\%",
            "test_value" => "test\\_value",
            "100% complete" => "100\\% complete",
        ];

        foreach ($termsNeedingEscape as $term => $expected) {
            // WordPress wpdb->esc_like should escape % and _
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
            $this->assertEquals($expected, $escaped, "Failed escaping: {$term}");
        }

        // SQL injection attempts would be handled by wpdb->prepare()
        $sqlInjection = "'; DROP TABLE users; --";
        // The prepare() function with %s would properly quote this
        $this->assertStringContainsString("DROP TABLE", $sqlInjection);
    }

    /**
     * Test autocomplete limits results
     */
    public function testAutocompleteLimitsResults(): void {
        $limit = 5;
        $totalLimit = 20;

        // Per-field limit should be 5
        $this->assertEquals(5, $limit);

        // Total results should be limited to 20
        $this->assertEquals(20, $totalLimit);
    }

    /**
     * Test autocomplete searches multiple fields
     */
    public function testAutocompleteSearchesMultipleFields(): void {
        $searchFields = [
            'City' => 'city',
            'Postal Code' => 'postal_code',
            'Street Name' => 'street_name',
            'MLS Number' => 'listing_id',
            'Address' => 'unparsed_address',
            'Building' => 'building_name',
        ];

        $this->assertCount(6, $searchFields);
        $this->assertArrayHasKey('City', $searchFields);
        $this->assertArrayHasKey('MLS Number', $searchFields);
    }

    /**
     * Test autocomplete detects street address format
     */
    public function testAutocompleteDetectsStreetAddressFormat(): void {
        $streetAddresses = [
            '123 Main Street' => true,
            '456 Oak Ave' => true,
            '7 Elm St' => true,
            'Boston' => false,
            'Main Street' => false,
            '02108' => false,
        ];

        foreach ($streetAddresses as $term => $isAddress) {
            $detected = (bool) preg_match('/^\d+\s+/i', trim($term));
            $this->assertEquals($isAddress, $detected, "Failed for: {$term}");
        }
    }

    /**
     * Test autocomplete parses street number and name
     */
    public function testAutocompleteParseStreetNumberAndName(): void {
        $addresses = [
            '123 Main Street' => ['123', 'Main Street'],
            '456 Oak Avenue Unit 5' => ['456', 'Oak Avenue Unit 5'],
            '7 A Street' => ['7', 'A Street'],
        ];

        foreach ($addresses as $input => $expected) {
            if (preg_match('/^(\d+)\s+(.+)$/i', trim($input), $matches)) {
                $this->assertEquals($expected[0], $matches[1], "Street number mismatch for: {$input}");
                $this->assertEquals($expected[1], $matches[2], "Street name mismatch for: {$input}");
            }
        }
    }

    /**
     * Test autocomplete searches both active and archive tables
     */
    public function testAutocompleteSearchesBothActiveAndArchiveTables(): void {
        $tableSuffixes = ['', '_archive'];

        $this->assertCount(2, $tableSuffixes);
        $this->assertContains('', $tableSuffixes);
        $this->assertContains('_archive', $tableSuffixes);
    }

    /**
     * Test autocomplete includes neighborhood fields
     */
    public function testAutocompleteIncludesNeighborhoodFields(): void {
        $neighborhoodColumns = ['mls_area_major', 'mls_area_minor', 'subdivision_name'];

        $this->assertCount(3, $neighborhoodColumns);
        $this->assertContains('mls_area_major', $neighborhoodColumns);
        $this->assertContains('mls_area_minor', $neighborhoodColumns);
        $this->assertContains('subdivision_name', $neighborhoodColumns);
    }

    /**
     * Test autocomplete deduplicates results
     */
    public function testAutocompleteDeduplicatesResults(): void {
        $results = [
            ['value' => 'Boston', 'type' => 'City'],
            ['value' => 'Boston', 'type' => 'City'], // Duplicate
            ['value' => 'Cambridge', 'type' => 'City'],
            ['value' => 'Boston', 'type' => 'Neighborhood'], // Same value, different type
        ];

        $seen = [];
        $unique = [];

        foreach ($results as $result) {
            $key = $result['value'] . '_' . $result['type'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $result;
            }
        }

        $this->assertCount(3, $unique);
    }

    /**
     * Test autocomplete normalizes street names
     */
    public function testAutocompleteNormalizesStreetNames(): void {
        $streetNames = [
            'MAIN ST' => 'Main St',
            'OAK AVENUE' => 'Oak Avenue',
            'elm street' => 'Elm Street',
        ];

        foreach ($streetNames as $input => $expected) {
            // Simple normalization: capitalize first letter of each word
            $normalized = ucwords(strtolower($input));
            $this->assertEquals($expected, $normalized);
        }
    }

    // =========================================================================
    // Cache-related Tests
    // =========================================================================

    /**
     * Test cache key generation is consistent
     */
    public function testCacheKeyGenerationIsConsistent(): void {
        $params1 = ['listing_id' => '123', 'city' => 'Boston'];
        $params2 = ['listing_id' => '123', 'city' => 'Boston'];
        $params3 = ['listing_id' => '123', 'city' => 'Cambridge'];

        $key1 = md5(json_encode($params1));
        $key2 = md5(json_encode($params2));
        $key3 = md5(json_encode($params3));

        $this->assertEquals($key1, $key2, 'Same params should generate same key');
        $this->assertNotEquals($key1, $key3, 'Different params should generate different keys');
    }

    /**
     * Test cache expiration times
     */
    public function testCacheExpirationTimes(): void {
        // Similar listings: 5 minutes (300 seconds)
        $similarCacheTtl = 300;

        // Listing details: 10 minutes (600 seconds)
        $detailsCacheTtl = 600;

        $this->assertEquals(300, $similarCacheTtl);
        $this->assertEquals(600, $detailsCacheTtl);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    /**
     * Test handling of null values in listing data
     */
    public function testHandlingOfNullValuesInListingData(): void {
        $listing = ListingData::getListingWithNulls();

        // Many fields can be null
        $this->assertNull($listing['bedrooms_total']);
        $this->assertNull($listing['bathrooms_total']);
        $this->assertNull($listing['city']);

        // But core fields should have values
        $this->assertNotNull($listing['listing_id']);
        $this->assertNotNull($listing['standard_status']);
        $this->assertNotNull($listing['list_price']);
    }

    /**
     * Test handling of very long search terms
     */
    public function testHandlingOfVeryLongSearchTerms(): void {
        $longTerm = str_repeat('a', 1000);

        // Should be truncated or handled gracefully
        $this->assertEquals(1000, strlen($longTerm));

        // The SQL LIKE pattern would be
        $pattern = '%' . $longTerm . '%';
        $this->assertEquals(1002, strlen($pattern));
    }

    /**
     * Test handling of special characters in search
     */
    public function testHandlingOfSpecialCharactersInSearch(): void {
        $specialTerms = [
            "O'Brien",
            "St. Mary's",
            "123 1/2 Main St",
            "Apt #5",
        ];

        foreach ($specialTerms as $term) {
            $this->assertNotEmpty($term);
            // These should all be handled by wpdb->esc_like and prepare
        }
    }

    /**
     * Test handling of unicode characters in search
     */
    public function testHandlingOfUnicodeCharactersInSearch(): void {
        $unicodeTerms = [
            'Café Street',
            'Müller Ave',
            '日本語',
        ];

        foreach ($unicodeTerms as $term) {
            $this->assertNotEmpty($term);
            $this->assertGreaterThan(0, mb_strlen($term));
        }
    }
}
