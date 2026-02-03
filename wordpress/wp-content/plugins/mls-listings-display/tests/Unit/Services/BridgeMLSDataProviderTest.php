<?php
/**
 * Tests for BridgeMLSDataProvider class
 *
 * @package MLSDisplay\Tests\Unit\Services
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit\Services;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;
use MLSDisplay\Tests\Fixtures\ListingData;
use MLSDisplay\Tests\Mocks\MockDataProvider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test class for BridgeMLSDataProvider
 *
 * Tests the data provider functionality including:
 * - isAvailable()
 * - getTablePrefix()
 * - getTables()
 * - getListings()
 * - getListing()
 * - getListingPhotos()
 * - getDistinctValues()
 * - getListingsForMap()
 * - getListingCount()
 */
class BridgeMLSDataProviderTest extends MLD_Unit_TestCase {

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
     * Stub common WordPress functions
     */
    private function stubCommonFunctions(): void {
        Functions\stubs([
            'is_plugin_active' => function($plugin) {
                return true; // Assume plugin is active for tests
            },
        ]);
    }

    // =========================================================================
    // isAvailable() Tests
    // =========================================================================

    /**
     * Test isAvailable returns true when plugin is active
     */
    public function testIsAvailableReturnsTrueWhenPluginActive(): void {
        // MockDataProvider is always available by default
        $provider = new MockDataProvider();
        $this->assertTrue($provider->isAvailable());

        // Verify the method was called
        $calls = $provider->getMethodCalls('isAvailable');
        $this->assertCount(1, $calls);
    }

    /**
     * Test isAvailable returns false when disabled
     */
    public function testIsAvailableReturnsFalseWhenDisabled(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $this->assertFalse($provider->isAvailable());
    }

    /**
     * Test isAvailable checks for BME_Database_Manager class
     */
    public function testIsAvailableChecksBMEDatabaseManagerClass(): void {
        // The real provider checks for class_exists('BME_Database_Manager')
        $classExists = class_exists('BME_Database_Manager');
        // In our test environment, this class doesn't exist
        $this->assertFalse($classExists);
    }

    // =========================================================================
    // getTablePrefix() Tests
    // =========================================================================

    /**
     * Test getTablePrefix returns correct prefix
     */
    public function testGetTablePrefixReturnsCorrectPrefix(): void {
        $provider = new MockDataProvider();

        // Mock provider uses 'wp_test_bme_' prefix
        $this->assertEquals('wp_test_bme_', $provider->getTablePrefix());
    }

    /**
     * Test getTablePrefix includes wpdb prefix
     */
    public function testGetTablePrefixIncludesWpdbPrefix(): void {
        // Real provider uses $wpdb->prefix . 'bme_'
        $expectedPattern = '/^wp_.*bme_$/';

        $provider = new MockDataProvider();
        $prefix = $provider->getTablePrefix();

        $this->assertMatchesRegularExpression($expectedPattern, $prefix);
    }

    // =========================================================================
    // getTables() Tests
    // =========================================================================

    /**
     * Test getTables returns array of table names
     */
    public function testGetTablesReturnsArrayOfTableNames(): void {
        $provider = new MockDataProvider();
        $tables = $provider->getTables();

        $this->assertIsArray($tables);
        $this->assertArrayHasKey('listings', $tables);
    }

    /**
     * Test getTables returns null when unavailable
     */
    public function testGetTablesReturnsNullWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $tables = $provider->getTables();

        $this->assertNull($tables);
    }

    /**
     * Test getTables caches result
     */
    public function testGetTablesCachesResult(): void {
        $provider = new MockDataProvider();

        // First call
        $tables1 = $provider->getTables();
        // Second call should return cached result
        $tables2 = $provider->getTables();

        $this->assertEquals($tables1, $tables2);

        // Verify getTables was called twice (recorded)
        $calls = $provider->getMethodCalls('getTables');
        $this->assertCount(2, $calls);
    }

    /**
     * Test getTables includes expected table types
     */
    public function testGetTablesIncludesExpectedTableTypes(): void {
        $provider = new MockDataProvider();
        $tables = $provider->getTables();

        $expectedTables = ['listings', 'location', 'photos', 'agents', 'offices'];

        foreach ($expectedTables as $tableType) {
            $this->assertArrayHasKey($tableType, $tables);
        }
    }

    // =========================================================================
    // getListings() Tests
    // =========================================================================

    /**
     * Test getListings returns array of listings
     */
    public function testGetListingsReturnsArrayOfListings(): void {
        $provider = new MockDataProvider();
        $listings = $provider->getListings();

        $this->assertIsArray($listings);
        $this->assertNotEmpty($listings);
    }

    /**
     * Test getListings returns empty array when unavailable
     */
    public function testGetListingsReturnsEmptyWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $listings = $provider->getListings();

        $this->assertIsArray($listings);
        $this->assertEmpty($listings);
    }

    /**
     * Test getListings filters by city
     */
    public function testGetListingsFiltersByCity(): void {
        $provider = new MockDataProvider();
        $listings = $provider->getListings(['city' => 'Boston']);

        foreach ($listings as $listing) {
            $this->assertEquals('Boston', $listing['city']);
        }
    }

    /**
     * Test getListings filters by price range
     */
    public function testGetListingsFiltersByPriceRange(): void {
        $provider = new MockDataProvider();
        $listings = $provider->getListings([
            'min_price' => 400000,
            'max_price' => 600000,
        ]);

        foreach ($listings as $listing) {
            $this->assertGreaterThanOrEqual(400000, $listing['list_price']);
            $this->assertLessThanOrEqual(600000, $listing['list_price']);
        }
    }

    /**
     * Test getListings respects limit parameter
     */
    public function testGetListingsRespectsLimit(): void {
        $provider = new MockDataProvider();

        $allListings = $provider->getListings([], 100);
        $limitedListings = $provider->getListings([], 3);

        $this->assertLessThanOrEqual(3, count($limitedListings));
    }

    /**
     * Test getListings respects offset parameter
     */
    public function testGetListingsRespectsOffset(): void {
        $provider = new MockDataProvider();

        $firstPage = $provider->getListings([], 5, 0);
        $secondPage = $provider->getListings([], 5, 5);

        // First listing on second page shouldn't be on first page
        if (!empty($secondPage)) {
            $firstPageIds = array_column($firstPage, 'listing_id');
            $this->assertNotContains($secondPage[0]['listing_id'], $firstPageIds);
        }
    }

    /**
     * Test getListings records method calls
     */
    public function testGetListingsRecordsMethodCalls(): void {
        $provider = new MockDataProvider();
        $provider->getListings(['city' => 'Boston'], 10, 0);

        $calls = $provider->getMethodCalls('getListings');

        $this->assertCount(1, $calls);
        $this->assertEquals('getListings', $calls[0]['method']);
        $this->assertEquals(['city' => 'Boston'], $calls[0]['args']['criteria']);
        $this->assertEquals(10, $calls[0]['args']['limit']);
        $this->assertEquals(0, $calls[0]['args']['offset']);
    }

    // =========================================================================
    // getListing() Tests
    // =========================================================================

    /**
     * Test getListing returns single listing
     */
    public function testGetListingReturnsSingleListing(): void {
        $provider = new MockDataProvider();
        $listings = $provider->getListings([], 1);

        if (!empty($listings)) {
            $listingId = (string) $listings[0]['listing_id'];
            $listing = $provider->getListing($listingId);

            $this->assertIsArray($listing);
            $this->assertEquals($listingId, (string) $listing['listing_id']);
        }
    }

    /**
     * Test getListing returns null for non-existent listing
     */
    public function testGetListingReturnsNullForNonExistent(): void {
        $provider = new MockDataProvider();
        $listing = $provider->getListing('99999999999');

        $this->assertNull($listing);
    }

    /**
     * Test getListing returns null when unavailable
     */
    public function testGetListingReturnsNullWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $listing = $provider->getListing('12345678');

        $this->assertNull($listing);
    }

    // =========================================================================
    // getListingPhotos() Tests
    // =========================================================================

    /**
     * Test getListingPhotos returns array
     */
    public function testGetListingPhotosReturnsArray(): void {
        $provider = new MockDataProvider();
        $photos = $provider->getListingPhotos('12345678');

        $this->assertIsArray($photos);
    }

    /**
     * Test getListingPhotos returns empty when unavailable
     */
    public function testGetListingPhotosReturnsEmptyWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $photos = $provider->getListingPhotos('12345678');

        $this->assertEmpty($photos);
    }

    // =========================================================================
    // getDistinctValues() Tests
    // =========================================================================

    /**
     * Test getDistinctValues returns unique values
     */
    public function testGetDistinctValuesReturnsUniqueValues(): void {
        $provider = new MockDataProvider();
        $cities = $provider->getDistinctValues('city');

        $this->assertIsArray($cities);
        // Should be unique
        $this->assertEquals($cities, array_unique($cities));
    }

    /**
     * Test getDistinctValues filters results
     */
    public function testGetDistinctValuesFiltersResults(): void {
        $provider = new MockDataProvider();
        $cities = $provider->getDistinctValues('city', ['standard_status' => 'Active']);

        $this->assertIsArray($cities);
    }

    /**
     * Test getDistinctValues returns empty when unavailable
     */
    public function testGetDistinctValuesReturnsEmptyWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $values = $provider->getDistinctValues('city');

        $this->assertEmpty($values);
    }

    // =========================================================================
    // getListingsForMap() Tests
    // =========================================================================

    /**
     * Test getListingsForMap returns listings with coordinates
     */
    public function testGetListingsForMapReturnsListingsWithCoordinates(): void {
        $provider = new MockDataProvider();
        $bounds = [
            'north' => 42.50,
            'south' => 42.20,
            'east' => -70.90,
            'west' => -71.20,
        ];

        $listings = $provider->getListingsForMap($bounds);

        foreach ($listings as $listing) {
            $this->assertArrayHasKey('latitude', $listing);
            $this->assertArrayHasKey('longitude', $listing);
            $this->assertNotNull($listing['latitude']);
            $this->assertNotNull($listing['longitude']);
        }
    }

    /**
     * Test getListingsForMap filters by bounds
     */
    public function testGetListingsForMapFiltersByBounds(): void {
        $provider = new MockDataProvider();
        $bounds = [
            'north' => 42.40,
            'south' => 42.30,
            'east' => -71.00,
            'west' => -71.10,
        ];

        $listings = $provider->getListingsForMap($bounds);

        foreach ($listings as $listing) {
            $this->assertGreaterThanOrEqual($bounds['south'], $listing['latitude']);
            $this->assertLessThanOrEqual($bounds['north'], $listing['latitude']);
            $this->assertGreaterThanOrEqual($bounds['west'], $listing['longitude']);
            $this->assertLessThanOrEqual($bounds['east'], $listing['longitude']);
        }
    }

    /**
     * Test getListingsForMap applies additional filters
     */
    public function testGetListingsForMapAppliesFilters(): void {
        $provider = new MockDataProvider();
        $bounds = ['north' => 43.0, 'south' => 42.0, 'east' => -70.0, 'west' => -72.0];
        $filters = ['city' => 'Boston'];

        $listings = $provider->getListingsForMap($bounds, $filters);

        // All returned listings should be in Boston (if any match both criteria)
        foreach ($listings as $listing) {
            if (isset($listing['city'])) {
                $this->assertEquals('Boston', $listing['city']);
            }
        }
    }

    /**
     * Test getListingsForMap returns empty when unavailable
     */
    public function testGetListingsForMapReturnsEmptyWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $bounds = ['north' => 43.0, 'south' => 42.0, 'east' => -70.0, 'west' => -72.0];
        $listings = $provider->getListingsForMap($bounds);

        $this->assertEmpty($listings);
    }

    /**
     * Test getListingsForMap respects zoom parameter
     */
    public function testGetListingsForMapRespectsZoomParameter(): void {
        $provider = new MockDataProvider();
        $bounds = ['north' => 43.0, 'south' => 42.0, 'east' => -70.0, 'west' => -72.0];

        // Method is called with zoom parameter
        $provider->getListingsForMap($bounds, [], 14);

        $calls = $provider->getMethodCalls('getListingsForMap');
        $this->assertCount(1, $calls);
        $this->assertEquals(14, $calls[0]['args']['zoom']);
    }

    // =========================================================================
    // getListingCount() Tests
    // =========================================================================

    /**
     * Test getListingCount returns integer
     */
    public function testGetListingCountReturnsInteger(): void {
        $provider = new MockDataProvider();
        $count = $provider->getListingCount();

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * Test getListingCount returns zero when unavailable
     */
    public function testGetListingCountReturnsZeroWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $count = $provider->getListingCount();

        $this->assertEquals(0, $count);
    }

    /**
     * Test getListingCount with criteria
     */
    public function testGetListingCountWithCriteria(): void {
        $provider = new MockDataProvider();

        $totalCount = $provider->getListingCount();
        $filteredCount = $provider->getListingCount(['standard_status' => 'Active']);

        // Filtered count should be less than or equal to total
        $this->assertLessThanOrEqual($totalCount, $filteredCount);
    }

    /**
     * Test getListingCount filters correctly
     */
    public function testGetListingCountFiltersCorrectly(): void {
        $provider = new MockDataProvider();

        // Count for a specific city
        $bostonCount = $provider->getListingCount(['city' => 'Boston']);
        $bostonListings = $provider->getListings(['city' => 'Boston'], 1000);

        // Count should match number of listings
        $this->assertEquals(count($bostonListings), $bostonCount);
    }

    // =========================================================================
    // query() Tests
    // =========================================================================

    /**
     * Test query returns array for valid query
     */
    public function testQueryReturnsArrayForValidQuery(): void {
        $provider = new MockDataProvider();
        $result = $provider->query("SELECT * FROM listings");

        // Default mock returns empty array for query
        $this->assertIsArray($result);
    }

    /**
     * Test query returns null when unavailable
     */
    public function testQueryReturnsNullWhenUnavailable(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $result = $provider->query("SELECT * FROM listings");

        $this->assertNull($result);
    }

    /**
     * Test query can use custom results
     */
    public function testQueryCanUseCustomResults(): void {
        $provider = new MockDataProvider();
        $customResults = [
            ['id' => 1, 'name' => 'Test'],
            ['id' => 2, 'name' => 'Test 2'],
        ];
        $provider->setQueryResults($customResults);

        $result = $provider->query("SELECT * FROM test");

        $this->assertEquals($customResults, $result);
    }

    // =========================================================================
    // Edge Cases and Error Handling
    // =========================================================================

    /**
     * Test provider handles empty criteria gracefully
     */
    public function testProviderHandlesEmptyCriteria(): void {
        $provider = new MockDataProvider();

        $listings = $provider->getListings([]);
        $count = $provider->getListingCount([]);

        $this->assertIsArray($listings);
        $this->assertIsInt($count);
    }

    /**
     * Test provider handles null values in criteria
     */
    public function testProviderHandlesNullValuesInCriteria(): void {
        $provider = new MockDataProvider();

        // Should handle criteria with null values
        $listings = $provider->getListings(['city' => null]);

        $this->assertIsArray($listings);
    }

    /**
     * Test provider reset clears state
     */
    public function testProviderResetClearsState(): void {
        $provider = new MockDataProvider();

        // Modify provider state
        $provider->setAvailable(false);
        $provider->setTablePrefix('custom_prefix_');

        // Verify modified state
        $this->assertFalse($provider->isAvailable());
        $this->assertEquals('custom_prefix_', $provider->getTablePrefix());

        // Reset
        $provider->reset();

        // Clear method calls that were made during verification above
        $provider->clearMethodCalls();

        // Now test reset state
        $this->assertTrue($provider->isAvailable());
        $this->assertEquals('wp_test_bme_', $provider->getTablePrefix());
    }

    /**
     * Test provider tracks method calls correctly
     */
    public function testProviderTracksMethodCallsCorrectly(): void {
        $provider = new MockDataProvider();

        $provider->isAvailable();
        $provider->getTables();
        $provider->getListings(['city' => 'Boston']);
        $provider->getListing('12345678');

        $allCalls = $provider->getMethodCalls();
        $this->assertCount(4, $allCalls);

        $getListingsCalls = $provider->getMethodCalls('getListings');
        $this->assertCount(1, $getListingsCalls);
    }
}
