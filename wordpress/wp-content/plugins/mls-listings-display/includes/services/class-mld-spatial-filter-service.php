<?php
/**
 * MLD Spatial Filter Service
 *
 * Handles all geographic/spatial filtering for property searches.
 * Extracted from MLD_Query for better separation of concerns.
 *
 * @package MLS_Listings_Display
 * @subpackage Services
 * @since 6.11.5
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Spatial_Filter_Service
 *
 * Provides methods to build SQL conditions for spatial/geographic filtering:
 * - Polygon filtering (drawn shapes on map)
 * - GeoJSON boundaries (neighborhoods, districts)
 * - Bounding box filtering (viewport bounds)
 * - Radius filtering (distance from point)
 *
 * @since 6.11.5
 */
class MLD_Spatial_Filter_Service {

    /**
     * Singleton instance
     * @var MLD_Spatial_Filter_Service|null
     */
    private static $instance = null;

    /**
     * Cache for spatial support check
     * @var bool|null
     */
    private $has_spatial_support = null;

    /**
     * Get singleton instance
     *
     * @return MLD_Spatial_Filter_Service
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {}

    /**
     * Check if MySQL has spatial support (ST_Contains, etc.)
     *
     * @return bool
     */
    public function has_spatial_support() {
        if ($this->has_spatial_support === null) {
            global $wpdb;
            $this->has_spatial_support = $wpdb->get_var(
                "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_NAME = 'ST_Contains'"
            ) > 0;
        }
        return $this->has_spatial_support;
    }

    /**
     * Build SQL condition for GeoJSON geometry (districts, neighborhoods, etc.)
     * Handles both Polygon and MultiPolygon types
     *
     * @param array $geojson GeoJSON geometry object with 'type' and 'coordinates'
     * @param string $table_alias Table alias for coordinates column (default: 'll' for location table)
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5
     */
    public function build_geojson_condition($geojson, $table_alias = 'll') {
        if (empty($geojson) || !isset($geojson['type'])) {
            return null;
        }

        $polygon_conditions = [];

        if ($geojson['type'] === 'Polygon') {
            // Single polygon - use the outer ring (index 0)
            if (isset($geojson['coordinates'][0]) && is_array($geojson['coordinates'][0])) {
                $coords = [];
                foreach ($geojson['coordinates'][0] as $point) {
                    // Validate point structure
                    if (!is_array($point) || count($point) < 2) {
                        continue;
                    }
                    // GeoJSON is [lng, lat] format, convert to [lat, lng]
                    $coords[] = [$point[1], $point[0]];
                }

                // Only build condition if we have at least 3 points (minimum for a polygon)
                if (count($coords) >= 3) {
                    $condition = $this->build_polygon_condition($coords, $table_alias);
                    if ($condition) {
                        $polygon_conditions[] = $condition;
                    }
                }
            }
        } elseif ($geojson['type'] === 'MultiPolygon') {
            // Multiple polygons
            foreach ($geojson['coordinates'] as $polygon) {
                if (isset($polygon[0]) && is_array($polygon[0])) {
                    $coords = [];
                    foreach ($polygon[0] as $point) {
                        // Validate point structure
                        if (!is_array($point) || count($point) < 2) {
                            continue;
                        }
                        // GeoJSON is [lng, lat] format, convert to [lat, lng]
                        $coords[] = [$point[1], $point[0]];
                    }

                    // Only build condition if we have at least 3 points (minimum for a polygon)
                    if (count($coords) >= 3) {
                        $condition = $this->build_polygon_condition($coords, $table_alias);
                        if ($condition) {
                            $polygon_conditions[] = $condition;
                        }
                    }
                }
            }
        }

        if (!empty($polygon_conditions)) {
            // OR logic - point can be in any of the polygons
            return '(' . implode(' OR ', $polygon_conditions) . ')';
        }

        return null;
    }

    /**
     * Build SQL condition for point-in-polygon check using summary table columns
     * Uses latitude/longitude DECIMAL columns instead of POINT geometry
     *
     * This method uses the ray casting algorithm to determine if a point
     * is inside a polygon, implemented entirely in SQL for the summary table
     * which stores coordinates as separate DECIMAL columns.
     *
     * @param array $polygon_coords Array of [lat, lng] coordinate pairs
     * @param string $table_alias Optional table alias prefix (e.g., 's' for 's.latitude')
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5
     * @since 6.30.24 Added $table_alias parameter to prevent ambiguous column errors
     */
    public function build_summary_polygon_condition($polygon_coords, $table_alias = '') {
        global $wpdb;

        if (empty($polygon_coords) || count($polygon_coords) < 3) {
            return null;
        }

        // Close the polygon if not already closed
        if ($polygon_coords[0] !== $polygon_coords[count($polygon_coords) - 1]) {
            $polygon_coords[] = $polygon_coords[0];
        }

        // Build column names with optional table alias
        $lat_col = $table_alias ? "{$table_alias}.latitude" : 'latitude';
        $lng_col = $table_alias ? "{$table_alias}.longitude" : 'longitude';

        // Use ray casting algorithm with summary table columns
        // latitude and longitude are DECIMAL columns, not POINT geometry
        $conditions = [];
        $n = count($polygon_coords) - 1; // Exclude the closing point

        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $lat1 = $polygon_coords[$i][0];
            $lng1 = $polygon_coords[$i][1];
            $lat2 = $polygon_coords[$j][0];
            $lng2 = $polygon_coords[$j][1];

            // Ray casting algorithm condition using summary table columns
            $conditions[] = $wpdb->prepare(
                "(((%f <= {$lat_col} AND {$lat_col} < %f) OR (%f <= {$lat_col} AND {$lat_col} < %f))
                 AND ({$lng_col} < (%f - %f) * ({$lat_col} - %f) / (%f - %f) + %f))",
                $lat1, $lat2, $lat2, $lat1,
                $lng2, $lng1, $lat1, $lat2, $lat1, $lng1
            );
        }

        // Point is inside if it crosses an odd number of edges
        $condition = 'MOD((' . implode(' + ', array_map(function($c) {
            return "IF($c, 1, 0)";
        }, $conditions)) . '), 2) = 1';

        return $condition;
    }

    /**
     * Build SQL condition for point-in-polygon check
     * Uses MySQL spatial functions if available, otherwise falls back to ray casting algorithm
     *
     * @param array $polygon_coords Array of [lat, lng] coordinate pairs
     * @param string $table_alias Table alias for coordinates column (default: 'll')
     * @return string|null SQL condition or null if invalid
     * @since 6.11.5
     */
    public function build_polygon_condition($polygon_coords, $table_alias = 'll') {
        global $wpdb;

        if (empty($polygon_coords) || count($polygon_coords) < 3) {
            return null;
        }

        // Close the polygon if not already closed
        if ($polygon_coords[0] !== $polygon_coords[count($polygon_coords) - 1]) {
            $polygon_coords[] = $polygon_coords[0];
        }

        // Check if MySQL has spatial support (MySQL 5.6+)
        if ($this->has_spatial_support()) {
            // Use MySQL spatial functions
            $points = [];
            foreach ($polygon_coords as $coord) {
                $points[] = sprintf('%f %f', $coord[1], $coord[0]); // lng lat format for WKT
            }
            $polygon_wkt = 'POLYGON((' . implode(', ', $points) . '))';

            // Use ST_Contains to check if point is within polygon
            return $wpdb->prepare(
                "ST_Contains(ST_GeomFromText(%s), {$table_alias}.coordinates)",
                $polygon_wkt
            );
        } else {
            // Fallback to custom point-in-polygon SQL implementation
            // This uses the ray casting algorithm implemented in SQL
            $conditions = [];
            $n = count($polygon_coords) - 1; // Exclude the closing point

            for ($i = 0; $i < $n; $i++) {
                $j = ($i + 1) % $n;
                $lat1 = $polygon_coords[$i][0];
                $lng1 = $polygon_coords[$i][1];
                $lat2 = $polygon_coords[$j][0];
                $lng2 = $polygon_coords[$j][1];

                // Ray casting algorithm condition
                $conditions[] = $wpdb->prepare(
                    "(((%f <= ST_Y({$table_alias}.coordinates) AND ST_Y({$table_alias}.coordinates) < %f) OR (%f <= ST_Y({$table_alias}.coordinates) AND ST_Y({$table_alias}.coordinates) < %f))
                     AND (ST_X({$table_alias}.coordinates) < (%f - %f) * (ST_Y({$table_alias}.coordinates) - %f) / (%f - %f) + %f))",
                    min($lat1, $lat2), max($lat1, $lat2), min($lat1, $lat2), max($lat1, $lat2),
                    $lng2, $lng1, $lat1, $lat2, $lat1, $lng1
                );
            }

            // Count intersections - odd number means point is inside
            return "(MOD((" . implode(" + ", array_map(function($c) { return "IF($c, 1, 0)"; }, $conditions)) . "), 2) = 1)";
        }
    }

    /**
     * Build SQL condition for bounding box (viewport) filtering
     *
     * @param float $north Northern latitude bound
     * @param float $south Southern latitude bound
     * @param float $east Eastern longitude bound
     * @param float $west Western longitude bound
     * @param string $lat_column Column name for latitude (default: 'latitude')
     * @param string $lng_column Column name for longitude (default: 'longitude')
     * @return string SQL condition
     * @since 6.11.5
     */
    public function build_bounds_condition($north, $south, $east, $west, $lat_column = 'latitude', $lng_column = 'longitude') {
        global $wpdb;

        return $wpdb->prepare(
            "({$lat_column} BETWEEN %f AND %f AND {$lng_column} BETWEEN %f AND %f)",
            $south, $north, $west, $east
        );
    }

    /**
     * Build SQL condition for radius search (distance from a point)
     *
     * Uses the Haversine formula to calculate distance in miles.
     *
     * @param float $lat Center latitude
     * @param float $lng Center longitude
     * @param float $radius_miles Search radius in miles
     * @param string $lat_column Column name for latitude (default: 'latitude')
     * @param string $lng_column Column name for longitude (default: 'longitude')
     * @return string SQL condition
     * @since 6.11.5
     */
    public function build_radius_condition($lat, $lng, $radius_miles, $lat_column = 'latitude', $lng_column = 'longitude') {
        global $wpdb;

        // Haversine formula for distance in miles
        // 3959 is Earth's radius in miles
        $haversine = "
            (3959 * ACOS(
                COS(RADIANS(%f)) * COS(RADIANS({$lat_column})) *
                COS(RADIANS({$lng_column}) - RADIANS(%f)) +
                SIN(RADIANS(%f)) * SIN(RADIANS({$lat_column}))
            ))
        ";

        return $wpdb->prepare(
            "{$haversine} <= %f",
            $lat, $lng, $lat, $radius_miles
        );
    }

    /**
     * Validate polygon coordinates
     *
     * @param array $coords Array of coordinate pairs
     * @return bool Whether the coordinates form a valid polygon
     * @since 6.11.5
     */
    public function validate_polygon_coords($coords) {
        if (!is_array($coords) || count($coords) < 3) {
            return false;
        }

        foreach ($coords as $point) {
            if (!is_array($point) || count($point) < 2) {
                return false;
            }
            // Validate lat/lng ranges
            $lat = $point[0];
            $lng = $point[1];
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return false;
            }
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                return false;
            }
        }

        return true;
    }

    /**
     * Close a polygon if not already closed
     *
     * @param array $coords Array of coordinate pairs
     * @return array Closed polygon coordinates
     * @since 6.11.5
     */
    public function close_polygon($coords) {
        if (empty($coords)) {
            return $coords;
        }

        if ($coords[0] !== $coords[count($coords) - 1]) {
            $coords[] = $coords[0];
        }

        return $coords;
    }
}
