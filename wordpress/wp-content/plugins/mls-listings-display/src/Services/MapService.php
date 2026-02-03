<?php
/**
 * Map Service for geographical operations
 *
 * @package MLSDisplay\Services
 * @since 4.8.0
 */

namespace MLSDisplay\Services;

use MLSDisplay\Services\QueryService;

/**
 * Service for map-related operations
 */
class MapService {

    /**
     * Query service instance
     * @var QueryService
     */
    private QueryService $queryService;

    /**
     * Constructor
     */
    public function __construct(QueryService $queryService) {
        $this->queryService = $queryService;
    }

    /**
     * Get listings for map display with clustering
     */
    public function getMapListings(array $bounds, array $filters = [], int $zoom = 10): array {
        $listings = $this->queryService->getListingsForMap($bounds, $filters, $zoom);

        // Apply clustering for high zoom levels
        if ($zoom <= 12 && count($listings) > 100) {
            return $this->clusterListings($listings, $zoom);
        }

        return $this->formatMapListings($listings);
    }

    /**
     * Calculate optimal map bounds for a set of listings
     */
    public function calculateBounds(array $listings): array {
        if (empty($listings)) {
            return $this->getDefaultBounds();
        }

        $latitudes = [];
        $longitudes = [];

        foreach ($listings as $listing) {
            $lat = (float) ($listing['Latitude'] ?? 0);
            $lng = (float) ($listing['Longitude'] ?? 0);

            if ($lat !== 0.0 && $lng !== 0.0) {
                $latitudes[] = $lat;
                $longitudes[] = $lng;
            }
        }

        if (empty($latitudes)) {
            return $this->getDefaultBounds();
        }

        // Add padding to bounds
        $padding = 0.01; // ~1km padding

        return [
            'north' => max($latitudes) + $padding,
            'south' => min($latitudes) - $padding,
            'east' => max($longitudes) + $padding,
            'west' => min($longitudes) - $padding
        ];
    }

    /**
     * Get map markers with custom icons based on price ranges
     */
    public function getMapMarkers(array $listings): array {
        $markers = [];
        $priceRanges = $this->calculatePriceRanges($listings);

        foreach ($listings as $listing) {
            $lat = (float) ($listing['Latitude'] ?? 0);
            $lng = (float) ($listing['Longitude'] ?? 0);
            $price = (float) ($listing['ListPrice'] ?? 0);

            if ($lat === 0.0 || $lng === 0.0) {
                continue;
            }

            $markers[] = [
                'id' => $listing['ListingId'] ?? '',
                'lat' => $lat,
                'lng' => $lng,
                'price' => $price,
                'formatted_price' => '$' . number_format($price, 0),
                'status' => $listing['StandardStatus'] ?? 'Active',
                'property_type' => $listing['PropertyType'] ?? '',
                'bedrooms' => $listing['BedroomsTotal'] ?? 0,
                'bathrooms' => $listing['BathroomsTotalInteger'] ?? 0,
                'icon' => $this->getMarkerIcon($price, $priceRanges),
                'popup_content' => $this->generatePopupContent($listing)
            ];
        }

        return $markers;
    }

    /**
     * Search listings by address or location
     */
    public function searchByLocation(string $location): array {
        // This would integrate with geocoding services
        $coordinates = $this->geocodeLocation($location);

        if (!$coordinates) {
            return [];
        }

        // Create bounds around the location (roughly 5km radius)
        $radius = 0.045; // ~5km in degrees

        $bounds = [
            $coordinates['lat'] + $radius, // north
            $coordinates['lat'] - $radius, // south
            $coordinates['lng'] + $radius, // east
            $coordinates['lng'] - $radius  // west
        ];

        return $this->queryService->getListingsForMap($bounds);
    }

    /**
     * Get neighborhood statistics
     */
    public function getNeighborhoodStats(array $bounds): array {
        $listings = $this->queryService->getListingsForMap($bounds, ['status' => 'Active']);

        if (empty($listings)) {
            return [];
        }

        $prices = array_filter(array_map(function($listing) {
            return (float) ($listing['ListPrice'] ?? 0);
        }, $listings));

        $propertyTypes = array_count_values(array_map(function($listing) {
            return $listing['PropertyType'] ?? 'Unknown';
        }, $listings));

        sort($prices);
        $count = count($prices);

        return [
            'total_listings' => count($listings),
            'average_price' => $count > 0 ? array_sum($prices) / $count : 0,
            'median_price' => $count > 0 ? $prices[floor($count / 2)] : 0,
            'min_price' => $count > 0 ? min($prices) : 0,
            'max_price' => $count > 0 ? max($prices) : 0,
            'property_types' => $propertyTypes,
            'price_per_sqft' => $this->calculateAvgPricePerSqft($listings)
        ];
    }

    /**
     * Cluster listings for better map performance
     *
     * @param array<int, array<string, mixed>> $listings Listings to cluster
     * @param int $zoom Map zoom level
     * @return array<int, array<string, mixed>> Clustered listings
     */
    private function clusterListings(array $listings, int $zoom): array {
        $clusters = [];
        $gridSize = $this->getGridSize($zoom);

        foreach ($listings as $listing) {
            $lat = (float) ($listing['Latitude'] ?? 0);
            $lng = (float) ($listing['Longitude'] ?? 0);

            if ($lat === 0.0 || $lng === 0.0) {
                continue;
            }

            // Calculate grid cell
            $gridLat = round($lat / $gridSize) * $gridSize;
            $gridLng = round($lng / $gridSize) * $gridSize;
            $gridKey = "{$gridLat},{$gridLng}";

            if (!isset($clusters[$gridKey])) {
                $clusters[$gridKey] = [
                    'lat' => $gridLat,
                    'lng' => $gridLng,
                    'count' => 0,
                    'listings' => [],
                    'min_price' => PHP_FLOAT_MAX,
                    'max_price' => 0,
                    'avg_price' => 0
                ];
            }

            $price = (float) ($listing['ListPrice'] ?? 0);
            $clusters[$gridKey]['count']++;
            $clusters[$gridKey]['listings'][] = $listing;
            $clusters[$gridKey]['min_price'] = min($clusters[$gridKey]['min_price'], $price);
            $clusters[$gridKey]['max_price'] = max($clusters[$gridKey]['max_price'], $price);
        }

        // Calculate average prices and format clusters
        foreach ($clusters as &$cluster) {
            $prices = array_map(function($listing) {
                return (float) ($listing['ListPrice'] ?? 0);
            }, $cluster['listings']);

            $cluster['avg_price'] = array_sum($prices) / count($prices);
            $cluster['price_range'] = '$' . number_format($cluster['min_price'], 0) .
                                     ' - $' . number_format($cluster['max_price'], 0);

            // For clusters with single listing, use individual listing data
            if ($cluster['count'] === 1) {
                $cluster = array_merge($cluster, $this->formatMapListings([$cluster['listings'][0]])[0]);
                $cluster['is_cluster'] = false;
            } else {
                $cluster['is_cluster'] = true;
            }
        }

        return array_values($clusters);
    }

    /**
     * Format listings for map display
     *
     * @param array<int, array<string, mixed>> $listings Raw listings
     * @return array<int, array<string, mixed>> Formatted listings
     */
    private function formatMapListings(array $listings): array {
        return array_map(function($listing) {
            return [
                'id' => $listing['ListingId'] ?? '',
                'lat' => (float) ($listing['Latitude'] ?? 0),
                'lng' => (float) ($listing['Longitude'] ?? 0),
                'price' => (float) ($listing['ListPrice'] ?? 0),
                'formatted_price' => '$' . number_format($listing['ListPrice'] ?? 0, 0),
                'status' => $listing['StandardStatus'] ?? 'Active',
                'property_type' => $listing['PropertyType'] ?? '',
                'city' => $listing['City'] ?? '',
                'bedrooms' => $listing['BedroomsTotal'] ?? 0,
                'bathrooms' => $listing['BathroomsTotalInteger'] ?? 0,
                'sqft' => $listing['LivingArea'] ?? 0,
                'is_cluster' => false
            ];
        }, $listings);
    }

    /**
     * Generate popup content for map markers
     *
     * @param array<string, mixed> $listing Listing data
     * @return string HTML popup content
     */
    private function generatePopupContent(array $listing): string {
        $price = '$' . number_format($listing['ListPrice'] ?? 0, 0);
        $address = $listing['UnparsedAddress'] ?? 'Address not available';
        $bedrooms = $listing['BedroomsTotal'] ?? 0;
        $bathrooms = $listing['BathroomsTotalInteger'] ?? 0;
        $sqft = $listing['LivingArea'] ?? 0;

        $html = "<div class='map-popup'>";
        $html .= "<h4>{$price}</h4>";
        $html .= "<p>{$address}</p>";
        $html .= "<div class='popup-details'>";
        $html .= "<span>{$bedrooms} bed</span> • ";
        $html .= "<span>{$bathrooms} bath</span>";
        if ($sqft > 0) {
            $html .= " • <span>" . number_format($sqft) . " sqft</span>";
        }
        $html .= "</div>";
        $html .= "</div>";

        return $html;
    }

    /**
     * Calculate price ranges for marker icons
     *
     * @param array<int, array<string, mixed>> $listings Listings to analyze
     * @return array{low: float, medium: float, high: float} Price range thresholds
     */
    private function calculatePriceRanges(array $listings): array {
        $prices = array_filter(array_map(function($listing) {
            return (float) ($listing['ListPrice'] ?? 0);
        }, $listings));

        if (empty($prices)) {
            return ['low' => 0, 'medium' => 0, 'high' => 0];
        }

        sort($prices);
        $count = count($prices);

        return [
            'low' => $prices[floor($count * 0.33)],
            'medium' => $prices[floor($count * 0.66)],
            'high' => $prices[$count - 1]
        ];
    }

    /**
     * Get marker icon based on price range
     *
     * @param float $price Listing price
     * @param array{low: float, medium: float, high: float} $priceRanges Price thresholds
     * @return string Marker icon class name
     */
    private function getMarkerIcon(float $price, array $priceRanges): string {
        if ($price <= $priceRanges['low']) {
            return 'marker-green';
        } elseif ($price <= $priceRanges['medium']) {
            return 'marker-yellow';
        } else {
            return 'marker-red';
        }
    }

    /**
     * Get default map bounds (example: Phoenix area)
     *
     * @return array{north: float, south: float, east: float, west: float} Default bounds
     */
    private function getDefaultBounds(): array {
        return [
            'north' => 33.7,
            'south' => 33.3,
            'east' => -111.8,
            'west' => -112.3
        ];
    }

    /**
     * Get grid size for clustering based on zoom level
     *
     * @param int $zoom Map zoom level (1-21)
     * @return float Grid size in degrees
     */
    private function getGridSize(int $zoom): float {
        $gridSizes = [
            1 => 10.0,    // Very wide clustering
            5 => 1.0,     // Wide clustering
            8 => 0.1,     // Medium clustering
            10 => 0.05,   // Tight clustering
            12 => 0.01    // Very tight clustering
        ];

        foreach ($gridSizes as $zoomLevel => $size) {
            if ($zoom <= $zoomLevel) {
                return $size;
            }
        }

        return 0.005; // Finest clustering for high zoom
    }

    /**
     * Calculate average price per square foot
     *
     * @param array<int, array<string, mixed>> $listings Listings to analyze
     * @return float Average price per square foot
     */
    private function calculateAvgPricePerSqft(array $listings): float {
        $pricePerSqft = [];

        foreach ($listings as $listing) {
            $price = (float) ($listing['ListPrice'] ?? 0);
            $sqft = (float) ($listing['LivingArea'] ?? 0);

            if ($price > 0 && $sqft > 0) {
                $pricePerSqft[] = $price / $sqft;
            }
        }

        return !empty($pricePerSqft) ? array_sum($pricePerSqft) / count($pricePerSqft) : 0;
    }

    /**
     * Geocode a location string to coordinates
     *
     * @param string $location Address or place name
     * @return array{lat: float, lng: float}|null Coordinates or null if not found
     */
    private function geocodeLocation(string $location): ?array {
        // This would integrate with a geocoding service
        // For now, return null to indicate no geocoding available
        return null;
    }
}