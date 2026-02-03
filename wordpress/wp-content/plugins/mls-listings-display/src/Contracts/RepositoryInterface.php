<?php
/**
 * Repository Interface for data access abstraction
 *
 * @package MLSDisplay\Contracts
 * @since 4.8.0
 */

namespace MLSDisplay\Contracts;

/**
 * Base repository interface
 */
interface RepositoryInterface {

    /**
     * Find all records with optional criteria
     *
     * @param array $criteria Search criteria
     * @param array $orderBy Order by clauses
     * @param int|null $limit Result limit
     * @param int $offset Result offset
     * @return array Array of records
     */
    public function findAll(array $criteria = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array;

    /**
     * Find a single record by ID
     *
     * @param mixed $id Record ID
     * @return array|null Record data or null if not found
     */
    public function findById($id): ?array;

    /**
     * Find records by criteria
     *
     * @param array $criteria Search criteria
     * @param array $orderBy Order by clauses
     * @param int|null $limit Result limit
     * @param int $offset Result offset
     * @return array Array of records
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array;

    /**
     * Find a single record by criteria
     *
     * @param array $criteria Search criteria
     * @return array|null Record data or null if not found
     */
    public function findOneBy(array $criteria): ?array;

    /**
     * Create a new record
     *
     * @param array $data Record data
     * @return mixed Created record ID or false on failure
     */
    public function create(array $data);

    /**
     * Update a record by ID
     *
     * @param mixed $id Record ID
     * @param array $data Updated data
     * @return bool True on success, false on failure
     */
    public function update($id, array $data): bool;

    /**
     * Delete a record by ID
     *
     * @param mixed $id Record ID
     * @return bool True on success, false on failure
     */
    public function delete($id): bool;

    /**
     * Count records matching criteria
     *
     * @param array $criteria Search criteria
     * @return int Number of matching records
     */
    public function count(array $criteria = []): int;

    /**
     * Check if a record exists
     *
     * @param mixed $id Record ID
     * @return bool True if record exists
     */
    public function exists($id): bool;
}