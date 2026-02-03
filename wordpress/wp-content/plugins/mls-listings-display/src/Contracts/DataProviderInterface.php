<?php
/**
 * Data Provider Interface for MLS data sources
 *
 * @package MLSDisplay\Contracts
 * @since 4.8.0
 */

namespace MLSDisplay\Contracts;

/**
 * Interface for MLS data providers
 */
interface DataProviderInterface {

    /**
     * Get available MLS tables
     *
     * @return array|null Array of table information or null if not available
     */
    public function getTables(): ?array;

    /**
     * Check if data provider is available
     *
     * @return bool True if provider is available
     */
    public function isAvailable(): bool;

    /**
     * Get table prefix for this provider
     *
     * @return string Table prefix
     */
    public function getTablePrefix(): string;

    /**
     * Execute a query on the provider's data source
     *
     * @param string $query SQL query to execute
     * @param array $params Query parameters
     * @return array|null Query results or null on failure
     */
    public function query(string $query, array $params = []): ?array;

    /**
     * Get listings based on criteria
     *
     * @param array $criteria Search criteria
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of listings
     */
    public function getListings(array $criteria = [], int $limit = 50, int $offset = 0): array;

    /**
     * Get a single listing by ID
     *
     * @param string $listingId Listing ID
     * @return array|null Listing data or null if not found
     */
    public function getListing(string $listingId): ?array;

    /**
     * Get listing photos
     *
     * @param string $listingId Listing ID
     * @return array Array of photo URLs
     */
    public function getListingPhotos(string $listingId): array;

    /**
     * Get distinct values for a field
     *
     * @param string $field Field name
     * @param array $filters Additional filters
     * @return array Array of distinct values
     */
    public function getDistinctValues(string $field, array $filters = []): array;

    /**
     * Get listings for map display with spatial filtering
     *
     * @param array $bounds Map bounds [north, south, east, west]
     * @param array $filters Additional filters
     * @param int $zoom Zoom level
     * @return array Array of listings with coordinates
     */
    public function getListingsForMap(array $bounds, array $filters = [], int $zoom = 10): array;

    /**
     * Get count of listings matching criteria
     *
     * @param array $criteria Search criteria
     * @return int Count of matching listings
     * @since 6.9.3
     */
    public function getListingCount(array $criteria = []): int;
}