<?php
/**
 * MLS Data Provider Interface
 * 
 * Provides an abstraction layer between the Display plugin and data sources
 * This allows the Display plugin to work with different data providers
 * without direct dependency on the Extractor plugin
 *
 * @package MLS_Listings_Display
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for MLS data providers
 */
interface MLD_Data_Provider_Interface {
    
    /**
     * Get database tables array
     * 
     * @return array Array of table names
     */
    public function get_tables();
    
    /**
     * Get listings based on filters
     * 
     * @param array $filters Filter criteria
     * @param int $limit Number of results to return
     * @param int $offset Offset for pagination
     * @return array Array of listing data
     */
    public function get_listings($filters = [], $limit = 20, $offset = 0);
    
    /**
     * Get a single listing by ID
     * 
     * @param string $listing_id The listing ID
     * @return array|null Listing data or null if not found
     */
    public function get_listing($listing_id);
    
    /**
     * Get listing count based on filters
     * 
     * @param array $filters Filter criteria
     * @return int Number of listings matching filters
     */
    public function get_listing_count($filters = []);
    
    /**
     * Get distinct values for a field
     * 
     * @param string $field Field name
     * @param array $filters Optional filters
     * @return array Array of distinct values
     */
    public function get_distinct_values($field, $filters = []);
    
    /**
     * Search listings
     * 
     * @param string $keyword Search keyword
     * @param array $filters Additional filters
     * @param int $limit Number of results
     * @return array Search results
     */
    public function search_listings($keyword, $filters = [], $limit = 20);
    
    /**
     * Get listing media
     * 
     * @param string $listing_id The listing ID
     * @return array Array of media items
     */
    public function get_listing_media($listing_id);
    
    /**
     * Check if provider is available
     * 
     * @return bool True if provider is available
     */
    public function is_available();
    
    /**
     * Get provider version
     * 
     * @return string Provider version
     */
    public function get_version();
}