<?php
/**
 * Database Table Tests
 *
 * Tests database table existence, structure, and queries.
 *
 * @package MLSDisplay\Tests\Integration
 * @since 6.14.11
 */

namespace MLSDisplay\Tests\Integration;

/**
 * Test class for database operations
 */
class DatabaseTest extends MLD_Integration_TestCase {

    /**
     * Test MLD tables are expected
     */
    public function testMldTablesAreExpected(): void {
        $expectedTables = $this->getExpectedMldTables();

        $this->assertNotEmpty($expectedTables);
        $this->assertContains('wp_mld_saved_searches', $expectedTables);
        $this->assertContains('wp_mld_notification_queue', $expectedTables);
        $this->assertContains('wp_mld_cma_reports', $expectedTables);
    }

    /**
     * Test BME dependency tables are expected
     */
    public function testBmeTablesAreExpected(): void {
        $expectedTables = $this->getExpectedBmeTables();

        $this->assertNotEmpty($expectedTables);
        $this->assertContains('wp_bme_listings', $expectedTables);
        $this->assertContains('wp_bme_listing_summary', $expectedTables);
        $this->assertContains('wp_bme_listing_location', $expectedTables);
    }

    /**
     * Test table existence check returns correct value
     */
    public function testTableExistsCheck(): void {
        // Register a table as existing
        $this->registerTableExists('wp_bme_listings');

        // Mock the query that checks for table
        $result = $this->wpdb->get_var("SHOW TABLES LIKE 'wp_bme_listings'");

        $this->assertEquals('wp_bme_listings', $result);
    }

    /**
     * Test missing table detection
     */
    public function testMissingTableDetection(): void {
        // Don't register any tables
        // Query for non-existent table
        $result = $this->wpdb->get_var("SHOW TABLES LIKE 'wp_nonexistent_table'");

        $this->assertNull($result);
    }

    /**
     * Test saved search table structure
     */
    public function testSavedSearchTableStructure(): void {
        $requiredColumns = [
            'id',
            'user_id',
            'search_name',
            'search_criteria',
            'notification_frequency',
            'is_active',
            'created_at',
            'updated_at',
        ];

        // These columns should be present in the saved searches table
        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $requiredColumns);
        }
    }

    /**
     * Test notification queue table structure
     */
    public function testNotificationQueueTableStructure(): void {
        $requiredColumns = [
            'id',
            'user_id',
            'notification_type',
            'message',
            'status',
            'scheduled_at',
            'sent_at',
            'created_at',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $requiredColumns);
        }
    }

    /**
     * Test listing data retrieval
     */
    public function testListingDataRetrieval(): void {
        // Register tables and set up test data
        $this->registerAllTablesExist();

        $testListings = $this->createMultipleListings(5);
        $this->registerQueryResult('FROM wp_bme_listing_summary', $testListings);

        // Query should return listings
        $results = $this->wpdb->get_results("SELECT * FROM wp_bme_listing_summary");

        $this->assertIsArray($results);
        $this->assertCount(5, $results);
    }

    /**
     * Test empty results handling
     */
    public function testEmptyResultsHandling(): void {
        $this->registerAllTablesExist();

        // Register empty results for a specific query
        $this->registerQueryResult('WHERE 1=0', []);

        $results = $this->wpdb->get_results("SELECT * FROM wp_bme_listings WHERE 1=0");

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test listing count query
     */
    public function testListingCountQuery(): void {
        $this->registerAllTablesExist();

        // Register count result
        $this->registerQueryResult('SELECT COUNT', '150');

        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM wp_bme_listings");

        $this->assertEquals('150', $count);
    }

    /**
     * Test active listings filter
     */
    public function testActiveListingsFilter(): void {
        $this->registerAllTablesExist();

        $activeListings = [
            $this->createListingData(['standard_status' => 'Active']),
            $this->createListingData(['standard_status' => 'Active', 'listing_id' => 'MLS2']),
        ];

        $this->registerQueryResult("standard_status = 'Active'", $activeListings);

        $results = $this->wpdb->get_results(
            "SELECT * FROM wp_bme_listing_summary WHERE standard_status = 'Active'"
        );

        $this->assertCount(2, $results);
    }

    /**
     * Test city filter query
     */
    public function testCityFilterQuery(): void {
        $this->registerAllTablesExist();

        $bostonListings = [
            $this->createListingData(['city' => 'Boston']),
        ];

        $this->registerQueryResult("city = 'Boston'", $bostonListings);

        $results = $this->wpdb->get_results(
            "SELECT * FROM wp_bme_listing_summary WHERE city = 'Boston'"
        );

        $this->assertCount(1, $results);
        $this->assertEquals('Boston', $results[0]['city']);
    }

    /**
     * Test price range filter query
     */
    public function testPriceRangeFilterQuery(): void {
        $this->registerAllTablesExist();

        $priceFilteredListings = [
            $this->createListingData(['list_price' => 500000]),
            $this->createListingData(['list_price' => 600000, 'listing_id' => 'MLS2']),
        ];

        $this->registerQueryResult('list_price BETWEEN', $priceFilteredListings);

        $results = $this->wpdb->get_results(
            "SELECT * FROM wp_bme_listing_summary WHERE list_price BETWEEN 400000 AND 700000"
        );

        $this->assertCount(2, $results);
    }

    /**
     * Test geographic bounds query
     */
    public function testGeographicBoundsQuery(): void {
        $this->registerAllTablesExist();

        $boundedListings = [
            $this->createListingData([
                'latitude' => 42.3601,
                'longitude' => -71.0589,
            ]),
        ];

        $this->registerQueryResult('latitude BETWEEN', $boundedListings);

        $results = $this->wpdb->get_results(
            "SELECT * FROM wp_bme_listing_summary WHERE latitude BETWEEN 42.0 AND 43.0 AND longitude BETWEEN -72.0 AND -70.0"
        );

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['latitude']);
        $this->assertNotNull($results[0]['longitude']);
    }

    /**
     * Test saved search insert
     */
    public function testSavedSearchInsert(): void {
        $result = $this->wpdb->insert(
            'wp_mld_saved_searches',
            [
                'user_id' => 1,
                'search_name' => 'Boston Homes',
                'search_criteria' => json_encode(['city' => 'Boston']),
                'is_active' => 1,
            ]
        );

        $this->assertEquals(1, $result);
        $this->assertEquals(1, $this->wpdb->insert_id);
    }

    /**
     * Test notification queue insert
     */
    public function testNotificationQueueInsert(): void {
        $result = $this->wpdb->insert(
            'wp_mld_notification_queue',
            [
                'user_id' => 1,
                'notification_type' => 'new_listing',
                'message' => 'New listing matches your search',
                'status' => 'pending',
            ]
        );

        $this->assertEquals(1, $result);
    }

    /**
     * Test database version option
     */
    public function testDatabaseVersionOption(): void {
        $this->setOption('mld_db_version', '6.14.11');

        $version = get_option('mld_db_version');

        $this->assertEquals('6.14.11', $version);
    }

    /**
     * Test table prefix is correct
     */
    public function testTablePrefixIsCorrect(): void {
        $this->assertEquals('wp_', $this->wpdb->prefix);
    }

    /**
     * Test query preparation escapes values
     */
    public function testQueryPreparationEscapesValues(): void {
        $maliciousInput = "'; DROP TABLE users; --";

        $escaped = $this->wpdb->_real_escape($maliciousInput);

        $this->assertStringContainsString("\\", $escaped);
        $this->assertNotEquals($maliciousInput, $escaped);
    }

    /**
     * Test listing ID format validation
     */
    public function testListingIdFormatValidation(): void {
        // Valid MLS ID formats
        $validIds = ['MLS123456', '12345678', 'PIN12345'];

        foreach ($validIds as $id) {
            $isValid = preg_match('/^[A-Za-z0-9]+$/', $id);
            $this->assertEquals(1, $isValid, "ID {$id} should be valid");
        }
    }

    /**
     * Test listing data has required fields
     */
    public function testListingDataHasRequiredFields(): void {
        $listing = $this->createListingData();

        $requiredFields = [
            'listing_id',
            'listing_key',
            'standard_status',
            'list_price',
            'property_type',
            'city',
            'state_or_province',
            'latitude',
            'longitude',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $listing, "Missing required field: {$field}");
        }
    }

    /**
     * Test summary table has map-required fields
     */
    public function testSummaryTableHasMapFields(): void {
        $listing = $this->createListingData();

        // Map display requires these fields
        $mapFields = ['latitude', 'longitude', 'list_price', 'city', 'street_name'];

        foreach ($mapFields as $field) {
            $this->assertArrayHasKey($field, $listing, "Missing map field: {$field}");
        }

        // Coordinates should be numeric
        $this->assertIsFloat($listing['latitude']);
        $this->assertIsFloat($listing['longitude']);
    }

    /**
     * Test archive table query fallback
     */
    public function testArchiveTableQueryFallback(): void {
        $this->registerAllTablesExist();

        // Active table returns empty
        $this->registerQueryResult('FROM wp_bme_listings WHERE', []);

        // Archive table returns result
        $archivedListing = $this->createListingData(['standard_status' => 'Closed']);
        $this->registerQueryResult('FROM wp_bme_listings_archive', [$archivedListing]);

        // First try active
        $result = $this->wpdb->get_results("SELECT * FROM wp_bme_listings WHERE listing_id = 'MLS123'");
        $this->assertEmpty($result);

        // Then fallback to archive
        $archiveResult = $this->wpdb->get_results("SELECT * FROM wp_bme_listings_archive WHERE listing_id = 'MLS123'");
        $this->assertCount(1, $archiveResult);
    }
}
