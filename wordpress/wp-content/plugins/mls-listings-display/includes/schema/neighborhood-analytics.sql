-- ========================================
-- Neighborhood Analytics Database Schema
-- Version: 1.0.0
-- Description: Tables for storing calculated neighborhood market analytics
-- ========================================

-- Table: wp_mld_neighborhood_analytics
-- Purpose: Store calculated analytics metrics for each neighborhood/city
CREATE TABLE IF NOT EXISTS `wp_mld_neighborhood_analytics` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `city` varchar(100) NOT NULL,
  `state` varchar(2) NOT NULL DEFAULT '',
  `zip_code` varchar(10) DEFAULT NULL,
  `period` varchar(20) NOT NULL COMMENT '6_months, 12_months, 24_months, current',

  -- Price Metrics
  `median_price` decimal(12,2) DEFAULT NULL,
  `average_price` decimal(12,2) DEFAULT NULL,
  `min_price` decimal(12,2) DEFAULT NULL,
  `max_price` decimal(12,2) DEFAULT NULL,
  `price_per_sqft_median` decimal(10,2) DEFAULT NULL,
  `price_per_sqft_average` decimal(10,2) DEFAULT NULL,
  `price_change_pct` decimal(5,2) DEFAULT NULL COMMENT 'Percentage change vs previous period',
  `price_change_amount` decimal(12,2) DEFAULT NULL,

  -- Market Velocity Metrics
  `avg_days_on_market` int(11) DEFAULT NULL,
  `median_days_on_market` int(11) DEFAULT NULL,
  `dom_change_pct` decimal(5,2) DEFAULT NULL,
  `avg_days_to_close` int(11) DEFAULT NULL,
  `listing_turnover_rate` decimal(5,2) DEFAULT NULL COMMENT 'Percentage of listings sold',

  -- Inventory Metrics
  `active_listings_count` int(11) DEFAULT 0,
  `pending_listings_count` int(11) DEFAULT 0,
  `sold_listings_count` int(11) DEFAULT 0,
  `total_inventory` int(11) DEFAULT 0,
  `inventory_change_pct` decimal(5,2) DEFAULT NULL,
  `months_of_supply` decimal(5,2) DEFAULT NULL COMMENT 'Inventory divided by monthly sales rate',
  `absorption_rate` decimal(5,2) DEFAULT NULL COMMENT 'Percentage of inventory sold per month',

  -- Sales Performance Metrics
  `sale_to_list_ratio` decimal(5,4) DEFAULT NULL COMMENT 'Sale price / List price average',
  `sale_to_list_median` decimal(5,4) DEFAULT NULL,
  `avg_seller_concession_pct` decimal(5,2) DEFAULT NULL,
  `price_reduction_pct` decimal(5,2) DEFAULT NULL COMMENT 'Percentage of listings with price reductions',
  `avg_price_reduction_amount` decimal(12,2) DEFAULT NULL,

  -- Market Heat Index (custom calculation)
  `market_heat_index` decimal(5,2) DEFAULT NULL COMMENT 'Hot: 70-100, Balanced: 40-69, Cold: 0-39',
  `market_classification` varchar(20) DEFAULT NULL COMMENT 'hot, balanced, cold, buyers_market, sellers_market',

  -- Metadata
  `property_type` varchar(50) DEFAULT 'all' COMMENT 'all, Single Family, Condo, etc.',
  `data_points` int(11) DEFAULT 0 COMMENT 'Number of listings used in calculation',
  `calculation_date` datetime NOT NULL,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_analytics` (`city`,`state`,`period`,`property_type`),
  KEY `idx_city` (`city`),
  KEY `idx_state` (`state`),
  KEY `idx_period` (`period`),
  KEY `idx_calculation_date` (`calculation_date`),
  KEY `idx_market_heat` (`market_heat_index`),
  KEY `idx_city_period` (`city`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Calculated neighborhood market analytics';

-- Table: wp_mld_neighborhood_trends
-- Purpose: Store historical trend data points for charting
CREATE TABLE IF NOT EXISTS `wp_mld_neighborhood_trends` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `city` varchar(100) NOT NULL,
  `state` varchar(2) NOT NULL DEFAULT '',
  `property_type` varchar(50) DEFAULT 'all',
  `metric_name` varchar(50) NOT NULL COMMENT 'price, dom, inventory, sales_volume, etc.',
  `metric_value` decimal(15,2) NOT NULL,
  `data_date` date NOT NULL COMMENT 'Date this metric value applies to (typically month-end)',
  `calculation_date` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_trend` (`city`,`state`,`property_type`,`metric_name`,`data_date`),
  KEY `idx_city_metric` (`city`,`metric_name`),
  KEY `idx_data_date` (`data_date`),
  KEY `idx_city_date` (`city`,`data_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historical trend data for neighborhood analytics charts';

-- Table: wp_mld_neighborhood_meta
-- Purpose: Store additional neighborhood metadata (demographics, amenities, etc.)
CREATE TABLE IF NOT EXISTS `wp_mld_neighborhood_meta` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `city` varchar(100) NOT NULL,
  `state` varchar(2) NOT NULL DEFAULT '',
  `meta_key` varchar(100) NOT NULL,
  `meta_value` longtext DEFAULT NULL,
  `meta_type` varchar(20) DEFAULT 'string' COMMENT 'string, number, json, array',
  `source` varchar(50) DEFAULT NULL COMMENT 'census, google_places, manual, etc.',
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_meta` (`city`,`state`,`meta_key`),
  KEY `idx_city` (`city`,`state`),
  KEY `idx_meta_key` (`meta_key`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Additional neighborhood metadata (demographics, amenities, etc.)';

-- Table: wp_mld_analytics_cache
-- Purpose: Cache complex calculation results to improve performance
CREATE TABLE IF NOT EXISTS `wp_mld_analytics_cache` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(255) NOT NULL,
  `cache_value` longtext NOT NULL,
  `cache_group` varchar(50) DEFAULT 'analytics',
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cache` (`cache_key`,`cache_group`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_cache_group` (`cache_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache for analytics calculations';
