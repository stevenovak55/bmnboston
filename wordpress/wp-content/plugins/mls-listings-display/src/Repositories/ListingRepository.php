<?php
/**
 * Listing Repository for MLS data access
 *
 * @package MLSDisplay\Repositories
 * @since 4.8.0
 */

namespace MLSDisplay\Repositories;

use MLSDisplay\Contracts\RepositoryInterface;
use MLSDisplay\Contracts\DataProviderInterface;

/**
 * Repository for listing data access
 */
class ListingRepository implements RepositoryInterface {

    /**
     * Data provider instance
     * @var DataProviderInterface
     */
    private DataProviderInterface $dataProvider;

    /**
     * Constructor
     *
     * @param DataProviderInterface $dataProvider
     */
    public function __construct(DataProviderInterface $dataProvider) {
        $this->dataProvider = $dataProvider;
    }

    /**
     * Find all listings with optional criteria
     */
    public function findAll(array $criteria = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array {
        return $this->dataProvider->getListings($criteria, $limit ?? 50, $offset);
    }

    /**
     * Find a listing by ID
     */
    public function findById($id): ?array {
        return $this->dataProvider->getListing($id);
    }

    /**
     * Find listings by criteria
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array {
        return $this->dataProvider->getListings($criteria, $limit ?? 50, $offset);
    }

    /**
     * Find a single listing by criteria
     */
    public function findOneBy(array $criteria): ?array {
        $results = $this->dataProvider->getListings($criteria, 1, 0);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Create a new listing (not applicable for MLS data)
     */
    public function create(array $data) {
        throw new \Exception('Creating listings is not supported for MLS data');
    }

    /**
     * Update a listing (not applicable for MLS data)
     */
    public function update($id, array $data): bool {
        throw new \Exception('Updating listings is not supported for MLS data');
    }

    /**
     * Delete a listing (not applicable for MLS data)
     */
    public function delete($id): bool {
        throw new \Exception('Deleting listings is not supported for MLS data');
    }

    /**
     * Count listings matching criteria
     *
     * Uses efficient SQL COUNT() via data provider
     *
     * @since 6.9.3 - Performance fix: Uses SQL COUNT() instead of fetching all records
     */
    public function count(array $criteria = []): int {
        return $this->dataProvider->getListingCount($criteria);
    }

    /**
     * Check if a listing exists
     */
    public function exists($id): bool {
        return $this->findById($id) !== null;
    }

    /**
     * Get listings for map display
     */
    public function getListingsForMap(array $bounds, array $filters = [], int $zoom = 10): array {
        return $this->dataProvider->getListingsForMap($bounds, $filters, $zoom);
    }

    /**
     * Get listing photos
     */
    public function getListingPhotos(string $listingId): array {
        return $this->dataProvider->getListingPhotos($listingId);
    }

    /**
     * Get distinct values for a field
     */
    public function getDistinctValues(string $field, array $filters = []): array {
        return $this->dataProvider->getDistinctValues($field, $filters);
    }

    /**
     * Get listings by geographic bounds
     */
    public function findByBounds(float $north, float $south, float $east, float $west, array $filters = []): array {
        $bounds = [$north, $south, $east, $west];
        return $this->dataProvider->getListingsForMap($bounds, $filters);
    }

    /**
     * Get listings by city
     */
    public function findByCity(string $city, array $filters = []): array {
        $filters['city'] = $city;
        return $this->dataProvider->getListings($filters);
    }

    /**
     * Get listings by price range
     */
    public function findByPriceRange(float $minPrice, float $maxPrice, array $filters = []): array {
        $filters['min_price'] = $minPrice;
        $filters['max_price'] = $maxPrice;
        return $this->dataProvider->getListings($filters);
    }

    /**
     * Get recently updated listings
     */
    public function findRecentlyUpdated(int $days = 7, array $filters = []): array {
        // v6.75.4: Use wp_date with current_time for correct timezone handling
        $filters['updated_since'] = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        return $this->dataProvider->getListings($filters);
    }
}