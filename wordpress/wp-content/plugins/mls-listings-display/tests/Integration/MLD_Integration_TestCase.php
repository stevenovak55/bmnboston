<?php
/**
 * Base Test Case for MLS Listings Display Integration Tests
 *
 * Provides setup for tests that need more realistic WordPress behavior.
 *
 * @package MLSDisplay\Tests\Integration
 * @since 6.14.11
 */

namespace MLSDisplay\Tests\Integration;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;
use Mockery;

/**
 * Base test case for MLS Listings Display integration tests
 */
abstract class MLD_Integration_TestCase extends MLD_Unit_TestCase {

    /**
     * Mock wpdb instance
     * @var \Mockery\MockInterface
     */
    protected $wpdb;

    /**
     * Track registered tables
     * @var array
     */
    protected array $registeredTables = [];

    /**
     * Track query results to return
     * @var array
     */
    protected array $queryResults = [];

    /**
     * Set up the integration test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Create mock wpdb
        $this->wpdb = $this->createIntegrationMockWpdb();

        // Make it available globally
        $GLOBALS['wpdb'] = $this->wpdb;

        // Set up default plugin options
        $this->setDefaultPluginOptions();
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void {
        // Clear globals
        unset($GLOBALS['wpdb']);

        // Reset query results
        $this->queryResults = [];
        $this->registeredTables = [];

        parent::tearDown();
    }

    /**
     * Create a comprehensive mock wpdb for integration testing
     *
     * @return \Mockery\MockInterface
     */
    protected function createIntegrationMockWpdb(): \Mockery\MockInterface {
        $wpdb = Mockery::mock('wpdb');

        // Set up table prefixes
        $wpdb->prefix = 'wp_';
        $wpdb->base_prefix = 'wp_';

        // Standard WordPress tables
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->options = 'wp_options';
        $wpdb->users = 'wp_users';
        $wpdb->usermeta = 'wp_usermeta';
        $wpdb->terms = 'wp_terms';
        $wpdb->term_taxonomy = 'wp_term_taxonomy';

        // Allow prepare calls
        $wpdb->shouldReceive('prepare')
            ->andReturnUsing(function ($query, ...$args) {
                // Simple prepare simulation
                $prepared = $query;
                foreach ($args as $arg) {
                    if (is_array($arg)) {
                        $arg = $arg[0] ?? '';
                    }
                    $prepared = preg_replace('/%[sdfib]/', $this->wpdb->_real_escape($arg), $prepared, 1);
                }
                return $prepared;
            });

        // Allow escape
        $wpdb->shouldReceive('_real_escape')
            ->andReturnUsing(function ($string) {
                return addslashes($string);
            });

        // Allow get_var
        $wpdb->shouldReceive('get_var')
            ->andReturnUsing(function ($query) {
                return $this->handleQuery('get_var', $query);
            });

        // Allow get_row
        $wpdb->shouldReceive('get_row')
            ->andReturnUsing(function ($query, $output = OBJECT) {
                return $this->handleQuery('get_row', $query);
            });

        // Allow get_results
        $wpdb->shouldReceive('get_results')
            ->andReturnUsing(function ($query, $output = OBJECT) {
                return $this->handleQuery('get_results', $query);
            });

        // Allow get_col
        $wpdb->shouldReceive('get_col')
            ->andReturnUsing(function ($query) {
                return $this->handleQuery('get_col', $query);
            });

        // Allow query
        $wpdb->shouldReceive('query')
            ->andReturn(true);

        // Allow insert
        $wpdb->shouldReceive('insert')
            ->andReturn(1);

        // Allow update
        $wpdb->shouldReceive('update')
            ->andReturn(1);

        // Allow delete
        $wpdb->shouldReceive('delete')
            ->andReturn(1);

        // Allow replace
        $wpdb->shouldReceive('replace')
            ->andReturn(1);

        // Insert ID tracking
        $wpdb->insert_id = 1;

        // Error tracking
        $wpdb->last_error = '';

        return $wpdb;
    }

    /**
     * Handle query and return registered result
     *
     * @param string $type Query type
     * @param string $query SQL query
     * @return mixed
     */
    protected function handleQuery(string $type, string $query) {
        // Check for registered query results
        foreach ($this->queryResults as $pattern => $result) {
            if (stripos($query, $pattern) !== false) {
                return $result;
            }
        }

        // Default returns
        switch ($type) {
            case 'get_var':
                return null;
            case 'get_row':
                return null;
            case 'get_results':
                return [];
            case 'get_col':
                return [];
            default:
                return null;
        }
    }

    /**
     * Register a query result to return
     *
     * @param string $queryPattern Pattern to match in query
     * @param mixed $result Result to return
     */
    protected function registerQueryResult(string $queryPattern, $result): void {
        $this->queryResults[$queryPattern] = $result;
    }

    /**
     * Register that a table exists
     *
     * @param string $tableName Table name
     */
    protected function registerTableExists(string $tableName): void {
        $this->registeredTables[] = $tableName;
        $this->registerQueryResult("SHOW TABLES LIKE '{$tableName}'", $tableName);
    }

    /**
     * Register multiple tables as existing
     *
     * @param array $tableNames Table names
     */
    protected function registerTablesExist(array $tableNames): void {
        foreach ($tableNames as $table) {
            $this->registerTableExists($table);
        }
    }

    /**
     * Set default plugin options for testing
     */
    protected function setDefaultPluginOptions(): void {
        $this->setOptions([
            'mld_db_version' => '6.14.11',
            'mld_plugin_version' => '6.14.11',
            'mld_settings' => [
                'map_enabled' => true,
                'default_zoom' => 12,
                'listings_per_page' => 20,
            ],
        ]);
    }

    /**
     * Get the expected MLD tables
     *
     * @return array
     */
    protected function getExpectedMldTables(): array {
        return [
            'wp_mld_saved_searches',
            'wp_mld_notification_queue',
            'wp_mld_notification_history',
            'wp_mld_cma_reports',
            'wp_mld_property_analytics',
            'wp_mld_session_tracking',
            'wp_mld_search_analytics',
        ];
    }

    /**
     * Get the expected BME tables (dependency)
     *
     * @return array
     */
    protected function getExpectedBmeTables(): array {
        return [
            'wp_bme_listings',
            'wp_bme_listing_details',
            'wp_bme_listing_location',
            'wp_bme_listing_financial',
            'wp_bme_listing_features',
            'wp_bme_listing_summary',
            'wp_bme_media',
            'wp_bme_rooms',
            'wp_bme_open_houses',
            'wp_bme_virtual_tours',
            'wp_bme_agents',
            'wp_bme_offices',
        ];
    }

    /**
     * Register all expected tables as existing
     */
    protected function registerAllTablesExist(): void {
        $this->registerTablesExist($this->getExpectedMldTables());
        $this->registerTablesExist($this->getExpectedBmeTables());
    }

    /**
     * Create a sample listing array for testing
     *
     * @param array $overrides Override default values
     * @return array
     */
    protected function createListingData(array $overrides = []): array {
        $defaults = [
            'listing_id' => 'MLS123456',
            'listing_key' => 'key_123456',
            'standard_status' => 'Active',
            'list_price' => 500000,
            'property_type' => 'Residential',
            'property_sub_type' => 'Single Family Residence',
            'bedrooms_total' => 3,
            'bathrooms_total' => 2,
            'living_area' => 1800,
            'lot_size_acres' => 0.25,
            'year_built' => 2010,
            'city' => 'Boston',
            'state_or_province' => 'MA',
            'postal_code' => '02101',
            'street_number' => '123',
            'street_name' => 'Main Street',
            'latitude' => 42.3601,
            'longitude' => -71.0589,
            'list_agent_name' => 'Test Agent',
            'list_office_name' => 'Test Office',
            'modification_timestamp' => date('Y-m-d H:i:s'),
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create multiple listing data arrays
     *
     * @param int $count Number of listings
     * @return array
     */
    protected function createMultipleListings(int $count): array {
        $listings = [];
        for ($i = 1; $i <= $count; $i++) {
            $listings[] = $this->createListingData([
                'listing_id' => "MLS{$i}",
                'listing_key' => "key_{$i}",
                'list_price' => 400000 + ($i * 50000),
                'street_number' => (string) (100 + $i),
            ]);
        }
        return $listings;
    }

    /**
     * Simulate an AJAX request
     *
     * @param string $action AJAX action name
     * @param array $data POST data
     * @param bool $asAdmin Whether to run as admin
     * @return mixed The JSON response
     */
    protected function doAjaxRequest(string $action, array $data = [], bool $asAdmin = true): mixed {
        // Set up user context
        if ($asAdmin) {
            $this->setAdminUser();
        }

        // Add nonce
        $nonce = wp_create_nonce($action);
        $data['_ajax_nonce'] = $nonce;
        $data['action'] = $action;

        // Simulate POST request
        $this->simulatePostRequest($data);

        // Define DOING_AJAX if not defined
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }

        return $this->getJsonResponse();
    }
}
