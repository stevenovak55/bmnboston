<?php
/**
 * Extraction Engine Interface for MLS data extraction
 *
 * @package BridgeMLS\Contracts
 * @since 1.0.0
 */

namespace BridgeMLS\Contracts;

/**
 * Interface for MLS extraction engines
 */
interface ExtractionEngineInterface {

    /**
     * Initialize the extraction engine
     *
     * @param array $config Configuration parameters
     * @return bool True on successful initialization
     */
    public function initialize(array $config = []): bool;

    /**
     * Test connection to MLS data source
     *
     * @return bool True if connection is successful
     */
    public function testConnection(): bool;

    /**
     * Extract listings from MLS source
     *
     * @param array $filters Extraction filters
     * @param int $limit Maximum number of listings to extract
     * @return array Array of extracted listings
     */
    public function extractListings(array $filters = [], int $limit = 1000): array;

    /**
     * Extract photos for listings
     *
     * @param array $listingIds Array of listing IDs
     * @return array Array of photo data
     */
    public function extractPhotos(array $listingIds = []): array;

    /**
     * Extract agent information
     *
     * @param array $filters Agent filters
     * @return array Array of agent data
     */
    public function extractAgents(array $filters = []): array;

    /**
     * Extract office information
     *
     * @param array $filters Office filters
     * @return array Array of office data
     */
    public function extractOffices(array $filters = []): array;

    /**
     * Get extraction statistics
     *
     * @return array Statistics about last extraction
     */
    public function getStatistics(): array;

    /**
     * Get supported MLS systems
     *
     * @return array Array of supported MLS system identifiers
     */
    public function getSupportedSystems(): array;

    /**
     * Validate extraction configuration
     *
     * @param array $config Configuration to validate
     * @return array Array of validation errors (empty if valid)
     */
    public function validateConfig(array $config): array;

    /**
     * Get extraction progress
     *
     * @return array Progress information
     */
    public function getProgress(): array;

    /**
     * Cancel ongoing extraction
     *
     * @return bool True if cancellation was successful
     */
    public function cancel(): bool;

    /**
     * Cleanup temporary data and resources
     *
     * @return bool True if cleanup was successful
     */
    public function cleanup(): bool;
}