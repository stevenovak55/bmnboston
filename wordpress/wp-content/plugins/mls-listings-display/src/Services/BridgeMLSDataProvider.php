<?php
/**
 * Bridge MLS Data Provider Implementation
 *
 * @package MLSDisplay\Services
 * @since 4.8.0
 * @updated 6.9.4 - Fixed column name mapping (snake_case) and location table JOINs
 */

namespace MLSDisplay\Services;

use MLSDisplay\Contracts\DataProviderInterface;

/**
 * Data provider for Bridge MLS Extractor Pro plugin
 *
 * Database Schema Notes:
 * - wp_bme_listings: Main listing table (standard_status, list_price, property_type, etc.)
 * - wp_bme_listing_location: Location data (city, state_or_province, latitude, longitude)
 * - Tables are joined via listing_id
 * - All column names use snake_case (not PascalCase)
 */
class BridgeMLSDataProvider implements DataProviderInterface {

    /**
     * WordPress database instance
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Cache for table information
     * @var array|null
     */
    private ?array $tablesCache = null;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Get available MLS tables
     */
    public function getTables(): ?array {
        if ($this->tablesCache !== null) {
            return $this->tablesCache;
        }

        // Check if Bridge MLS Extractor Pro is active
        if (!$this->isAvailable()) {
            return null;
        }

        // Try to get tables from Bridge MLS plugin
        if (class_exists('BME_Database_Manager')) {
            $dbManager = new \BME_Database_Manager();
            $this->tablesCache = $dbManager->get_tables();
            return $this->tablesCache;
        }

        // Fallback: detect tables manually
        $prefix = $this->getTablePrefix();
        $tables = [];

        $tableNames = [
            'listings' => $prefix . 'listings',
            'location' => $prefix . 'listing_location',
            'photos' => $prefix . 'media',
            'agents' => $prefix . 'agents',
            'offices' => $prefix . 'offices'
        ];

        foreach ($tableNames as $key => $tableName) {
            $result = $this->wpdb->get_var(
                $this->wpdb->prepare("SHOW TABLES LIKE %s", $tableName)
            );
            if ($result) {
                $tables[$key] = $tableName;
            }
        }

        $this->tablesCache = !empty($tables) ? $tables : null;
        return $this->tablesCache;
    }

    /**
     * Check if Bridge MLS data provider is available
     */
    public function isAvailable(): bool {
        // Check if Bridge MLS Extractor Pro plugin is active
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('bridge-mls-extractor-pro/bridge-mls-extractor-pro.php') ||
               class_exists('BME_Database_Manager') ||
               class_exists('Bridge_MLS_Extractor_Pro');
    }

    /**
     * Get table prefix for Bridge MLS tables
     */
    public function getTablePrefix(): string {
        return $this->wpdb->prefix . 'bme_';
    }

    /**
     * Execute a query on Bridge MLS data
     */
    public function query(string $query, array $params = []): ?array {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            if (!empty($params)) {
                $query = $this->wpdb->prepare($query, $params);
            }

            $results = $this->wpdb->get_results($query, ARRAY_A);
            return $results ?: [];
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Bridge MLS Data Provider query error: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Get listings based on criteria
     *
     * @since 6.9.4 - Fixed column names to use snake_case, added location table JOIN
     */
    public function getListings(array $criteria = [], int $limit = 50, int $offset = 0): array {
        $tables = $this->getTables();
        if (!$tables || !isset($tables['listings'])) {
            return [];
        }

        $listingsTable = $tables['listings'];
        $locationTable = $tables['location'] ?? $this->getTablePrefix() . 'listing_location';
        $needsLocationJoin = $this->needsLocationJoin($criteria);

        // Build SELECT with optional location JOIN
        if ($needsLocationJoin) {
            $sql = "SELECT l.*, loc.city, loc.state_or_province, loc.postal_code, loc.latitude, loc.longitude
                    FROM {$listingsTable} l
                    LEFT JOIN {$locationTable} loc ON l.listing_id = loc.listing_id";
        } else {
            $sql = "SELECT * FROM {$listingsTable} l";
        }

        $params = [];
        $conditions = [];

        // Build WHERE conditions using correct snake_case column names
        if (!empty($criteria)) {
            foreach ($criteria as $field => $value) {
                switch ($field) {
                    case 'city':
                        $conditions[] = "loc.city = %s";
                        $params[] = $value;
                        break;
                    case 'state':
                        $conditions[] = "loc.state_or_province = %s";
                        $params[] = $value;
                        break;
                    case 'postal_code':
                    case 'zip':
                        $conditions[] = "loc.postal_code = %s";
                        $params[] = $value;
                        break;
                    case 'min_price':
                        $conditions[] = "l.list_price >= %f";
                        $params[] = (float) $value;
                        break;
                    case 'max_price':
                        $conditions[] = "l.list_price <= %f";
                        $params[] = (float) $value;
                        break;
                    case 'property_type':
                        $conditions[] = "l.property_type = %s";
                        $params[] = $value;
                        break;
                    case 'status':
                        $conditions[] = "l.standard_status = %s";
                        $params[] = $value;
                        break;
                    case 'updated_since':
                        $conditions[] = "l.modification_timestamp >= %s";
                        $params[] = $value;
                        break;
                    default:
                        // For unknown fields, try to determine if it's a listing or location column
                        if (is_string($value)) {
                            $conditions[] = "l.{$field} = %s";
                            $params[] = $value;
                        }
                        break;
                }
            }
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        // Default ordering using correct column name
        $sql .= " ORDER BY l.modification_timestamp DESC";

        // Add limit and offset
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $this->query($sql, $params) ?: [];
    }

    /**
     * Check if criteria requires location table JOIN
     *
     * @param array $criteria Search criteria
     * @return bool True if location JOIN is needed
     * @since 6.9.4
     */
    private function needsLocationJoin(array $criteria): bool {
        $locationFields = ['city', 'state', 'postal_code', 'zip', 'county', 'latitude', 'longitude'];
        foreach ($locationFields as $field) {
            if (isset($criteria[$field])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get a single listing by ID
     *
     * @since 6.9.4 - Fixed column names, added location JOIN
     */
    public function getListing(string $listingId): ?array {
        $tables = $this->getTables();
        if (!$tables || !isset($tables['listings'])) {
            return null;
        }

        $listingsTable = $tables['listings'];
        $locationTable = $tables['location'] ?? $this->getTablePrefix() . 'listing_location';

        // Join with location table to get full listing data
        $sql = "SELECT l.*, loc.city, loc.state_or_province, loc.postal_code,
                       loc.street_number, loc.street_name, loc.unit_number,
                       loc.latitude, loc.longitude, loc.county_or_parish,
                       loc.subdivision_name, loc.school_district
                FROM {$listingsTable} l
                LEFT JOIN {$locationTable} loc ON l.listing_id = loc.listing_id
                WHERE l.listing_id = %s";

        $results = $this->query($sql, [$listingId]);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Get listing photos
     *
     * @since 6.9.4 - Fixed column names (listing_id, order_num)
     */
    public function getListingPhotos(string $listingId): array {
        $tables = $this->getTables();
        $photosTable = $tables['photos'] ?? $this->getTablePrefix() . 'media';

        // Check if table exists
        $tableExists = $this->wpdb->get_var(
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $photosTable)
        );

        if (!$tableExists) {
            return [];
        }

        $sql = "SELECT * FROM {$photosTable} WHERE listing_id = %s ORDER BY order_num ASC";

        $results = $this->query($sql, [$listingId]);
        return $results ?: [];
    }

    /**
     * Get distinct values for a field
     *
     * @since 6.9.4 - Added support for location fields with proper JOIN
     */
    public function getDistinctValues(string $field, array $filters = []): array {
        $tables = $this->getTables();
        if (!$tables || !isset($tables['listings'])) {
            return [];
        }

        $listingsTable = $tables['listings'];
        $locationTable = $tables['location'] ?? $this->getTablePrefix() . 'listing_location';

        // Map field names and determine if we need location table
        $locationFields = ['city', 'state_or_province', 'postal_code', 'county_or_parish', 'subdivision_name'];
        $isLocationField = in_array($field, $locationFields);

        if ($isLocationField) {
            $sql = "SELECT DISTINCT loc.{$field}
                    FROM {$locationTable} loc
                    INNER JOIN {$listingsTable} l ON loc.listing_id = l.listing_id";
            $selectField = "loc.{$field}";
        } else {
            $sql = "SELECT DISTINCT l.{$field} FROM {$listingsTable} l";
            $selectField = "l.{$field}";
        }

        $params = [];
        $conditions = [];

        // Apply filters using correct column names
        if (!empty($filters)) {
            foreach ($filters as $filterField => $value) {
                if ($filterField === 'status') {
                    $conditions[] = "l.standard_status = %s";
                } elseif ($filterField === 'property_type') {
                    $conditions[] = "l.property_type = %s";
                } elseif (in_array($filterField, $locationFields)) {
                    $conditions[] = "loc.{$filterField} = %s";
                } else {
                    $conditions[] = "l.{$filterField} = %s";
                }
                $params[] = $value;
            }
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY {$selectField}";

        $results = $this->query($sql, $params);
        return array_column($results ?: [], $field);
    }

    /**
     * Get listings for map display with spatial filtering
     *
     * @since 6.9.4 - Fixed column names, proper location table JOIN
     */
    public function getListingsForMap(array $bounds, array $filters = [], int $zoom = 10): array {
        $tables = $this->getTables();
        if (!$tables || !isset($tables['listings'])) {
            return [];
        }

        $listingsTable = $tables['listings'];
        $locationTable = $tables['location'] ?? $this->getTablePrefix() . 'listing_location';
        list($north, $south, $east, $west) = $bounds;

        $sql = "SELECT l.listing_id, loc.latitude, loc.longitude, l.list_price,
                       l.standard_status, loc.city, l.property_type,
                       l.bedrooms_total, l.bathrooms_total_integer, l.living_area
                FROM {$listingsTable} l
                INNER JOIN {$locationTable} loc ON l.listing_id = loc.listing_id";

        $params = [];
        $conditions = [];

        // Spatial bounds (location table)
        $conditions[] = "loc.latitude BETWEEN %f AND %f";
        $conditions[] = "loc.longitude BETWEEN %f AND %f";
        $params[] = $south;
        $params[] = $north;
        $params[] = $west;
        $params[] = $east;

        // Additional filters
        if (!empty($filters)) {
            foreach ($filters as $field => $value) {
                if ($field === 'city' && !empty($value)) {
                    $conditions[] = "loc.city = %s";
                    $params[] = $value;
                } elseif ($field === 'min_price' && $value > 0) {
                    $conditions[] = "l.list_price >= %f";
                    $params[] = (float) $value;
                } elseif ($field === 'max_price' && $value > 0) {
                    $conditions[] = "l.list_price <= %f";
                    $params[] = (float) $value;
                } elseif ($field === 'status' && !empty($value)) {
                    $conditions[] = "l.standard_status = %s";
                    $params[] = $value;
                } elseif ($field === 'property_type' && !empty($value)) {
                    $conditions[] = "l.property_type = %s";
                    $params[] = $value;
                }
            }
        }

        $sql .= " WHERE " . implode(' AND ', $conditions);

        // Limit based on zoom level for performance
        $limit = $zoom >= 14 ? 200 : 500;
        $sql .= " ORDER BY l.modification_timestamp DESC LIMIT {$limit}";

        return $this->query($sql, $params) ?: [];
    }

    /**
     * Get count of listings matching criteria
     *
     * Uses efficient SQL COUNT() instead of fetching all records
     *
     * @param array $criteria Search criteria
     * @return int Count of matching listings
     * @since 6.9.3
     * @since 6.9.4 - Fixed column names, added location JOIN support
     */
    public function getListingCount(array $criteria = []): int {
        $tables = $this->getTables();
        if (!$tables || !isset($tables['listings'])) {
            return 0;
        }

        $listingsTable = $tables['listings'];
        $locationTable = $tables['location'] ?? $this->getTablePrefix() . 'listing_location';
        $needsLocationJoin = $this->needsLocationJoin($criteria);

        // Build COUNT query with optional location JOIN
        if ($needsLocationJoin) {
            $sql = "SELECT COUNT(*) FROM {$listingsTable} l
                    LEFT JOIN {$locationTable} loc ON l.listing_id = loc.listing_id";
        } else {
            $sql = "SELECT COUNT(*) FROM {$listingsTable} l";
        }

        $params = [];
        $conditions = [];

        // Build WHERE conditions using correct snake_case column names
        if (!empty($criteria)) {
            foreach ($criteria as $field => $value) {
                switch ($field) {
                    case 'city':
                        $conditions[] = "loc.city = %s";
                        $params[] = $value;
                        break;
                    case 'state':
                        $conditions[] = "loc.state_or_province = %s";
                        $params[] = $value;
                        break;
                    case 'postal_code':
                    case 'zip':
                        $conditions[] = "loc.postal_code = %s";
                        $params[] = $value;
                        break;
                    case 'min_price':
                        $conditions[] = "l.list_price >= %f";
                        $params[] = (float) $value;
                        break;
                    case 'max_price':
                        $conditions[] = "l.list_price <= %f";
                        $params[] = (float) $value;
                        break;
                    case 'property_type':
                        $conditions[] = "l.property_type = %s";
                        $params[] = $value;
                        break;
                    case 'status':
                        $conditions[] = "l.standard_status = %s";
                        $params[] = $value;
                        break;
                    case 'updated_since':
                        $conditions[] = "l.modification_timestamp >= %s";
                        $params[] = $value;
                        break;
                    default:
                        if (is_string($value)) {
                            $conditions[] = "l.{$field} = %s";
                            $params[] = $value;
                        }
                        break;
                }
            }
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        try {
            if (!empty($params)) {
                $sql = $this->wpdb->prepare($sql, $params);
            }
            $count = $this->wpdb->get_var($sql);
            return (int) ($count ?? 0);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Bridge MLS Data Provider count error: " . $e->getMessage());
            }
            return 0;
        }
    }
}