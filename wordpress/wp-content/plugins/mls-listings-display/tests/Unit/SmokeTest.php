<?php
/**
 * Smoke Test - Verify Test Infrastructure Works
 *
 * @package MLSDisplay\Tests\Unit
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit;

use MLSDisplay\Tests\Fixtures\ListingData;
use MLSDisplay\Tests\Mocks\MockDataProvider;

/**
 * Basic smoke tests to verify the testing infrastructure is working
 */
class SmokeTest extends MLD_Unit_TestCase {

    /**
     * Test that PHPUnit and Brain Monkey are properly set up
     */
    public function testPHPUnitSetup(): void {
        $this->assertTrue(true, 'PHPUnit is working');
    }

    /**
     * Test that Brain Monkey function stubs work
     */
    public function testBrainMonkeyFunctionStubs(): void {
        // Test that our stub functions work
        $result = sanitize_text_field('  Hello World  ');
        $this->assertEquals('Hello World', $result);

        $result = esc_html('<script>alert("xss")</script>');
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    /**
     * Test that WordPress option functions work
     */
    public function testOptionFunctions(): void {
        // Test default value
        $result = get_option('nonexistent_option', 'default');
        $this->assertEquals('default', $result);

        // Test setting and getting option
        update_option('test_option', 'test_value');
        $result = get_option('test_option');
        $this->assertEquals('test_value', $result);

        // Test deleting option
        delete_option('test_option');
        $result = get_option('test_option', 'default');
        $this->assertEquals('default', $result);
    }

    /**
     * Test that transient functions work
     */
    public function testTransientFunctions(): void {
        // Test default (no transient)
        $result = get_transient('nonexistent_transient');
        $this->assertFalse($result);

        // Test setting and getting transient
        set_transient('test_transient', ['key' => 'value']);
        $result = get_transient('test_transient');
        $this->assertEquals(['key' => 'value'], $result);

        // Test deleting transient
        delete_transient('test_transient');
        $result = get_transient('test_transient');
        $this->assertFalse($result);
    }

    /**
     * Test that cache functions work
     */
    public function testCacheFunctions(): void {
        // Test cache miss
        $found = false;
        $result = wp_cache_get('test_key', 'test_group', false, $found);
        $this->assertFalse($found);

        // Test cache set and get
        wp_cache_set('test_key', 'test_value', 'test_group');
        $found = false;
        $result = wp_cache_get('test_key', 'test_group', false, $found);
        $this->assertTrue($found);
        $this->assertEquals('test_value', $result);

        // Test cache delete
        wp_cache_delete('test_key', 'test_group');
        $found = false;
        $result = wp_cache_get('test_key', 'test_group', false, $found);
        $this->assertFalse($found);
    }

    /**
     * Test that nonce functions work
     */
    public function testNonceFunctions(): void {
        $nonce = wp_create_nonce('test_action');
        $this->assertNotEmpty($nonce);

        // Valid nonce should verify
        $result = wp_verify_nonce($nonce, 'test_action');
        $this->assertEquals(1, $result);

        // Invalid nonce should fail
        $result = wp_verify_nonce('invalid_nonce', 'test_action');
        $this->assertFalse($result);
    }

    /**
     * Test that user functions work
     */
    public function testUserFunctions(): void {
        // Default: no user logged in
        $this->assertEquals(0, get_current_user_id());
        $this->assertFalse(is_user_logged_in());

        // Set admin user
        $this->setAdminUser();
        $this->assertEquals(1, get_current_user_id());
        $this->assertTrue(is_user_logged_in());
        $this->assertTrue(current_user_can('manage_options'));

        // Set subscriber user
        $this->setSubscriberUser();
        $this->assertEquals(2, get_current_user_id());
        $this->assertTrue(current_user_can('read'));
        $this->assertFalse(current_user_can('manage_options'));
    }

    /**
     * Test that WP_Error class works
     */
    public function testWPErrorClass(): void {
        $error = new \WP_Error('test_code', 'Test error message', ['extra' => 'data']);

        $this->assertTrue(is_wp_error($error));
        $this->assertEquals('test_code', $error->get_error_code());
        $this->assertEquals('Test error message', $error->get_error_message());
        $this->assertEquals(['extra' => 'data'], $error->get_error_data());
        $this->assertTrue($error->has_errors());

        // Not a WP_Error
        $this->assertFalse(is_wp_error('not an error'));
        $this->assertFalse(is_wp_error(null));
    }

    /**
     * Test that test fixtures load correctly
     */
    public function testListingDataFixtures(): void {
        $listing = ListingData::getSingleFamilyListing();

        $this->assertIsArray($listing);
        $this->assertArrayHasKey('listing_id', $listing);
        $this->assertArrayHasKey('listing_key', $listing);
        $this->assertArrayHasKey('standard_status', $listing);
        $this->assertArrayHasKey('list_price', $listing);
        $this->assertArrayHasKey('city', $listing);
        $this->assertArrayHasKey('latitude', $listing);
        $this->assertArrayHasKey('longitude', $listing);

        // Test specific values
        $this->assertEquals('Active', $listing['standard_status']);
        $this->assertEquals('Residential', $listing['property_type']);
        $this->assertEquals('Boston', $listing['city']);
    }

    /**
     * Test that MockDataProvider works
     */
    public function testMockDataProvider(): void {
        $provider = new MockDataProvider();

        // Test availability
        $this->assertTrue($provider->isAvailable());

        // Test table prefix
        $this->assertEquals('wp_test_bme_', $provider->getTablePrefix());

        // Test tables
        $tables = $provider->getTables();
        $this->assertIsArray($tables);
        $this->assertArrayHasKey('listings', $tables);

        // Test get listings
        $listings = $provider->getListings();
        $this->assertIsArray($listings);
        $this->assertNotEmpty($listings);

        // Test get single listing
        $firstListing = $listings[0];
        $listingId = (string) $firstListing['listing_id'];
        $retrieved = $provider->getListing($listingId);
        $this->assertEquals($firstListing['listing_id'], $retrieved['listing_id']);

        // Test listing count
        $count = $provider->getListingCount();
        $this->assertGreaterThan(0, $count);
    }

    /**
     * Test MockDataProvider filtering
     */
    public function testMockDataProviderFiltering(): void {
        $provider = new MockDataProvider();

        // Test status filter
        $activeListings = $provider->getListings(['standard_status' => 'Active']);
        foreach ($activeListings as $listing) {
            $this->assertEquals('Active', $listing['standard_status']);
        }

        // Test city filter
        $bostonListings = $provider->getListings(['city' => 'Boston']);
        foreach ($bostonListings as $listing) {
            $this->assertEquals('Boston', $listing['city']);
        }
    }

    /**
     * Test MockDataProvider can be disabled
     */
    public function testMockDataProviderDisabled(): void {
        $provider = new MockDataProvider();
        $provider->setAvailable(false);

        $this->assertFalse($provider->isAvailable());
        $this->assertNull($provider->getTables());
        $this->assertEmpty($provider->getListings());
        $this->assertNull($provider->getListing('12345678'));
        $this->assertEquals(0, $provider->getListingCount());
    }

    /**
     * Test base test case helper methods
     */
    public function testBaseTestCaseHelpers(): void {
        // Test setOption helper
        $this->setOption('mld_test_option', 'test_value');
        $this->assertEquals('test_value', get_option('mld_test_option'));

        // Test setOptions helper
        $this->setOptions([
            'option_a' => 'value_a',
            'option_b' => 'value_b',
        ]);
        $this->assertEquals('value_a', get_option('option_a'));
        $this->assertEquals('value_b', get_option('option_b'));

        // Test getSampleListing helper
        $listing = $this->getSampleListing();
        $this->assertIsArray($listing);
        $this->assertArrayHasKey('listing_id', $listing);

        // Test getSampleListings helper
        $listings = $this->getSampleListings(5);
        $this->assertCount(5, $listings);
    }

    /**
     * Test WordPress internationalization stubs
     */
    public function testI18nFunctions(): void {
        // __() should return the input string
        $result = __('Hello World', 'mls-listings-display');
        $this->assertEquals('Hello World', $result);

        // esc_html__() should escape and return
        $result = esc_html__('<script>test</script>', 'mls-listings-display');
        $this->assertStringContainsString('&lt;', $result);
    }

    /**
     * Test WordPress utility functions
     */
    public function testUtilityFunctions(): void {
        // absint
        $this->assertEquals(42, absint(-42));
        $this->assertEquals(42, absint('42'));
        $this->assertEquals(0, absint('abc'));

        // trailingslashit
        $this->assertEquals('/path/to/dir/', trailingslashit('/path/to/dir'));
        $this->assertEquals('/path/to/dir/', trailingslashit('/path/to/dir/'));

        // untrailingslashit
        $this->assertEquals('/path/to/dir', untrailingslashit('/path/to/dir/'));

        // wp_parse_args
        $result = wp_parse_args(['a' => 1], ['a' => 0, 'b' => 2]);
        $this->assertEquals(['a' => 1, 'b' => 2], $result);
    }

    /**
     * Test that JSON response tracking works
     */
    public function testJsonResponseTracking(): void {
        // Simulate sending JSON success
        wp_send_json_success(['message' => 'Success']);

        $response = $this->getJsonResponse();
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals(['message' => 'Success'], $response['data']);
    }

    /**
     * Test that constants are defined correctly
     */
    public function testConstantsAreDefined(): void {
        $this->assertTrue(defined('ABSPATH'));
        $this->assertTrue(defined('MLD_PLUGIN_PATH'));
        $this->assertTrue(defined('MLD_VERSION'));
        $this->assertTrue(defined('WP_DEBUG'));

        $this->assertEquals('6.10.6', MLD_VERSION);
    }
}
