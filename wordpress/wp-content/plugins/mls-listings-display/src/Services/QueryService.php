<?php
/**
 * Query Service for MLS data operations
 *
 * @package MLSDisplay\Services
 * @since 4.8.0
 */

namespace MLSDisplay\Services;

use MLSDisplay\Contracts\DataProviderInterface;
use MLSDisplay\Repositories\ListingRepository;

/**
 * Service for handling MLS queries
 */
class QueryService {

    /**
     * Data provider instance
     * @var DataProviderInterface
     */
    private DataProviderInterface $dataProvider;

    /**
     * Listing repository
     * @var ListingRepository
     */
    private ListingRepository $listingRepository;

    /**
     * Constructor
     */
    public function __construct(DataProviderInterface $dataProvider, ListingRepository $listingRepository) {
        $this->dataProvider = $dataProvider;
        $this->listingRepository = $listingRepository;
    }

    /**
     * Execute a basic listing query
     */
    public function executeListingQuery(array $criteria = [], int $limit = 50, int $offset = 0): array {
        // Validate and sanitize criteria
        $sanitizedCriteria = $this->sanitizeCriteria($criteria);

        // Execute query through repository
        return $this->listingRepository->findBy($sanitizedCriteria, [], $limit, $offset);
    }

    /**
     * Get listings for map display
     */
    public function getListingsForMap(array $bounds, array $filters = [], int $zoom = 10): array {
        // Validate bounds
        if (count($bounds) !== 4) {
            throw new \InvalidArgumentException('Bounds must contain exactly 4 elements [north, south, east, west]');
        }

        list($north, $south, $east, $west) = $bounds;

        // Validate coordinate bounds
        if ($north < $south || $east < $west) {
            throw new \InvalidArgumentException('Invalid coordinate bounds');
        }

        // Handle zoom-based logic to avoid MySQL spatial index issues
        $usePhpFiltering = $zoom >= 14 && !empty($filters['city']);

        if ($usePhpFiltering) {
            // For high zoom with city filter, get city results first, then filter by bounds in PHP
            return $this->getListingsForMapWithCityFilter($bounds, $filters, $zoom);
        }

        // Normal spatial query
        return $this->listingRepository->getListingsForMap($bounds, $filters, $zoom);
    }

    /**
     * Get listings for map with city filter (PHP bounds filtering)
     *
     * @param array<int, float> $bounds Map bounds [north, south, east, west]
     * @param array<string, mixed> $filters Search filters
     * @param int $zoom Zoom level
     * @return array<int, array<string, mixed>> Filtered listings
     */
    private function getListingsForMapWithCityFilter(array $bounds, array $filters, int $zoom): array {
        list($north, $south, $east, $west) = $bounds;

        // Get all listings for the city first
        $cityListings = $this->listingRepository->findByCity($filters['city'], $filters);

        // Filter by bounds in PHP
        $filteredListings = [];
        foreach ($cityListings as $listing) {
            $lat = (float) ($listing['Latitude'] ?? 0);
            $lng = (float) ($listing['Longitude'] ?? 0);

            if ($lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east) {
                $filteredListings[] = $listing;
            }

            // Limit results for performance
            if (count($filteredListings) >= 200) {
                break;
            }
        }

        return $filteredListings;
    }

    /**
     * Get distinct field values
     */
    public function getDistinctValues(string $field, array $filters = []): array {
        $allowedFields = [
            'City', 'PropertyType', 'StandardStatus', 'CountyOrParish',
            'BedroomsTotal', 'BathroomsTotalInteger', 'YearBuilt'
        ];

        if (!in_array($field, $allowedFields)) {
            throw new \InvalidArgumentException("Field '{$field}' is not allowed for distinct queries");
        }

        return $this->listingRepository->getDistinctValues($field, $filters);
    }

    /**
     * Search listings with advanced criteria
     */
    public function searchListings(array $searchParams): array {
        $criteria = [];

        // Price range
        if (!empty($searchParams['min_price'])) {
            $criteria['min_price'] = (float) $searchParams['min_price'];
        }
        if (!empty($searchParams['max_price'])) {
            $criteria['max_price'] = (float) $searchParams['max_price'];
        }

        // Property details
        if (!empty($searchParams['city'])) {
            $criteria['city'] = sanitize_text_field($searchParams['city']);
        }
        if (!empty($searchParams['property_type'])) {
            $criteria['property_type'] = sanitize_text_field($searchParams['property_type']);
        }
        if (!empty($searchParams['bedrooms'])) {
            $criteria['bedrooms_min'] = (int) $searchParams['bedrooms'];
        }
        if (!empty($searchParams['bathrooms'])) {
            $criteria['bathrooms_min'] = (float) $searchParams['bathrooms'];
        }

        // Status filter
        if (!empty($searchParams['status'])) {
            $criteria['status'] = sanitize_text_field($searchParams['status']);
        } else {
            // Default to active listings
            $criteria['status'] = 'Active';
        }

        $limit = !empty($searchParams['limit']) ? (int) $searchParams['limit'] : 50;
        $offset = !empty($searchParams['offset']) ? (int) $searchParams['offset'] : 0;

        return $this->executeListingQuery($criteria, $limit, $offset);
    }

    /**
     * Get listing by MLS number
     */
    public function getListingByMLS(string $mlsNumber): ?array {
        return $this->listingRepository->findOneBy(['ListingId' => $mlsNumber]);
    }

    /**
     * Get recent listings
     */
    public function getRecentListings(int $days = 7, int $limit = 20): array {
        return $this->listingRepository->findRecentlyUpdated($days, ['status' => 'Active']);
    }

    /**
     * Get price statistics for an area
     */
    public function getPriceStatistics(array $filters = []): array {
        $listings = $this->listingRepository->findBy($filters, [], 1000); // Get up to 1000 for stats

        if (empty($listings)) {
            return [
                'count' => 0,
                'min_price' => 0,
                'max_price' => 0,
                'avg_price' => 0,
                'median_price' => 0
            ];
        }

        $prices = array_filter(array_map(function($listing) {
            return (float) ($listing['ListPrice'] ?? 0);
        }, $listings));

        sort($prices);
        $count = count($prices);
        $median = $count > 0 ? $prices[floor($count / 2)] : 0;

        return [
            'count' => $count,
            'min_price' => min($prices),
            'max_price' => max($prices),
            'avg_price' => $count > 0 ? array_sum($prices) / $count : 0,
            'median_price' => $median
        ];
    }

    /**
     * Sanitize search criteria
     *
     * @param array<string, mixed> $criteria Raw search criteria
     * @return array<string, mixed> Sanitized criteria
     */
    private function sanitizeCriteria(array $criteria): array {
        $sanitized = [];

        foreach ($criteria as $key => $value) {
            switch ($key) {
                case 'city':
                case 'property_type':
                case 'status':
                    $sanitized[$key] = sanitize_text_field($value);
                    break;
                case 'min_price':
                case 'max_price':
                    $sanitized[$key] = (float) $value;
                    break;
                case 'bedrooms_min':
                case 'bathrooms_min':
                    $sanitized[$key] = (int) $value;
                    break;
                default:
                    // Only allow known safe fields
                    if (in_array($key, ['ListingId', 'updated_since'])) {
                        $sanitized[$key] = sanitize_text_field($value);
                    }
                    break;
            }
        }

        return $sanitized;
    }

    /**
     * Validate search parameters
     */
    public function validateSearchParams(array $params): array {
        $errors = [];

        // Price validation
        if (isset($params['min_price']) && $params['min_price'] < 0) {
            $errors[] = 'Minimum price cannot be negative';
        }
        if (isset($params['max_price']) && $params['max_price'] < 0) {
            $errors[] = 'Maximum price cannot be negative';
        }
        if (isset($params['min_price'], $params['max_price']) && $params['min_price'] > $params['max_price']) {
            $errors[] = 'Minimum price cannot be greater than maximum price';
        }

        // Bedroom/bathroom validation
        if (isset($params['bedrooms']) && $params['bedrooms'] < 0) {
            $errors[] = 'Number of bedrooms cannot be negative';
        }
        if (isset($params['bathrooms']) && $params['bathrooms'] < 0) {
            $errors[] = 'Number of bathrooms cannot be negative';
        }

        return $errors;
    }
}