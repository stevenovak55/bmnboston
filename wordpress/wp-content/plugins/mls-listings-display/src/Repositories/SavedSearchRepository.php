<?php
/**
 * Saved Search Repository for database access
 *
 * @package MLSDisplay\Repositories
 * @since 4.8.0
 */

namespace MLSDisplay\Repositories;

use MLSDisplay\Contracts\RepositoryInterface;

/**
 * Repository for saved search data access
 */
class SavedSearchRepository implements RepositoryInterface {

    /**
     * WordPress database instance
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Saved searches table name
     * @var string
     */
    private string $table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'mld_saved_searches';
    }

    /**
     * Find all saved searches
     */
    public function findAll(array $criteria = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if (!empty($criteria)) {
            $conditions = [];
            foreach ($criteria as $field => $value) {
                $conditions[] = "{$field} = %s";
                $params[] = $value;
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
     * Find a saved search by ID
     */
    public function findById($id): ?array {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id);
        $result = $this->wpdb->get_row($sql, ARRAY_A);
        return $result ?: null;
    }

    /**
     * Find saved searches by criteria
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array {
        return $this->findAll($criteria, $orderBy, $limit, $offset);
    }

    /**
     * Find a single saved search by criteria
     */
    public function findOneBy(array $criteria): ?array {
        $results = $this->findBy($criteria, [], 1, 0);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Create a new saved search
     */
    public function create(array $data) {
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->insert($this->table, $data);
        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Update a saved search
     */
    public function update($id, array $data): bool {
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update(
            $this->table,
            $data,
            ['id' => $id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a saved search
     */
    public function delete($id): bool {
        $result = $this->wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Count saved searches
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
     * Check if a saved search exists
     */
    public function exists($id): bool {
        return $this->findById($id) !== null;
    }

    /**
     * Find saved searches by user ID
     */
    public function findByUserId(int $userId): array {
        return $this->findBy(['user_id' => $userId], ['created_at' => 'DESC']);
    }

    /**
     * Find active saved searches with notifications enabled
     */
    public function findActiveWithNotifications(): array {
        return $this->findBy([
            'status' => 'active',
            'notifications_enabled' => 1
        ]);
    }

    /**
     * Find saved searches that need notification processing
     *
     * @since 6.11.8 Fixed column names to match database schema
     * @since 6.13.8 Use WordPress timezone instead of MySQL NOW()
     */
    public function findDueForNotification(): array {
        // Use current_time('mysql') for WordPress timezone consistency
        $wp_now = current_time('mysql');

        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table}
                WHERE is_active = 1
                AND notification_frequency IS NOT NULL
                AND (last_notified_at IS NULL OR last_notified_at < DATE_SUB(%s, INTERVAL
                    CASE notification_frequency
                        WHEN 'instant' THEN 0
                        WHEN 'hourly' THEN 1
                        WHEN 'daily' THEN 24
                        WHEN 'weekly' THEN 168
                        ELSE 24
                    END HOUR))", $wp_now);

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Update last notification sent timestamp
     *
     * @since 6.11.8 Fixed column name to match database schema (last_notified_at)
     */
    public function updateLastNotificationSent(int $id): bool {
        return $this->update($id, ['last_notified_at' => current_time('mysql')]);
    }

    /**
     * Get saved search statistics
     */
    public function getStatistics(): array {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN notifications_enabled = 1 THEN 1 ELSE 0 END) as with_notifications,
                    COUNT(DISTINCT user_id) as unique_users
                FROM {$this->table}";

        return $this->wpdb->get_row($sql, ARRAY_A) ?: [];
    }
}