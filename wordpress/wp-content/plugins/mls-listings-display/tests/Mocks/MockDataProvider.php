<?php
/**
 * Mock Data Provider for Testing
 *
 * Implements DataProviderInterface for unit testing without database access.
 *
 * @package MLSDisplay\Tests\Mocks
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Mocks;

use MLSDisplay\Contracts\DataProviderInterface;
use MLSDisplay\Tests\Fixtures\ListingData;

/**
 * Mock implementation of DataProviderInterface for testing
 */
class MockDataProvider implements DataProviderInterface {

    /**
     * Whether the mock provider is "available"
     * @var bool
     */
    private bool $isAvailable = true;

    /**
     * Mock table prefix
     * @var string
     */
    private string $tablePrefix = 'wp_test_bme_';

    /**
     * Mock tables configuration
     * @var array|null
     */
    private ?array $tables = null;

    /**
     * Store listings for the mock
     * @var array
     */
    private array $listings = [];

    /**
     * Store photos for the mock
     * @var array
     */
    private array $photos = [];

    /**
     * Track method calls for assertions
     * @var array
     */
    private array $methodCalls = [];

    /**
     * Custom query results
     * @var array|null
     */
    private ?array $queryResults = null;

    /**
     * Constructor - initialize with test fixtures
     */
    public function __construct() {
        $this->initializeDefaultTables();
        $this->initializeDefaultListings();
    }

    /**
     * Initialize default mock tables
     */
    private function initializeDefaultTables(): void {
        $this->tables = [
            'listings' => $this->tablePrefix . 'listings',
            'location' => $this->tablePrefix . 'listing_location',
            'photos' => $this->tablePrefix . 'media',
            'agents' => $this->tablePrefix . 'agents',
            'offices' => $this->tablePrefix . 'offices',
        ];
    }

    /**
     * Initialize default mock listings from fixtures
     */
    private function initializeDefaultListings(): void {
        // Add various listing types
        $this->listings = [
            ListingData::getSingleFamilyListing(),
            ListingData::getCondoListing(),
            ListingData::getClosedListing(),
            ListingData::getPendingListing(),
            ListingData::getCommercialListing(),
            ListingData::getLandListing(),
        ];

        // Add 10 more active listings for pagination testing
        $activeListings = ListingData::getActiveListings(10);
        $this->listings = array_merge($this->listings, $activeListings);

        // Initialize photos for each listing
        foreach ($this->listings as $listing) {
            $listingId = (string) $listing['listing_id'];
            $photoCount = $listing['photo_count'] ?? 5;
            $this->photos[$listingId] = [];

            for ($i = 1; $i <= min($photoCount, 10); $i++) {
                $this->photos[$listingId][] = "https://example.com/photos/{$listingId}/{$i}.jpg";
            }
        }
    }

    /**
     * Record a method call for later assertions
     */
    private function recordCall(string $method, array $args = []): void {
        $this->methodCalls[] = [
            'method' => $method,
            'args' => $args,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Get recorded method calls
     *
     * @param string|null $method Filter by method name
     * @return array
     */
    public function getMethodCalls(?string $method = null): array {
        if ($method === null) {
            return $this->methodCalls;
        }

        return array_filter($this->methodCalls, function ($call) use ($method) {
            return $call['method'] === $method;
        });
    }

    /**
     * Clear recorded method calls
     */
    public function clearMethodCalls(): void {
        $this->methodCalls = [];
    }

    /**
     * Set whether the provider is available
     */
    public function setAvailable(bool $available): self {
        $this->isAvailable = $available;
        return $this;
    }

    /**
     * Set custom tables configuration
     */
    public function setTables(?array $tables): self {
        $this->tables = $tables;
        return $this;
    }

    /**
     * Set custom table prefix
     */
    public function setTablePrefix(string $prefix): self {
        $this->tablePrefix = $prefix;
        return $this;
    }

    /**
     * Add a listing to the mock data
     */
    public function addListing(array $listing): self {
        $this->listings[] = $listing;
        return $this;
    }

    /**
     * Set all listings
     */
    public function setListings(array $listings): self {
        $this->listings = $listings;
        return $this;
    }

    /**
     * Clear all listings
     */
    public function clearListings(): self {
        $this->listings = [];
        $this->photos = [];
        return $this;
    }

    /**
     * Set photos for a listing
     */
    public function setPhotos(string $listingId, array $photos): self {
        $this->photos[$listingId] = $photos;
        return $this;
    }

    /**
     * Set custom query results
     */
    public function setQueryResults(?array $results): self {
        $this->queryResults = $results;
        return $this;
    }

    /**
     * Reset the mock to default state
     */
    public function reset(): self {
        $this->isAvailable = true;
        $this->tablePrefix = 'wp_test_bme_';
        $this->queryResults = null;
        $this->methodCalls = [];
        $this->initializeDefaultTables();
        $this->initializeDefaultListings();
        return $this;
    }

    // =====================================================
    // DataProviderInterface Implementation
    // =====================================================

    /**
     * {@inheritdoc}
     */
    public function getTables(): ?array {
        $this->recordCall('getTables');

        if (!$this->isAvailable) {
            return null;
        }

        return $this->tables;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool {
        $this->recordCall('isAvailable');
        return $this->isAvailable;
    }

    /**
     * {@inheritdoc}
     */
    public function getTablePrefix(): string {
        $this->recordCall('getTablePrefix');
        return $this->tablePrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $query, array $params = []): ?array {
        $this->recordCall('query', ['query' => $query, 'params' => $params]);

        if (!$this->isAvailable) {
            return null;
        }

        if ($this->queryResults !== null) {
            return $this->queryResults;
        }

        // Return empty array as default (simulates successful but empty query)
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getListings(array $criteria = [], int $limit = 50, int $offset = 0): array {
        $this->recordCall('getListings', [
            'criteria' => $criteria,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (!$this->isAvailable) {
            return [];
        }

        $filtered = $this->filterListings($criteria);

        // Apply offset and limit
        return array_slice($filtered, $offset, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function getListing(string $listingId): ?array {
        $this->recordCall('getListing', ['listingId' => $listingId]);

        if (!$this->isAvailable) {
            return null;
        }

        foreach ($this->listings as $listing) {
            if ((string) $listing['listing_id'] === $listingId) {
                return $listing;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getListingPhotos(string $listingId): array {
        $this->recordCall('getListingPhotos', ['listingId' => $listingId]);

        if (!$this->isAvailable) {
            return [];
        }

        return $this->photos[$listingId] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getDistinctValues(string $field, array $filters = []): array {
        $this->recordCall('getDistinctValues', ['field' => $field, 'filters' => $filters]);

        if (!$this->isAvailable) {
            return [];
        }

        $values = [];
        $filtered = $this->filterListings($filters);

        foreach ($filtered as $listing) {
            if (isset($listing[$field]) && $listing[$field] !== null) {
                $values[] = $listing[$field];
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * {@inheritdoc}
     */
    public function getListingsForMap(array $bounds, array $filters = [], int $zoom = 10): array {
        $this->recordCall('getListingsForMap', [
            'bounds' => $bounds,
            'filters' => $filters,
            'zoom' => $zoom,
        ]);

        if (!$this->isAvailable) {
            return [];
        }

        $filtered = $this->filterListings($filters);

        // Filter by bounds if provided
        if (!empty($bounds)) {
            $north = $bounds['north'] ?? $bounds[0] ?? null;
            $south = $bounds['south'] ?? $bounds[1] ?? null;
            $east = $bounds['east'] ?? $bounds[2] ?? null;
            $west = $bounds['west'] ?? $bounds[3] ?? null;

            if ($north !== null && $south !== null && $east !== null && $west !== null) {
                $filtered = array_filter($filtered, function ($listing) use ($north, $south, $east, $west) {
                    $lat = $listing['latitude'] ?? null;
                    $lng = $listing['longitude'] ?? null;

                    if ($lat === null || $lng === null) {
                        return false;
                    }

                    return $lat <= $north && $lat >= $south && $lng <= $east && $lng >= $west;
                });
            }
        }

        // Return only listings with coordinates
        return array_values(array_filter($filtered, function ($listing) {
            return isset($listing['latitude']) && isset($listing['longitude']) &&
                   $listing['latitude'] !== null && $listing['longitude'] !== null;
        }));
    }

    /**
     * {@inheritdoc}
     */
    public function getListingCount(array $criteria = []): int {
        $this->recordCall('getListingCount', ['criteria' => $criteria]);

        if (!$this->isAvailable) {
            return 0;
        }

        return count($this->filterListings($criteria));
    }

    // =====================================================
    // Helper Methods
    // =====================================================

    /**
     * Filter listings based on criteria
     *
     * @param array $criteria
     * @return array
     */
    private function filterListings(array $criteria): array {
        if (empty($criteria)) {
            return $this->listings;
        }

        return array_filter($this->listings, function ($listing) use ($criteria) {
            foreach ($criteria as $field => $value) {
                // Handle array values (IN clause simulation)
                if (is_array($value)) {
                    if (!isset($listing[$field]) || !in_array($listing[$field], $value)) {
                        return false;
                    }
                }
                // Handle range filters
                elseif ($field === 'min_price') {
                    if (!isset($listing['list_price']) || $listing['list_price'] < $value) {
                        return false;
                    }
                }
                elseif ($field === 'max_price') {
                    if (!isset($listing['list_price']) || $listing['list_price'] > $value) {
                        return false;
                    }
                }
                elseif ($field === 'min_beds') {
                    if (!isset($listing['bedrooms_total']) || $listing['bedrooms_total'] < $value) {
                        return false;
                    }
                }
                elseif ($field === 'max_beds') {
                    if (!isset($listing['bedrooms_total']) || $listing['bedrooms_total'] > $value) {
                        return false;
                    }
                }
                elseif ($field === 'min_sqft') {
                    if (!isset($listing['building_area_total']) || $listing['building_area_total'] < $value) {
                        return false;
                    }
                }
                elseif ($field === 'max_sqft') {
                    if (!isset($listing['building_area_total']) || $listing['building_area_total'] > $value) {
                        return false;
                    }
                }
                // Handle exact match
                else {
                    if (!isset($listing[$field]) || $listing[$field] !== $value) {
                        return false;
                    }
                }
            }

            return true;
        });
    }
}
