<?php
/**
 * Listing Repository for Bridge MLS data access
 *
 * @package BridgeMLS\Repositories
 * @since 1.0.0
 */

namespace BridgeMLS\Repositories;

/**
 * Repository for MLS listing data access
 */
class ListingRepository {

    /**
     * WordPress database instance
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Listings table name
     * @var string
     */
    private string $table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'bme_listings';
    }

    /**
     * Find listings by criteria
     */
    public function findBy(array $criteria = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '%s'));
                    $conditions[] = "{$field} IN ({$placeholders})";
                    $params = array_merge($params, $value);
                } else {
                    $conditions[] = "{$field} = %s";
                    $params[] = $value;
                }
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if (!empty($orderBy)) {
            $orderClauses = [];
            foreach ($orderBy as $field => $direction) {
                $orderClauses[] = "{$field} {$direction}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        if ($limit !== null) {
            $sql .= " LIMIT %d OFFSET %d";
            $params[] = $limit;
            $params[] = $offset;
        }

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Find a single listing by ID
     */
    public function findById(string $id): ?array {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE ListingId = %s", $id);
        $result = $this->wpdb->get_row($sql, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Create or update listing
     */
    public function save(array $data): bool {
        $listingId = $data['ListingId'] ?? null;
        if (!$listingId) {
            return false;
        }

        $existing = $this->findById($listingId);

        if ($existing) {
            return $this->update($listingId, $data);
        } else {
            return $this->create($data);
        }
    }

    /**
     * Create new listing
     */
    public function create(array $data): bool {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->insert($this->table, $data);
        return $result !== false;
    }

    /**
     * Update existing listing
     */
    public function update(string $listingId, array $data): bool {
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update(
            $this->table,
            $data,
            ['ListingId' => $listingId],
            null,
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Delete listing
     */
    public function delete(string $listingId): bool {
        $result = $this->wpdb->delete(
            $this->table,
            ['ListingId' => $listingId],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Bulk insert listings
     */
    public function bulkInsert(array $listings): int {
        if (empty($listings)) {
            return 0;
        }

        $inserted = 0;
        $batchSize = 100;
        $batches = array_chunk($listings, $batchSize);

        foreach ($batches as $batch) {
            $values = [];
            $placeholders = [];

            foreach ($batch as $listing) {
                $listing['created_at'] = current_time('mysql');
                $listing['updated_at'] = current_time('mysql');

                $placeholders[] = '(' . implode(',', array_fill(0, count($listing), '%s')) . ')';
                $values = array_merge($values, array_values($listing));
            }

            if (!empty($values)) {
                $fields = array_keys($batch[0]);
                $sql = "INSERT INTO {$this->table} (" . implode(',', $fields) . ") VALUES " . implode(',', $placeholders);

                $prepared = $this->wpdb->prepare($sql, $values);
                $result = $this->wpdb->query($prepared);

                if ($result !== false) {
                    $inserted += count($batch);
                }
            }
        }

        return $inserted;
    }

    /**
     * Count listings
     */
    public function count(array $criteria = []): int {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        $params = [];

        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                $conditions[] = "{$field} = %s";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Get listings that need updates
     */
    public function getNeedingUpdates(int $hours = 24): array {
        // Use wp_date() for WordPress timezone consistency
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($hours * HOUR_IN_SECONDS));

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE updated_at < %s
             AND StandardStatus IN ('Active', 'ActiveUnderContract')
             ORDER BY updated_at ASC",
            $cutoff
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN StandardStatus = 'Active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN StandardStatus = 'Closed' THEN 1 ELSE 0 END) as sold,
                    SUM(CASE WHEN StandardStatus = 'Pending' THEN 1 ELSE 0 END) as pending,
                    AVG(ListPrice) as avg_price,
                    MIN(ListPrice) as min_price,
                    MAX(ListPrice) as max_price,
                    COUNT(DISTINCT City) as cities,
                    COUNT(DISTINCT PropertyType) as property_types
                FROM {$this->table}";

        return $this->wpdb->get_row($sql, ARRAY_A) ?: [];
    }

    /**
     * Find duplicates by address
     */
    public function findDuplicates(): array {
        $sql = "SELECT UnparsedAddress, COUNT(*) as count
                FROM {$this->table}
                GROUP BY UnparsedAddress
                HAVING count > 1
                ORDER BY count DESC";

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Clean up old listings
     */
    public function cleanupOld(int $days = 90): int {
        // Use wp_date() for WordPress timezone consistency
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        $result = $this->wpdb->delete(
            $this->table,
            [
                'StandardStatus' => 'Closed',
                'updated_at <' => $cutoff
            ],
            ['%s', '%s']
        );

        return $result ?: 0;
    }

    /**
     * Get listings by geographic bounds
     */
    public function findByBounds(float $north, float $south, float $east, float $west): array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE Latitude BETWEEN %f AND %f
             AND Longitude BETWEEN %f AND %f
             AND StandardStatus = 'Active'",
            $south, $north, $west, $east
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Update listing status
     */
    public function updateStatus(string $listingId, string $status): bool {
        return $this->update($listingId, [
            'StandardStatus' => $status,
            'StatusChangeTimestamp' => current_time('mysql')
        ]);
    }

    /**
     * Get recent activity
     */
    public function getRecentActivity(int $days = 7): array {
        // Use wp_date() for WordPress timezone consistency
        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));

        $sql = $this->wpdb->prepare(
            "SELECT ListingId, StandardStatus, ListPrice, City, PropertyType, updated_at
             FROM {$this->table}
             WHERE updated_at >= %s
             ORDER BY updated_at DESC
             LIMIT 100",
            $cutoff
        );

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }
}