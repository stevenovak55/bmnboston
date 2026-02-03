-- User-Contributed Property Data Table
-- Stores additional property characteristics not in MLS data

CREATE TABLE IF NOT EXISTS wp_mld_user_property_data (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,

    -- Road Type
    road_type VARCHAR(20) DEFAULT NULL COMMENT 'main_road, neighborhood_road',
    road_type_updated_by BIGINT UNSIGNED DEFAULT NULL COMMENT 'User ID who last updated',
    road_type_updated_at DATETIME DEFAULT NULL,

    -- Property Condition
    property_condition VARCHAR(30) DEFAULT NULL COMMENT 'new, fully_renovated, some_updates, needs_updating, distressed',
    condition_updated_by BIGINT UNSIGNED DEFAULT NULL,
    condition_updated_at DATETIME DEFAULT NULL,

    -- Auto-detection flags
    is_new_construction TINYINT(1) DEFAULT 0 COMMENT 'Auto-flagged if year_built within 3 years',

    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY listing_id (listing_id),
    KEY road_type (road_type),
    KEY property_condition (property_condition),
    KEY road_type_updated_at (road_type_updated_at),
    KEY condition_updated_at (condition_updated_at),

    CONSTRAINT fk_user_property_listing
        FOREIGN KEY (listing_id)
        REFERENCES wp_bme_listings(listing_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User-contributed property characteristics for CMA adjustments';
