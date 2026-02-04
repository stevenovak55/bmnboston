<?php
/**
 * Listing Service for business logic operations
 *
 * @package MLSDisplay\Services
 * @since 4.8.0
 */

namespace MLSDisplay\Services;

use MLSDisplay\Repositories\ListingRepository;

/**
 * Service for listing business logic
 */
class ListingService {

    /**
     * Listing repository
     * @var ListingRepository
     */
    private ListingRepository $listingRepository;

    /**
     * Constructor
     */
    public function __construct(ListingRepository $listingRepository) {
        $this->listingRepository = $listingRepository;
    }

    /**
     * Get formatted listing data for display
     */
    public function getFormattedListing(string $listingId): ?array {
        $listing = $this->listingRepository->findById($listingId);
        if (!$listing) {
            return null;
        }

        return $this->formatListingData($listing);
    }

    /**
     * Get listing photos with optimization
     */
    public function getListingPhotos(string $listingId, array $options = []): array {
        $photos = $this->listingRepository->getListingPhotos($listingId);

        $maxPhotos = $options['max_photos'] ?? null;
        $imageSize = $options['image_size'] ?? 'medium';

        if ($maxPhotos && count($photos) > $maxPhotos) {
            $photos = array_slice($photos, 0, $maxPhotos);
        }

        return array_map(function($photo) use ($imageSize) {
            return $this->formatPhotoData($photo, $imageSize);
        }, $photos);
    }

    /**
     * Calculate listing metrics and insights
     */
    public function getListingInsights(string $listingId): array {
        $listing = $this->listingRepository->findById($listingId);
        if (!$listing) {
            return [];
        }

        $insights = [];

        // Days on market
        if (!empty($listing['ListingContractDate'])) {
            $listDate = new \DateTime($listing['ListingContractDate']);
            $now = new \DateTime();
            $insights['days_on_market'] = $now->diff($listDate)->days;
        }

        // Price per square foot
        if (!empty($listing['ListPrice']) && !empty($listing['LivingArea'])) {
            $insights['price_per_sqft'] = round($listing['ListPrice'] / $listing['LivingArea'], 2);
        }

        // Market comparison
        $marketStats = $this->getMarketComparison($listing);
        $insights['market_comparison'] = $marketStats;

        // Property features score
        $insights['features_score'] = $this->calculateFeaturesScore($listing);

        return $insights;
    }

    /**
     * Get similar listings
     */
    public function getSimilarListings(string $listingId, int $limit = 5): array {
        $listing = $this->listingRepository->findById($listingId);
        if (!$listing) {
            return [];
        }

        // Build similarity criteria
        $criteria = [];

        // Same city
        if (!empty($listing['City'])) {
            $criteria['city'] = $listing['City'];
        }

        // Similar price range (+/- 20%)
        if (!empty($listing['ListPrice'])) {
            $price = (float) $listing['ListPrice'];
            $criteria['min_price'] = $price * 0.8;
            $criteria['max_price'] = $price * 1.2;
        }

        // Same property type
        if (!empty($listing['PropertyType'])) {
            $criteria['property_type'] = $listing['PropertyType'];
        }

        // Active listings only
        $criteria['status'] = 'Active';

        $similarListings = $this->listingRepository->findBy($criteria, [], $limit + 1);

        // Remove the current listing from results
        return array_filter($similarListings, function($similar) use ($listingId) {
            return ($similar['ListingId'] ?? '') !== $listingId;
        });
    }

    /**
     * Check if listing is a featured/premium property
     */
    public function isFeaturedListing(array $listing): bool {
        // Define criteria for featured listings
        $featuredCriteria = [
            'high_price' => ($listing['ListPrice'] ?? 0) > 500000,
            'recent' => $this->isRecentListing($listing),
            'complete_data' => $this->hasCompleteData($listing),
            'has_photos' => $this->hasPhotos($listing['ListingId'] ?? '')
        ];

        // Listing is featured if it meets at least 2 criteria
        return array_sum($featuredCriteria) >= 2;
    }

    /**
     * Get listing status information
     */
    public function getListingStatus(array $listing): array {
        $status = $listing['StandardStatus'] ?? 'Unknown';
        $statusInfo = [
            'status' => $status,
            'display_name' => $this->getStatusDisplayName($status),
            'is_active' => in_array($status, ['Active', 'ActiveUnderContract']),
            'is_sold' => in_array($status, ['Closed', 'Sold']),
            'is_pending' => in_array($status, ['Pending', 'ActiveUnderContract']),
            'css_class' => $this->getStatusCssClass($status)
        ];

        // Add contingent information if available
        if (!empty($listing['ContingentDate'])) {
            $statusInfo['contingent_date'] = $listing['ContingentDate'];
        }

        return $statusInfo;
    }

    /**
     * Format listing data for display
     *
     * @param array<string, mixed> $listing Raw listing data
     * @return array<string, mixed> Formatted listing with computed fields
     */
    private function formatListingData(array $listing): array {
        $formatted = $listing;

        // Format price
        if (!empty($listing['ListPrice'])) {
            $formatted['formatted_price'] = $this->formatPrice($listing['ListPrice']);
        }

        // Format address
        $formatted['formatted_address'] = $this->formatAddress($listing);

        // Format dates
        foreach (['ListingContractDate', 'ModificationTimestamp', 'ContractDate'] as $dateField) {
            if (!empty($listing[$dateField])) {
                $formatted["formatted_{$dateField}"] = $this->formatDate($listing[$dateField]);
            }
        }

        // Add computed fields
        $formatted['status_info'] = $this->getListingStatus($listing);
        $formatted['is_featured'] = $this->isFeaturedListing($listing);

        return $formatted;
    }

    /**
     * Format photo data
     *
     * @param array<string, mixed> $photo Raw photo data
     * @param string $size Image size (small, medium, large)
     * @return array<string, mixed> Formatted photo with URLs and metadata
     */
    private function formatPhotoData(array $photo, string $size): array {
        $formatted = $photo;

        // Generate different sized URLs if needed
        $baseUrl = $photo['PhotoURL'] ?? '';
        $formatted['url'] = $baseUrl;
        $formatted['thumbnail'] = $this->generateThumbnailUrl($baseUrl, $size);
        $formatted['alt_text'] = $photo['PhotoDescription'] ?? 'Property Photo';

        return $formatted;
    }

    /**
     * Calculate features score based on property amenities
     *
     * @param array<string, mixed> $listing Listing data with amenities
     * @return int Feature score (0-100)
     */
    private function calculateFeaturesScore(array $listing): int {
        $score = 0;

        // Basic features
        if (!empty($listing['BedroomsTotal']) && $listing['BedroomsTotal'] >= 3) $score += 10;
        if (!empty($listing['BathroomsTotalInteger']) && $listing['BathroomsTotalInteger'] >= 2) $score += 10;
        if (!empty($listing['LivingArea']) && $listing['LivingArea'] >= 2000) $score += 15;

        // Premium features
        if (!empty($listing['GarageSpaces']) && $listing['GarageSpaces'] >= 2) $score += 10;
        if (!empty($listing['PoolPrivateYN']) && $listing['PoolPrivateYN'] === 'Yes') $score += 15;
        if (!empty($listing['FireplacesTotal']) && $listing['FireplacesTotal'] > 0) $score += 5;

        // Property condition
        if (!empty($listing['YearBuilt'])) {
            $age = date('Y') - $listing['YearBuilt'];
            if ($age < 10) $score += 15;
            elseif ($age < 20) $score += 10;
            elseif ($age < 30) $score += 5;
        }

        return min($score, 100); // Cap at 100
    }

    /**
     * Get market comparison data
     *
     * @param array<string, mixed> $listing Subject listing to compare
     * @return array{average_price?: float, median_price?: float, sample_size?: int, price_percentile?: int} Market stats
     */
    private function getMarketComparison(array $listing): array {
        if (empty($listing['City']) || empty($listing['PropertyType'])) {
            return [];
        }

        $criteria = [
            'city' => $listing['City'],
            'property_type' => $listing['PropertyType'],
            'status' => 'Active'
        ];

        $marketListings = $this->listingRepository->findBy($criteria, [], 100);

        if (count($marketListings) < 3) {
            return [];
        }

        $prices = array_filter(array_map(function($l) {
            return (float) ($l['ListPrice'] ?? 0);
        }, $marketListings));

        sort($prices);
        $count = count($prices);

        return [
            'average_price' => $count > 0 ? array_sum($prices) / $count : 0,
            'median_price' => $count > 0 ? $prices[(int) floor($count / 2)] : 0,
            'sample_size' => $count,
            'price_percentile' => $this->calculatePricePercentile($listing['ListPrice'], $prices)
        ];
    }

    /**
     * Format price for display
     *
     * @param float $price Price value
     * @return string Formatted price with currency symbol
     */
    private function formatPrice(float $price): string {
        return '$' . number_format($price, 0);
    }

    /**
     * Format full address from listing components
     *
     * @param array<string, mixed> $listing Listing with address fields
     * @return string Formatted full address
     */
    private function formatAddress(array $listing): string {
        $parts = array_filter([
            $listing['UnparsedAddress'] ?? '',
            $listing['City'] ?? '',
            $listing['StateOrProvince'] ?? '',
            $listing['PostalCode'] ?? ''
        ]);

        return implode(', ', $parts);
    }

    /**
     * Format date for display
     *
     * @param string $date Date string in any parseable format
     * @return string Formatted date (e.g., "Jan 15, 2025")
     */
    private function formatDate(string $date): string {
        // v6.75.4: Use DateTime with wp_timezone() for correct display
        $dt = new \DateTime($date, wp_timezone());
        return wp_date('M j, Y', $dt->getTimestamp());
    }

    /**
     * Check if listing was recently added (within 30 days)
     *
     * @param array<string, mixed> $listing Listing data
     * @return bool True if listed within last 30 days
     */
    private function isRecentListing(array $listing): bool {
        if (empty($listing['ListingContractDate'])) {
            return false;
        }

        // v6.75.4: Use DateTime with wp_timezone() for correct timezone handling
        // strtotime() interprets dates as UTC, causing 5-hour discrepancy
        // Database stores dates in WordPress timezone (America/New_York)
        $listDate = new \DateTime($listing['ListingContractDate'], wp_timezone());
        $daysSince = (current_time('timestamp') - $listDate->getTimestamp()) / (24 * 60 * 60);

        return $daysSince <= 30; // Listed within last 30 days
    }

    /**
     * Check if listing has all required data fields populated
     *
     * @param array<string, mixed> $listing Listing data
     * @return bool True if all required fields are present
     */
    private function hasCompleteData(array $listing): bool {
        $requiredFields = ['ListPrice', 'BedroomsTotal', 'BathroomsTotalInteger', 'LivingArea'];

        foreach ($requiredFields as $field) {
            if (empty($listing[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if listing has photos
     *
     * @param string $listingId Listing ID
     * @return bool True if listing has at least one photo
     */
    private function hasPhotos(string $listingId): bool {
        if (empty($listingId)) {
            return false;
        }

        $photos = $this->listingRepository->getListingPhotos($listingId);
        return count($photos) > 0;
    }

    /**
     * Get human-readable status name
     *
     * @param string $status MLS status code
     * @return string Display-friendly status name
     */
    private function getStatusDisplayName(string $status): string {
        $statusMap = [
            'Active' => 'For Sale',
            'ActiveUnderContract' => 'Under Contract',
            'Pending' => 'Pending',
            'Closed' => 'Sold',
            'Sold' => 'Sold',
            'Withdrawn' => 'Withdrawn',
            'Expired' => 'Expired'
        ];

        return $statusMap[$status] ?? $status;
    }

    /**
     * Get CSS class for status styling
     *
     * @param string $status MLS status code
     * @return string CSS class name
     */
    private function getStatusCssClass(string $status): string {
        $classMap = [
            'Active' => 'status-active',
            'ActiveUnderContract' => 'status-under-contract',
            'Pending' => 'status-pending',
            'Closed' => 'status-sold',
            'Sold' => 'status-sold',
            'Withdrawn' => 'status-withdrawn',
            'Expired' => 'status-expired'
        ];

        return $classMap[$status] ?? 'status-unknown';
    }

    /**
     * Generate thumbnail URL for given image size
     *
     * @param string $baseUrl Original image URL
     * @param string $size Desired size (small, medium, large)
     * @return string Thumbnail URL
     */
    private function generateThumbnailUrl(string $baseUrl, string $size): string {
        // This would implement thumbnail generation logic
        // For now, return the base URL
        return $baseUrl;
    }

    /**
     * Calculate price percentile in the market
     *
     * @param float $price Subject price to evaluate
     * @param array<int, float> $sortedPrices Sorted array of market prices
     * @return int Percentile (0-100)
     */
    private function calculatePricePercentile(float $price, array $sortedPrices): int {
        $count = count($sortedPrices);
        if ($count === 0) return 50;

        $position = 0;
        foreach ($sortedPrices as $index => $p) {
            if ($price <= $p) {
                $position = $index;
                break;
            }
        }

        return (int) round(($position / $count) * 100);
    }
}