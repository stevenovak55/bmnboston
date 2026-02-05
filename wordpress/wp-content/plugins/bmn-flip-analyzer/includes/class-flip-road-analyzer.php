<?php
/**
 * Road Type Analyzer.
 *
 * Determines road type using:
 * 1. OpenStreetMap Overpass API (primary - free, accurate)
 * 2. Google Street View + Claude Vision (optional enhancement)
 * 3. Street name heuristic (fallback)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Road_Analyzer {

    /** OSM Overpass API endpoint */
    const OVERPASS_API = 'https://overpass-api.de/api/interpreter';

    /** Road type mapping from OSM highway tags to our categories */
    const OSM_ROAD_MAP = [
        // Quiet residential
        'residential'    => 'quiet-residential',
        'living_street'  => 'cul-de-sac',
        'service'        => 'quiet-residential',
        'pedestrian'     => 'quiet-residential',
        'footway'        => 'quiet-residential',
        'path'           => 'quiet-residential',

        // Moderate traffic
        'tertiary'       => 'moderate-traffic',
        'tertiary_link'  => 'moderate-traffic',
        'unclassified'   => 'moderate-traffic',

        // Busy roads
        'secondary'      => 'busy-road',
        'secondary_link' => 'busy-road',
        'primary'        => 'busy-road',
        'primary_link'   => 'busy-road',

        // Highway adjacent
        'trunk'          => 'highway-adjacent',
        'trunk_link'     => 'highway-adjacent',
        'motorway'       => 'highway-adjacent',
        'motorway_link'  => 'highway-adjacent',
    ];

    /** Street name patterns that indicate busy roads */
    const BUSY_ROAD_PATTERNS = [
        '/\b(highway|hwy|route|rt|state road|sr|us)\s*\d+/i',
        '/\b(avenue|ave|boulevard|blvd|parkway|pkwy)\b/i',
        '/\b(main\s+st|main\s+street)\b/i',
        '/\b(broadway)\b/i',
    ];

    /** Street name patterns that indicate quiet roads */
    const QUIET_ROAD_PATTERNS = [
        '/\b(lane|ln|court|ct|circle|cir|way|place|pl|terrace|ter|path)\b/i',
        '/\b(cul-de-sac|dead\s*end)\b/i',
    ];

    /**
     * Analyze road type for a property.
     *
     * @param float  $lat Latitude.
     * @param float  $lng Longitude.
     * @param string $street_name Street name for targeted OSM query.
     * @return array {
     *     road_type: string,
     *     source: string (osm|osm-named|heuristic),
     *     osm_highway: string|null,
     *     confidence: string (high|medium|low),
     *     details: string,
     * }
     */
    public static function analyze(float $lat, float $lng, string $street_name = ''): array {
        $result = [
            'road_type'   => 'unknown',
            'source'      => 'none',
            'osm_highway' => null,
            'confidence'  => 'low',
            'details'     => '',
        ];

        // Extract just the street name without suffix for OSM query
        $clean_street_name = self::extract_street_name($street_name);

        // Try OSM with specific street name first (most accurate)
        if (!empty($clean_street_name)) {
            $osm_named = self::query_osm_by_name($lat, $lng, $clean_street_name);
            if ($osm_named) {
                $result['road_type']   = $osm_named['road_type'];
                $result['source']      = 'osm-named';
                $result['osm_highway'] = $osm_named['highway'];
                $result['confidence']  = 'high';
                $result['details']     = "OSM highway type: {$osm_named['highway']}, road name: {$osm_named['name']}";
                return $result;
            }
        }

        // Fall back to nearest road query
        $osm_result = self::query_osm($lat, $lng);
        if ($osm_result) {
            $result['road_type']   = $osm_result['road_type'];
            $result['source']      = 'osm';
            $result['osm_highway'] = $osm_result['highway'];
            $result['confidence']  = 'high';
            $result['details']     = "OSM highway type: {$osm_result['highway']}, road name: {$osm_result['name']}";
            return $result;
        }

        // Fallback to street name heuristic
        if (!empty($street_name)) {
            $heuristic = self::analyze_street_name($street_name);
            if ($heuristic['road_type'] !== 'unknown') {
                $result['road_type']  = $heuristic['road_type'];
                $result['source']     = 'heuristic';
                $result['confidence'] = 'medium';
                $result['details']    = $heuristic['reason'];
                return $result;
            }
        }

        return $result;
    }

    /**
     * Extract just the street name without suffix (St, Ave, etc.)
     */
    private static function extract_street_name(string $full_name): string {
        // Remove common suffixes
        $suffixes = ['street', 'st', 'avenue', 'ave', 'road', 'rd', 'drive', 'dr',
                     'lane', 'ln', 'court', 'ct', 'circle', 'cir', 'way', 'place', 'pl',
                     'boulevard', 'blvd', 'terrace', 'ter', 'parkway', 'pkwy'];
        $pattern = '/\s+(' . implode('|', $suffixes) . ')\.?\s*$/i';
        return trim(preg_replace($pattern, '', $full_name));
    }

    /**
     * Query OSM for a specific street by name near coordinates.
     */
    private static function query_osm_by_name(float $lat, float $lng, string $street_name): ?array {
        // Search within ~0.5 mile bounding box
        $lat_delta = 0.01;  // ~0.7 miles
        $lng_delta = 0.01;

        $query = sprintf(
            '[out:json][timeout:10];way["name"~"%s",i]["highway"](%f,%f,%f,%f);out body;',
            addslashes($street_name),
            $lat - $lat_delta, $lng - $lng_delta,
            $lat + $lat_delta, $lng + $lng_delta
        );

        $response = wp_remote_post(self::OVERPASS_API, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $query,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['elements'])) {
            return null;
        }

        // Find the best match
        $best = null;
        foreach ($data['elements'] as $element) {
            if (!isset($element['tags']['highway'])) continue;

            $highway = $element['tags']['highway'];
            $name = $element['tags']['name'] ?? '';

            // Prefer exact match
            if (stripos($name, $street_name) !== false) {
                $best = ['highway' => $highway, 'name' => $name];
                break;
            }

            if (!$best) {
                $best = ['highway' => $highway, 'name' => $name];
            }
        }

        if (!$best) {
            return null;
        }

        $road_type = self::OSM_ROAD_MAP[$best['highway']] ?? 'moderate-traffic';

        return [
            'road_type' => $road_type,
            'highway'   => $best['highway'],
            'name'      => $best['name'],
        ];
    }

    /**
     * Query OpenStreetMap Overpass API for road type.
     *
     * @param float $lat Latitude.
     * @param float $lng Longitude.
     * @return array|null { road_type, highway, name } or null if not found.
     */
    private static function query_osm(float $lat, float $lng): ?array {
        // Query for roads within 50 meters of the coordinates
        // Using compact query format for reliability
        $query = sprintf(
            '[out:json][timeout:10];way(around:50,%f,%f)["highway"];out body;',
            $lat, $lng
        );

        // Use POST with raw body (not form data)
        $response = wp_remote_post(self::OVERPASS_API, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $query,  // Send query directly, not as form data
        ]);

        if (is_wp_error($response)) {
            error_log('Flip Road Analyzer OSM Error: ' . $response->get_error_message());
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            error_log('Flip Road Analyzer OSM HTTP Error: ' . $status);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['elements'])) {
            // No roads found, try larger radius
            return null;
        }

        // Find the closest/most relevant road
        // Prioritize named roads over service roads
        $best_road = null;
        foreach ($data['elements'] as $element) {
            if (!isset($element['tags']['highway'])) continue;

            $highway = $element['tags']['highway'];
            $name = $element['tags']['name'] ?? '';

            // Skip footways and paths unless they're the only option
            if (in_array($highway, ['footway', 'path', 'cycleway', 'steps'])) {
                if (!$best_road) {
                    $best_road = ['highway' => $highway, 'name' => $name];
                }
                continue;
            }

            // Prefer named roads
            if (!empty($name) || !$best_road) {
                $best_road = ['highway' => $highway, 'name' => $name];
            }
        }

        if (!$best_road) {
            return null;
        }

        // Map OSM highway type to our categories
        $road_type = self::OSM_ROAD_MAP[$best_road['highway']] ?? 'moderate-traffic';

        return [
            'road_type' => $road_type,
            'highway'   => $best_road['highway'],
            'name'      => $best_road['name'],
        ];
    }

    /**
     * Analyze street name for road type hints.
     *
     * @param string $street_name Street name.
     * @return array { road_type, reason }
     */
    private static function analyze_street_name(string $street_name): array {
        // Check for busy road patterns
        foreach (self::BUSY_ROAD_PATTERNS as $pattern) {
            if (preg_match($pattern, $street_name)) {
                return [
                    'road_type' => 'busy-road',
                    'reason'    => "Street name pattern suggests busy road: {$street_name}",
                ];
            }
        }

        // Check for quiet road patterns
        foreach (self::QUIET_ROAD_PATTERNS as $pattern) {
            if (preg_match($pattern, $street_name)) {
                return [
                    'road_type' => 'quiet-residential',
                    'reason'    => "Street name pattern suggests quiet residential: {$street_name}",
                ];
            }
        }

        return [
            'road_type' => 'unknown',
            'reason'    => 'No pattern match',
        ];
    }

    /**
     * Analyze road using Google Street View image + Claude Vision.
     * This is more expensive but more accurate for edge cases.
     *
     * @param float  $lat Latitude.
     * @param float  $lng Longitude.
     * @param string $api_key Google Maps API key.
     * @param string $claude_api_key Claude API key.
     * @return array|null Analysis result or null on failure.
     */
    public static function analyze_with_streetview(
        float $lat,
        float $lng,
        string $google_api_key,
        string $claude_api_key
    ): ?array {
        // Get Street View image
        $image_data = self::fetch_streetview_image($lat, $lng, $google_api_key);
        if (!$image_data) {
            return null;
        }

        // Analyze with Claude
        return self::analyze_image_with_claude($image_data, $claude_api_key);
    }

    /**
     * Fetch Google Street View image as base64.
     *
     * @param float  $lat Latitude.
     * @param float  $lng Longitude.
     * @param string $api_key Google Maps API key.
     * @return array|null { data, media_type } or null on failure.
     */
    private static function fetch_streetview_image(float $lat, float $lng, string $api_key): ?array {
        $url = sprintf(
            'https://maps.googleapis.com/maps/api/streetview?size=640x480&location=%f,%f&fov=90&heading=0&pitch=0&key=%s',
            $lat, $lng, $api_key
        );

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        return [
            'data'       => base64_encode($body),
            'media_type' => 'image/jpeg',
        ];
    }

    /**
     * Analyze Street View image with Claude Vision.
     */
    private static function analyze_image_with_claude(array $image_data, string $api_key): ?array {
        $prompt = <<<'PROMPT'
Analyze this Google Street View image to determine the road type for real estate investment purposes.

Return ONLY valid JSON:
{
  "road_type": "<cul-de-sac|dead-end|quiet-residential|moderate-traffic|busy-road|highway-adjacent>",
  "confidence": "<high|medium|low>",
  "observations": {
    "lane_count": <number of lanes visible>,
    "has_center_line": <true|false>,
    "has_double_yellow": <true|false>,
    "has_sidewalks": <true|false>,
    "visible_traffic": "<none|light|moderate|heavy>",
    "speed_indicators": "<residential 25mph|moderate 35mph|fast 45mph+|unknown>",
    "commercial_nearby": <true|false>
  },
  "reasoning": "<brief explanation of road type determination>"
}

Road type definitions:
- cul-de-sac: Circular dead-end, very quiet
- dead-end: Street ends, minimal traffic
- quiet-residential: Narrow residential street, no center lines or single white line
- moderate-traffic: Has center line, some traffic, typical suburban street
- busy-road: Double yellow lines, multiple lanes, significant traffic
- highway-adjacent: Near highway or major arterial road
PROMPT;

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => json_encode([
                'model'      => 'claude-sonnet-4-5-20250929',
                'max_tokens' => 512,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $image_data['media_type'],
                                'data'       => $image_data['data'],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ]],
            ]),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $text = $body['content'][0]['text'] ?? '';

        // Parse JSON from response
        $analysis = json_decode($text, true);
        if (!$analysis) {
            // Try to extract JSON from markdown
            if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
                $analysis = json_decode($matches[1], true);
            }
        }

        return $analysis;
    }

    /**
     * Get road type score for location scoring.
     */
    public static function get_road_type_score(string $road_type): float {
        return match ($road_type) {
            'cul-de-sac'        => 100,
            'dead-end'          => 95,
            'quiet-residential' => 85,
            'moderate-traffic'  => 60,
            'busy-road'         => 25,
            'highway-adjacent'  => 10,
            default             => 70, // Unknown - assume moderate
        };
    }

    /**
     * Get human-readable road type label.
     */
    public static function get_road_type_label(string $road_type): string {
        return match ($road_type) {
            'cul-de-sac'        => 'Cul-de-sac (Premium)',
            'dead-end'          => 'Dead End (Quiet)',
            'quiet-residential' => 'Quiet Residential',
            'moderate-traffic'  => 'Moderate Traffic',
            'busy-road'         => 'Busy Road (Concern)',
            'highway-adjacent'  => 'Highway Adjacent (Major Concern)',
            default             => 'Unknown',
        };
    }
}
