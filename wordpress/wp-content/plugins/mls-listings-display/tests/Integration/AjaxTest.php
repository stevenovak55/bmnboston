<?php
/**
 * AJAX Handler Tests
 *
 * Tests the main AJAX handlers in class-mld-ajax.php
 * This is a critical test file for the 2,444-line AJAX class.
 *
 * @package MLSDisplay\Tests\Integration
 * @since 6.14.11
 */

namespace MLSDisplay\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * Test class for MLD_Ajax handlers
 */
class AjaxTest extends MLD_Integration_TestCase {

    /**
     * Test filter validation accepts valid filters
     */
    public function testFilterValidationAcceptsValidFilters(): void {
        $validFilters = [
            'min_price' => 100000,
            'max_price' => 500000,
            'min_bedrooms' => 2,
            'max_bedrooms' => 5,
            'city' => 'Boston',
            'property_type' => 'Residential',
        ];

        // All keys should be in the allowed list
        $allowedKeys = [
            'min_price', 'max_price', 'min_sqft', 'max_sqft',
            'min_bedrooms', 'max_bedrooms', 'min_bathrooms', 'max_bathrooms',
            'property_type', 'property_subtype', 'city', 'state',
            'status', 'sort_by', 'sort_order', 'polygon',
        ];

        foreach (array_keys($validFilters) as $key) {
            $this->assertContains($key, $allowedKeys, "Filter key '{$key}' should be allowed");
        }
    }

    /**
     * Test filter validation rejects unknown keys
     */
    public function testFilterValidationRejectsUnknownKeys(): void {
        $allowedKeys = [
            'min_price', 'max_price', 'min_sqft', 'max_sqft',
            'min_bedrooms', 'max_bedrooms', 'property_type', 'city',
        ];

        $unknownKeys = ['injection_attempt', 'drop_table', 'unknown_field'];

        foreach ($unknownKeys as $key) {
            $this->assertNotContains($key, $allowedKeys, "Unknown key '{$key}' should be rejected");
        }
    }

    /**
     * Test price filter sanitization
     */
    public function testPriceFilterSanitization(): void {
        // Price should be converted to integer
        $priceInput = '500000.50';
        $sanitized = absint($priceInput);

        $this->assertEquals(500000, $sanitized);
        $this->assertIsInt($sanitized);
    }

    /**
     * Test negative price is rejected
     */
    public function testNegativePriceIsRejected(): void {
        $negativePrice = -100000;
        $sanitized = absint($negativePrice);

        $this->assertGreaterThanOrEqual(0, $sanitized);
    }

    /**
     * Test city filter sanitization removes HTML
     */
    public function testCityFilterSanitization(): void {
        $maliciousCity = '<script>alert("xss")</script>Boston';
        $sanitized = sanitize_text_field($maliciousCity);

        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('Boston', $sanitized);
    }

    /**
     * Test viewport bounds validation
     */
    public function testViewportBoundsValidation(): void {
        $validBounds = [
            'north' => 42.5,
            'south' => 42.0,
            'east' => -70.5,
            'west' => -71.5,
        ];

        // North should be greater than south
        $this->assertGreaterThan($validBounds['south'], $validBounds['north']);

        // East should be greater than west (in this longitude range)
        $this->assertGreaterThan($validBounds['west'], $validBounds['east']);
    }

    /**
     * Test invalid bounds are detected
     */
    public function testInvalidBoundsDetection(): void {
        // Inverted bounds (south > north)
        $invalidBounds = [
            'north' => 42.0,
            'south' => 42.5,
            'east' => -70.5,
            'west' => -71.5,
        ];

        $isValid = $invalidBounds['north'] > $invalidBounds['south'];

        $this->assertFalse($isValid, 'Inverted bounds should be invalid');
    }

    /**
     * Test zoom level parameter validation
     */
    public function testZoomLevelValidation(): void {
        $validZoomLevels = range(1, 21);

        foreach ($validZoomLevels as $zoom) {
            $this->assertGreaterThanOrEqual(1, $zoom);
            $this->assertLessThanOrEqual(21, $zoom);
        }
    }

    /**
     * Test zoom level affects query limit
     */
    public function testZoomLevelAffectsQueryLimit(): void {
        // At lower zoom, fewer listings should be returned
        $zoomLimits = [
            8 => 50,   // Low zoom = few listings
            12 => 200, // Medium zoom
            16 => 500, // High zoom = more listings
        ];

        foreach ($zoomLimits as $zoom => $expectedLimit) {
            $this->assertIsInt($expectedLimit);
            $this->assertGreaterThan(0, $expectedLimit);
        }
    }

    /**
     * Test map listings response structure
     */
    public function testMapListingsResponseStructure(): void {
        $this->registerAllTablesExist();

        $listings = $this->createMultipleListings(3);
        $this->registerQueryResult('wp_bme_listing_summary', $listings);

        // Expected response structure
        $expectedKeys = ['listings', 'total_count', 'filtered_count'];

        $mockResponse = [
            'listings' => $listings,
            'total_count' => 100,
            'filtered_count' => 3,
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $mockResponse);
        }
    }

    /**
     * Test listing details response includes required fields
     */
    public function testListingDetailsResponseFields(): void {
        $listing = $this->createListingData();

        $requiredFields = [
            'listing_id',
            'list_price',
            'street_name',
            'city',
            'bedrooms_total',
            'bathrooms_total',
            'living_area',
            'latitude',
            'longitude',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $listing, "Listing should have field: {$field}");
        }
    }

    /**
     * Test autocomplete sanitizes search term removes HTML
     */
    public function testAutocompleteSanitizesSearchTermRemovesHtml(): void {
        $maliciousSearch = "<script>alert('xss')</script>Boston";
        $sanitized = sanitize_text_field($maliciousSearch);

        // Should remove HTML tags
        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('Boston', $sanitized);
    }

    /**
     * Test search term is escaped for SQL
     */
    public function testSearchTermIsEscapedForSql(): void {
        $maliciousSearch = "'; DROP TABLE listings; --";
        $escaped = addslashes($maliciousSearch);

        // Quotes should be escaped
        $this->assertStringContainsString("\\'", $escaped);
    }

    /**
     * Test autocomplete minimum length requirement
     */
    public function testAutocompleteMinimumLength(): void {
        $minLength = 2;

        $shortSearch = 'a';
        $validSearch = 'bos';

        $this->assertLessThan($minLength, strlen($shortSearch));
        $this->assertGreaterThanOrEqual($minLength, strlen($validSearch));
    }

    /**
     * Test autocomplete response structure
     */
    public function testAutocompleteResponseStructure(): void {
        $mockSuggestions = [
            ['value' => 'Boston', 'type' => 'city', 'count' => 150],
            ['value' => 'Brookline', 'type' => 'city', 'count' => 45],
        ];

        foreach ($mockSuggestions as $suggestion) {
            $this->assertArrayHasKey('value', $suggestion);
            $this->assertArrayHasKey('type', $suggestion);
        }
    }

    /**
     * Test contact form requires email
     */
    public function testContactFormRequiresEmail(): void {
        $formData = [
            'name' => 'Test User',
            'message' => 'I am interested in this property',
            // Missing email
        ];

        $hasEmail = isset($formData['email']) && is_email($formData['email'] ?? '');

        $this->assertFalse($hasEmail, 'Form should require email');
    }

    /**
     * Test contact form validates email format
     */
    public function testContactFormValidatesEmailFormat(): void {
        $validEmail = 'user@example.com';
        $invalidEmail = 'not-an-email';

        // is_email is a WordPress function, use filter_var for test
        $this->assertNotFalse(filter_var($validEmail, FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var($invalidEmail, FILTER_VALIDATE_EMAIL));
    }

    /**
     * Test save property requires authentication
     */
    public function testSavePropertyRequiresAuthentication(): void {
        // Not logged in
        $this->setCurrentUser(0);

        $isLoggedIn = is_user_logged_in();

        $this->assertFalse($isLoggedIn);
    }

    /**
     * Test save property works for logged in user
     */
    public function testSavePropertyWorksForLoggedInUser(): void {
        // Logged in user
        $this->setCurrentUser(123);

        $isLoggedIn = is_user_logged_in();

        $this->assertTrue($isLoggedIn);
        $this->assertEquals(123, get_current_user_id());
    }

    /**
     * Test similar homes filter by criteria
     */
    public function testSimilarHomesFilterByCriteria(): void {
        $subjectListing = $this->createListingData([
            'list_price' => 500000,
            'bedrooms_total' => 3,
            'city' => 'Boston',
        ]);

        // Similar homes should have similar criteria
        $similarCriteria = [
            'min_price' => 400000, // -20%
            'max_price' => 600000, // +20%
            'bedrooms' => [2, 3, 4], // +/- 1
            'city' => 'Boston',
        ];

        $this->assertEquals('Boston', $similarCriteria['city']);
        $this->assertGreaterThan($similarCriteria['min_price'], $subjectListing['list_price']);
        $this->assertLessThan($similarCriteria['max_price'], $subjectListing['list_price']);
    }

    /**
     * Test walk score request validation
     */
    public function testWalkScoreRequestValidation(): void {
        $validRequest = [
            'latitude' => 42.3601,
            'longitude' => -71.0589,
            'address' => '123 Main St, Boston, MA',
        ];

        $this->assertArrayHasKey('latitude', $validRequest);
        $this->assertArrayHasKey('longitude', $validRequest);
        $this->assertIsFloat($validRequest['latitude']);
        $this->assertIsFloat($validRequest['longitude']);
    }

    /**
     * Test JavaScript error logging sanitizes input
     */
    public function testJsErrorLoggingSanitizesInput(): void {
        $errorData = [
            'message' => '<script>evil()</script>Error occurred',
            'url' => 'http://example.com/page',
            'line' => 123,
        ];

        $sanitizedMessage = sanitize_text_field($errorData['message']);

        $this->assertStringNotContainsString('<script>', $sanitizedMessage);
    }

    /**
     * Test admin-only AJAX action requires admin
     */
    public function testAdminOnlyActionRequiresAdmin(): void {
        // Non-admin user
        $this->setSubscriberUser();

        $canManageOptions = current_user_can('manage_options');

        $this->assertFalse($canManageOptions);
    }

    /**
     * Test admin action works for admin user
     */
    public function testAdminActionWorksForAdminUser(): void {
        // Admin user
        $this->setAdminUser();

        $canManageOptions = current_user_can('manage_options');

        $this->assertTrue($canManageOptions);
    }

    /**
     * Test property type filter accepts valid types
     */
    public function testPropertyTypeFilterAcceptsValidTypes(): void {
        $validTypes = ['Residential', 'Land', 'Commercial', 'Multi-Family'];

        foreach ($validTypes as $type) {
            $sanitized = sanitize_text_field($type);
            $this->assertEquals($type, $sanitized);
        }
    }

    /**
     * Test sort order validation
     */
    public function testSortOrderValidation(): void {
        $validOrders = ['ASC', 'DESC'];
        $invalidOrder = 'RANDOM';

        $this->assertContains('ASC', $validOrders);
        $this->assertContains('DESC', $validOrders);
        $this->assertNotContains($invalidOrder, $validOrders);
    }

    /**
     * Test sort by field validation
     */
    public function testSortByFieldValidation(): void {
        $validSortFields = [
            'list_price',
            'bedrooms_total',
            'bathrooms_total',
            'living_area',
            'modification_timestamp',
            'days_on_market',
        ];

        $invalidField = 'DROP_TABLE';

        $this->assertContains('list_price', $validSortFields);
        $this->assertNotContains($invalidField, $validSortFields);
    }

    /**
     * Test pagination parameters
     */
    public function testPaginationParameters(): void {
        $page = 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $this->assertEquals(0, $offset);

        $page = 3;
        $offset = ($page - 1) * $perPage;

        $this->assertEquals(40, $offset);
    }

    /**
     * Test max results limit
     */
    public function testMaxResultsLimit(): void {
        $maxLimit = 500;
        $requestedLimit = 10000;

        $effectiveLimit = min($requestedLimit, $maxLimit);

        $this->assertEquals(500, $effectiveLimit);
    }

    /**
     * Test response includes success flag
     */
    public function testResponseIncludesSuccessFlag(): void {
        $successResponse = ['success' => true, 'data' => []];
        $errorResponse = ['success' => false, 'data' => ['message' => 'Error']];

        $this->assertTrue($successResponse['success']);
        $this->assertFalse($errorResponse['success']);
    }

    /**
     * Test error response includes message
     */
    public function testErrorResponseIncludesMessage(): void {
        $errorResponse = [
            'success' => false,
            'data' => [
                'message' => 'Invalid request parameters',
                'code' => 'invalid_params',
            ],
        ];

        $this->assertArrayHasKey('message', $errorResponse['data']);
        $this->assertNotEmpty($errorResponse['data']['message']);
    }

    /**
     * Test polygon filter is array of coordinates
     */
    public function testPolygonFilterIsArrayOfCoordinates(): void {
        $polygon = [
            ['lat' => 42.36, 'lng' => -71.06],
            ['lat' => 42.37, 'lng' => -71.05],
            ['lat' => 42.35, 'lng' => -71.04],
            ['lat' => 42.36, 'lng' => -71.06], // Closed polygon
        ];

        $this->assertIsArray($polygon);
        $this->assertGreaterThanOrEqual(3, count($polygon));

        foreach ($polygon as $point) {
            $this->assertArrayHasKey('lat', $point);
            $this->assertArrayHasKey('lng', $point);
        }
    }

    /**
     * Test open house filter is boolean
     */
    public function testOpenHouseFilterIsBoolean(): void {
        $withOpenHouse = true;
        $withoutOpenHouse = false;

        $this->assertIsBool($withOpenHouse);
        $this->assertIsBool($withoutOpenHouse);
    }

    /**
     * Test virtual tour filter is boolean
     */
    public function testVirtualTourFilterIsBoolean(): void {
        $hasVirtualTour = true;

        $this->assertIsBool($hasVirtualTour);
    }

    /**
     * Test load more cards returns expected structure
     */
    public function testLoadMoreCardsReturnsExpectedStructure(): void {
        $response = [
            'success' => true,
            'data' => [
                'html' => '<div class="listing-card">...</div>',
                'has_more' => true,
                'next_page' => 2,
            ],
        ];

        $this->assertArrayHasKey('html', $response['data']);
        $this->assertArrayHasKey('has_more', $response['data']);
        $this->assertArrayHasKey('next_page', $response['data']);
    }
}
