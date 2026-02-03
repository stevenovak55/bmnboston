<?php
/**
 * Base Test Case for MLS Listings Display Unit Tests
 *
 * Provides common setup, teardown, and helper methods for unit tests.
 *
 * @package MLSDisplay\Tests\Unit
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;
use MLSDisplay\Tests\Mocks\MockDataProvider;
use MLSDisplay\Tests\Fixtures\ListingData;

/**
 * Base test case for MLS Listings Display unit tests
 *
 * Extends PHPUnit TestCase with Brain Monkey integration for WordPress function mocking.
 */
abstract class MLD_Unit_TestCase extends TestCase {

    /**
     * Mock data provider instance
     * @var MockDataProvider|null
     */
    protected ?MockDataProvider $mockDataProvider = null;

    /**
     * Set up the test environment before each test
     */
    protected function setUp(): void {
        parent::setUp();

        // Set up Brain Monkey
        Monkey\setUp();

        // Reset global test data
        $this->resetTestData();

        // Initialize mock data provider
        $this->mockDataProvider = new MockDataProvider();
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void {
        // Tear down Brain Monkey
        Monkey\tearDown();

        // Close Mockery
        Mockery::close();

        // Reset mock data provider
        $this->mockDataProvider = null;

        // Reset test data
        $this->resetTestData();

        parent::tearDown();
    }

    /**
     * Reset all global test data
     */
    protected function resetTestData(): void {
        global $_wp_cache_test_data, $_wp_transients_test_data, $_wp_options_test_data;
        global $_wp_test_current_user_id, $_wp_test_user_capabilities;
        global $_wp_test_json_response, $_wp_test_die_called;

        $_wp_cache_test_data = [];
        $_wp_transients_test_data = [];
        $_wp_options_test_data = [];
        $_wp_test_current_user_id = 0;
        $_wp_test_user_capabilities = [];
        $_wp_test_json_response = null;
        $_wp_test_die_called = null;
    }

    /**
     * Set a test option value
     *
     * @param string $option Option name
     * @param mixed $value Option value
     */
    protected function setOption(string $option, $value): void {
        global $_wp_options_test_data;
        if (!isset($_wp_options_test_data)) {
            $_wp_options_test_data = [];
        }
        $_wp_options_test_data[$option] = $value;
    }

    /**
     * Set multiple test options
     *
     * @param array $options Associative array of option => value
     */
    protected function setOptions(array $options): void {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * Set cache value for testing
     *
     * @param string $key Cache key
     * @param mixed $value Cache value
     * @param string $group Cache group
     */
    protected function setCacheValue(string $key, $value, string $group = ''): void {
        global $_wp_cache_test_data;
        if (!isset($_wp_cache_test_data)) {
            $_wp_cache_test_data = [];
        }
        if (!isset($_wp_cache_test_data[$group])) {
            $_wp_cache_test_data[$group] = [];
        }
        $_wp_cache_test_data[$group][$key] = $value;
    }

    /**
     * Set transient value for testing
     *
     * @param string $transient Transient name
     * @param mixed $value Transient value
     */
    protected function setTransient(string $transient, $value): void {
        global $_wp_transients_test_data;
        if (!isset($_wp_transients_test_data)) {
            $_wp_transients_test_data = [];
        }
        $_wp_transients_test_data[$transient] = $value;
    }

    /**
     * Set the current test user
     *
     * @param int $userId User ID
     * @param array $capabilities User capabilities
     */
    protected function setCurrentUser(int $userId, array $capabilities = []): void {
        global $_wp_test_current_user_id, $_wp_test_user_capabilities;
        $_wp_test_current_user_id = $userId;
        $_wp_test_user_capabilities = $capabilities;
    }

    /**
     * Set an admin user for testing
     */
    protected function setAdminUser(): void {
        $this->setCurrentUser(1, [
            'manage_options' => true,
            'edit_posts' => true,
            'edit_others_posts' => true,
            'edit_plugins' => true,
            'activate_plugins' => true,
            'administrator' => true,
        ]);
    }

    /**
     * Set a subscriber user for testing
     */
    protected function setSubscriberUser(): void {
        $this->setCurrentUser(2, [
            'read' => true,
        ]);
    }

    /**
     * Get the last JSON response sent during the test
     *
     * @return mixed
     */
    protected function getJsonResponse() {
        global $_wp_test_json_response;
        return $_wp_test_json_response;
    }

    /**
     * Get info about whether wp_die was called
     *
     * @return array|null
     */
    protected function getDieInfo(): ?array {
        global $_wp_test_die_called;
        return $_wp_test_die_called;
    }

    /**
     * Assert that wp_die was called
     *
     * @param string|null $expectedMessage Expected message (optional)
     */
    protected function assertWpDieCalled(?string $expectedMessage = null): void {
        $dieInfo = $this->getDieInfo();
        $this->assertNotNull($dieInfo, 'Expected wp_die to be called');

        if ($expectedMessage !== null) {
            $this->assertEquals($expectedMessage, $dieInfo['message']);
        }
    }

    /**
     * Assert that wp_die was not called
     */
    protected function assertWpDieNotCalled(): void {
        $dieInfo = $this->getDieInfo();
        $this->assertNull($dieInfo, 'Expected wp_die not to be called');
    }

    /**
     * Assert JSON success response
     *
     * @param mixed $expectedData Expected data (optional)
     */
    protected function assertJsonSuccess($expectedData = null): void {
        $response = $this->getJsonResponse();
        $this->assertNotNull($response, 'Expected JSON response');
        $this->assertArrayHasKey('success', $response, 'Response should have success key');
        $this->assertTrue($response['success'], 'Response should indicate success');

        if ($expectedData !== null) {
            $this->assertEquals($expectedData, $response['data']);
        }
    }

    /**
     * Assert JSON error response
     *
     * @param mixed $expectedData Expected error data (optional)
     */
    protected function assertJsonError($expectedData = null): void {
        $response = $this->getJsonResponse();
        $this->assertNotNull($response, 'Expected JSON response');
        $this->assertArrayHasKey('success', $response, 'Response should have success key');
        $this->assertFalse($response['success'], 'Response should indicate error');

        if ($expectedData !== null) {
            $this->assertEquals($expectedData, $response['data']);
        }
    }

    /**
     * Get a sample listing for testing
     *
     * @return array
     */
    protected function getSampleListing(): array {
        return ListingData::getSingleFamilyListing();
    }

    /**
     * Get sample listings for testing
     *
     * @param int $count Number of listings
     * @return array
     */
    protected function getSampleListings(int $count = 10): array {
        return ListingData::getActiveListings($count);
    }

    /**
     * Mock a WordPress function with Brain Monkey
     *
     * @param string $function Function name
     * @return \Brain\Monkey\Expectation\Expectation
     */
    protected function mockFunction(string $function) {
        return Monkey\Functions\expect($function);
    }

    /**
     * Stub a WordPress function to return a value
     *
     * @param string $function Function name
     * @param mixed $returnValue Value to return
     */
    protected function stubFunction(string $function, $returnValue): void {
        Monkey\Functions\stubs([
            $function => $returnValue,
        ]);
    }

    /**
     * Stub multiple WordPress functions
     *
     * @param array $stubs Associative array of function => return value
     */
    protected function stubFunctions(array $stubs): void {
        Monkey\Functions\stubs($stubs);
    }

    /**
     * Create a mock wpdb object
     *
     * @return Mockery\MockInterface
     */
    protected function createMockWpdb() {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->options = 'wp_options';
        $wpdb->users = 'wp_users';
        $wpdb->usermeta = 'wp_usermeta';

        return $wpdb;
    }

    /**
     * Assert that an array has all specified keys
     *
     * @param array $expectedKeys
     * @param array $array
     * @param string $message
     */
    protected function assertArrayHasAllKeys(array $expectedKeys, array $array, string $message = ''): void {
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Expected array to have key: {$key}");
        }
    }

    /**
     * Assert that a listing has required fields
     *
     * @param array $listing
     */
    protected function assertValidListing(array $listing): void {
        $requiredKeys = ['listing_id', 'listing_key', 'standard_status', 'list_price'];
        $this->assertArrayHasAllKeys($requiredKeys, $listing, 'Listing missing required field');
    }

    /**
     * Assert that a listing has map coordinates
     *
     * @param array $listing
     */
    protected function assertListingHasCoordinates(array $listing): void {
        $this->assertArrayHasKey('latitude', $listing);
        $this->assertArrayHasKey('longitude', $listing);
        $this->assertNotNull($listing['latitude'], 'Latitude should not be null');
        $this->assertNotNull($listing['longitude'], 'Longitude should not be null');
    }

    /**
     * Helper to simulate POST request data
     *
     * @param array $data POST data
     */
    protected function simulatePostRequest(array $data): void {
        $_POST = $data;
        $_REQUEST = array_merge($_REQUEST ?? [], $data);
    }

    /**
     * Helper to simulate GET request data
     *
     * @param array $data GET data
     */
    protected function simulateGetRequest(array $data): void {
        $_GET = $data;
        $_REQUEST = array_merge($_REQUEST ?? [], $data);
    }

    /**
     * Helper to clear request data
     */
    protected function clearRequestData(): void {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * Set AJAX request context
     */
    protected function setAjaxContext(): void {
        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }
    }
}
