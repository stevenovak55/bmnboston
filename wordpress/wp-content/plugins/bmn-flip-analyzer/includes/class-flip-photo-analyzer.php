<?php
/**
 * Photo Analyzer using Claude Vision API.
 *
 * Analyzes property photos to assess renovation needs and refine rehab estimates.
 * Uses Anthropic Claude Vision API with structured JSON output.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_Photo_Analyzer {

    /** Claude model for vision analysis */
    const CLAUDE_MODEL = 'claude-sonnet-4-5-20250929';

    /** Max photos to analyze per property */
    const MAX_PHOTOS = 10;

    /** Default rehab cost per sqft when photo analysis unavailable */
    const DEFAULT_REHAB_COST_PER_SQFT = 30;

    /**
     * Get the Claude API key from the chatbot settings table.
     *
     * @return string|null API key or null if not configured.
     */
    private static function get_api_key(): ?string {
        global $wpdb;
        $settings_table = $wpdb->prefix . 'mld_chat_settings';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$settings_table}'") !== $settings_table) {
            return null;
        }

        // Get the Claude API key
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT setting_value, is_encrypted FROM {$settings_table} WHERE setting_key = %s",
            'claude_api_key'
        ));

        if (!$row || empty($row->setting_value)) {
            return null;
        }

        // Decrypt if encrypted
        if (!empty($row->is_encrypted)) {
            $key = wp_salt('auth');
            $decrypted = openssl_decrypt($row->setting_value, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
            return $decrypted ?: $row->setting_value;
        }

        return $row->setting_value;
    }

    /**
     * Analyze photos for a property.
     *
     * @param int $listing_id MLS listing ID.
     * @return array {
     *     success: bool,
     *     analysis: array|null (parsed JSON from Claude),
     *     rehab_cost_per_sqft: float,
     *     photo_score: float (0-100),
     *     error: string|null,
     * }
     */
    public static function analyze(int $listing_id): array {
        $result = [
            'success'             => false,
            'analysis'            => null,
            'rehab_cost_per_sqft' => self::DEFAULT_REHAB_COST_PER_SQFT,
            'photo_score'         => 50,
            'error'               => null,
        ];

        // Get API key from MLD chatbot settings
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            $result['error'] = 'Claude API key not configured in MLD Chatbot settings.';
            return $result;
        }

        // Get photo URLs
        $photos = self::get_photo_urls($listing_id);
        if (empty($photos)) {
            $result['error'] = 'No photos found for this listing.';
            return $result;
        }

        // Build message content with photos
        $content = self::build_message_content($photos);

        // Call Claude Vision API
        $response = self::call_claude_api($api_key, $content);

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        // Parse the response
        $analysis = self::parse_response($response);
        if (!$analysis) {
            $result['error'] = 'Failed to parse Claude response.';
            return $result;
        }

        $result['success']             = true;
        $result['analysis']            = $analysis;
        $result['rehab_cost_per_sqft'] = (float) ($analysis['estimated_cost_per_sqft'] ?? self::DEFAULT_REHAB_COST_PER_SQFT);
        $result['photo_score']         = (float) ($analysis['flip_photo_score'] ?? 50);

        return $result;
    }

    /**
     * Get photo URLs for a listing.
     */
    private static function get_photo_urls(int $listing_id): array {
        global $wpdb;
        $media_table = $wpdb->prefix . 'bme_media';

        // Get primary photos, ordered by preference
        // media_category contains values like 'Photo', 'Interior', 'Kitchen', etc.
        // order_index is the sort order
        $photos = $wpdb->get_col($wpdb->prepare(
            "SELECT media_url FROM {$media_table}
             WHERE listing_id = %s
               AND media_url IS NOT NULL
               AND media_url != ''
             ORDER BY
               CASE
                 WHEN media_category = 'Photo' AND order_index = 1 THEN 0
                 WHEN media_category = 'Interior' THEN 1
                 WHEN media_category = 'Kitchen' THEN 2
                 WHEN media_category = 'Bathroom' THEN 3
                 WHEN media_category = 'Exterior' THEN 4
                 ELSE 5
               END,
               order_index ASC
             LIMIT %d",
            $listing_id,
            self::MAX_PHOTOS
        ));

        return is_array($photos) ? $photos : [];
    }

    /**
     * Build message content array with images (base64 encoded).
     *
     * Fetches images from URLs and encodes as base64 to avoid robots.txt restrictions.
     */
    private static function build_message_content(array $photo_urls): array {
        $content = [];

        // Add images as base64
        foreach ($photo_urls as $url) {
            $image_data = self::fetch_image_as_base64($url);
            if ($image_data) {
                $content[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => $image_data['media_type'],
                        'data'       => $image_data['data'],
                    ],
                ];
            }
        }

        // Add analysis prompt
        $content[] = [
            'type' => 'text',
            'text' => self::get_analysis_prompt(),
        ];

        return $content;
    }

    /**
     * Fetch an image from URL and return as base64.
     *
     * @param string $url Image URL.
     * @return array|null { media_type: string, data: string } or null on failure.
     */
    private static function fetch_image_as_base64(string $url): ?array {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'image/*',
            ],
        ]);

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

        // Detect media type from content-type header or URL
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $media_type = self::detect_media_type($url, $content_type);

        if (!$media_type) {
            return null;
        }

        return [
            'media_type' => $media_type,
            'data'       => base64_encode($body),
        ];
    }

    /**
     * Detect image media type from URL or content-type header.
     *
     * @param string $url Image URL.
     * @param string $content_type Content-Type header value.
     * @return string|null MIME type or null if unsupported.
     */
    private static function detect_media_type(string $url, string $content_type): ?string {
        // Valid media types for Claude Vision
        $valid_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        // Try content-type header first
        if (!empty($content_type)) {
            $type = strtolower(trim(explode(';', $content_type)[0]));
            if (in_array($type, $valid_types, true)) {
                return $type;
            }
        }

        // Fall back to URL extension
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $ext_map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $ext_map[$ext] ?? null;
    }

    /**
     * Get the analysis prompt for Claude.
     */
    private static function get_analysis_prompt(): string {
        return <<<'PROMPT'
Analyze these real estate listing photos for renovation/flip potential.
Return ONLY valid JSON with this exact structure (no markdown, no explanation):
{
  "overall_condition": <number 1-10, where 10=move-in ready, 1=uninhabitable>,
  "renovation_level": "<cosmetic|moderate|major|gut>",
  "estimated_cost_per_sqft": <number between 15-100, typical ranges: cosmetic=15-25, moderate=25-50, major=50-80, gut=80-100>,
  "kitchen_condition": <number 1-10>,
  "bathroom_condition": <number 1-10>,
  "flooring_condition": <number 1-10>,
  "exterior_condition": <number 1-10>,
  "curb_appeal": <number 1-10>,
  "structural_concerns": <true|false>,
  "structural_details": "<description if concerns found, empty string if none>",
  "water_damage_signs": <true|false>,
  "dated_features": ["<list of dated elements that need updating>"],
  "positive_features": ["<list of features that don't need work>"],
  "renovation_summary": "<2-3 sentence summary of work needed and flip potential>",
  "flip_photo_score": <number 0-100, higher = better flip candidate from visual perspective>,
  "road_visible": <true|false, whether the road/street is visible in any exterior photos>,
  "road_type": "<cul-de-sac|dead-end|quiet-residential|moderate-traffic|busy-road|highway-adjacent|unknown>",
  "road_concerns": "<description of any road/traffic concerns that could affect resale value, empty string if none>",
  "lot_assessment": "<description of lot size, privacy, expansion potential based on visible yard/land>"
}

Scoring guidance for flip_photo_score:
- 80-100: Clear renovation opportunity with good bones, needs cosmetic/moderate work
- 60-79: Decent flip potential, some significant updates needed
- 40-59: Uncertain flip potential, may require major work
- 20-39: Significant concerns, likely major/gut renovation
- 0-19: Structural issues or uninhabitable condition

For estimated_cost_per_sqft, consider:
- Cosmetic (15-25): Paint, minor repairs, basic updates
- Moderate (25-50): Kitchen/bath refresh, new flooring, fixtures
- Major (50-80): Full kitchen/bath remodel, systems updates
- Gut (80-100): Complete interior demolition and rebuild

Road type identification (look at exterior photos for road visibility):
- cul-de-sac: Circular dead-end street visible
- dead-end: Street ends at property or nearby
- quiet-residential: Narrow side street, no visible traffic, residential feel
- moderate-traffic: Standard residential street, some width
- busy-road: Wide road, double yellow lines visible, commercial feel, visible traffic
- highway-adjacent: Near highway, major road visible
- unknown: Road not visible in photos

IMPORTANT: Road type significantly affects resale value. A $1M+ house on a busy double-yellow road is a red flag.
PROMPT;
    }

    /**
     * Call Claude API with vision content.
     *
     * @return array|WP_Error Response body or error.
     */
    private static function call_claude_api(string $api_key, array $content): array|WP_Error {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => json_encode([
                'model'      => self::CLAUDE_MODEL,
                'max_tokens' => 1024,
                'messages'   => [
                    [
                        'role'    => 'user',
                        'content' => $content,
                    ],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        if ($status !== 200) {
            $error_data = json_decode($body, true);
            $error_msg  = $error_data['error']['message'] ?? "HTTP {$status} error";
            return new WP_Error('claude_api_error', $error_msg);
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('json_parse_error', 'Failed to parse API response.');
        }

        return $data;
    }

    /**
     * Parse Claude response to extract JSON analysis.
     */
    private static function parse_response(array $response): ?array {
        // Get text content from response
        $text = '';
        if (isset($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if ($block['type'] === 'text') {
                    $text = $block['text'];
                    break;
                }
            }
        }

        if (empty($text)) {
            return null;
        }

        // Try to parse as JSON
        $analysis = json_decode($text, true);
        if ($analysis && is_array($analysis)) {
            return self::validate_analysis($analysis);
        }

        // Try to extract JSON from markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $matches)) {
            $analysis = json_decode($matches[1], true);
            if ($analysis && is_array($analysis)) {
                return self::validate_analysis($analysis);
            }
        }

        // Try to find JSON object in text
        if (preg_match('/\{[\s\S]*"overall_condition"[\s\S]*\}/', $text, $matches)) {
            $analysis = json_decode($matches[0], true);
            if ($analysis && is_array($analysis)) {
                return self::validate_analysis($analysis);
            }
        }

        return null;
    }

    /**
     * Validate and sanitize analysis data.
     */
    private static function validate_analysis(array $analysis): array {
        // Ensure required fields exist with defaults
        $defaults = [
            'overall_condition'       => 5,
            'renovation_level'        => 'moderate',
            'estimated_cost_per_sqft' => self::DEFAULT_REHAB_COST_PER_SQFT,
            'kitchen_condition'       => 5,
            'bathroom_condition'      => 5,
            'flooring_condition'      => 5,
            'exterior_condition'      => 5,
            'curb_appeal'             => 5,
            'structural_concerns'     => false,
            'structural_details'      => '',
            'water_damage_signs'      => false,
            'dated_features'          => [],
            'positive_features'       => [],
            'renovation_summary'      => '',
            'flip_photo_score'        => 50,
            'road_visible'            => false,
            'road_type'               => 'unknown',
            'road_concerns'           => '',
            'lot_assessment'          => '',
        ];

        $validated = array_merge($defaults, $analysis);

        // Clamp numeric values
        $validated['overall_condition']       = max(1, min(10, (int) $validated['overall_condition']));
        $validated['kitchen_condition']       = max(1, min(10, (int) $validated['kitchen_condition']));
        $validated['bathroom_condition']      = max(1, min(10, (int) $validated['bathroom_condition']));
        $validated['flooring_condition']      = max(1, min(10, (int) $validated['flooring_condition']));
        $validated['exterior_condition']      = max(1, min(10, (int) $validated['exterior_condition']));
        $validated['curb_appeal']             = max(1, min(10, (int) $validated['curb_appeal']));
        $validated['estimated_cost_per_sqft'] = max(15, min(100, (float) $validated['estimated_cost_per_sqft']));
        $validated['flip_photo_score']        = max(0, min(100, (float) $validated['flip_photo_score']));

        // Validate renovation_level
        $valid_levels = ['cosmetic', 'moderate', 'major', 'gut'];
        if (!in_array($validated['renovation_level'], $valid_levels, true)) {
            $validated['renovation_level'] = 'moderate';
        }

        // Validate road_type
        $valid_road_types = ['cul-de-sac', 'dead-end', 'quiet-residential', 'moderate-traffic', 'busy-road', 'highway-adjacent', 'unknown'];
        if (!in_array($validated['road_type'], $valid_road_types, true)) {
            $validated['road_type'] = 'unknown';
        }

        // Ensure arrays
        if (!is_array($validated['dated_features'])) {
            $validated['dated_features'] = [];
        }
        if (!is_array($validated['positive_features'])) {
            $validated['positive_features'] = [];
        }

        // Ensure booleans
        $validated['structural_concerns'] = (bool) $validated['structural_concerns'];
        $validated['water_damage_signs']  = (bool) $validated['water_damage_signs'];
        $validated['road_visible']        = (bool) $validated['road_visible'];

        // Ensure strings
        $validated['road_concerns']   = (string) $validated['road_concerns'];
        $validated['lot_assessment']  = (string) $validated['lot_assessment'];

        return $validated;
    }

    /**
     * Run photo analysis on top candidates from latest run.
     *
     * @param int   $top Number of top candidates to analyze.
     * @param float $min_score Minimum total_score to qualify.
     * @param callable|null $progress Progress callback.
     * @return array { analyzed: int, updated: int, errors: int }
     */
    public static function analyze_top_candidates(int $top = 50, float $min_score = 40, ?callable $progress = null): array {
        $log = $progress ?? function ($msg) {};

        $results = Flip_Database::get_results([
            'top'       => $top,
            'min_score' => $min_score,
            'sort'      => 'total_score',
            'order'     => 'DESC',
        ]);

        $stats = ['analyzed' => 0, 'updated' => 0, 'errors' => 0];

        if (empty($results)) {
            $log("No candidates found with score >= {$min_score}");
            return $stats;
        }

        $log("Analyzing " . count($results) . " properties with photo analysis...");

        foreach ($results as $i => $result) {
            $listing_id = (int) $result->listing_id;
            $log("  [{$i}/" . count($results) . "] Analyzing MLS# {$listing_id}...");

            $analysis = self::analyze($listing_id);
            $stats['analyzed']++;

            if (!$analysis['success']) {
                $log("    Error: " . $analysis['error']);
                $stats['errors']++;
                continue;
            }

            // Update the result in database
            $updated = self::update_result_with_photo_analysis($result, $analysis);
            if ($updated) {
                $stats['updated']++;
                $log("    Photo score: {$analysis['photo_score']}, Rehab: \${$analysis['rehab_cost_per_sqft']}/sqft ({$analysis['analysis']['renovation_level']})");
            }

            // Rate limit: small delay between API calls
            usleep(500000); // 0.5 second
        }

        $log("Photo analysis complete: {$stats['updated']} updated, {$stats['errors']} errors.");
        return $stats;
    }

    /**
     * Update a flip result with photo analysis data.
     */
    private static function update_result_with_photo_analysis(object $result, array $analysis): bool {
        global $wpdb;
        $table = Flip_Database::table_name();

        $sqft = (int) $result->building_area_total;
        $arv = (float) $result->estimated_arv;
        $list_price = (float) $result->list_price;
        $year_built = (int) $result->year_built;
        $city = $result->city ?? '';

        // Use shared financial calculation (same as main pipeline)
        $area_avg_dom = Flip_ARV_Calculator::get_area_avg_dom($city);
        $remarks = Flip_Market_Scorer::get_remarks((int) $result->listing_id);
        $fin = Flip_Analyzer::calculate_financials($arv, $list_price, $sqft, $year_built, $remarks, $area_avg_dom);

        // Photo score is a bonus, adds up to 10 points
        $photo_bonus = ($analysis['photo_score'] / 100) * 10;
        $base_score = (float) $result->total_score;
        $new_total = min(100, $base_score + $photo_bonus);

        // Get road type from photo analysis (or keep existing)
        $road_type = $analysis['analysis']['road_type'] ?? ($result->road_type ?? 'unknown');

        return $wpdb->update(
            $table,
            [
                'photo_score'          => round($analysis['photo_score'], 2),
                'photo_analysis_json'  => json_encode($analysis['analysis']),
                'estimated_rehab_cost' => $fin['rehab_cost'],
                'rehab_level'          => $analysis['analysis']['renovation_level'],
                'rehab_contingency'    => $fin['rehab_contingency'],
                'rehab_multiplier'     => $fin['rehab_multiplier'],
                'road_type'            => $road_type,
                'mao'                  => $fin['mao'],
                'estimated_profit'     => $fin['estimated_profit'],
                'estimated_roi'        => $fin['estimated_roi'],
                'financing_costs'      => $fin['financing_costs'],
                'holding_costs'        => $fin['holding_costs'],
                'hold_months'          => $fin['hold_months'],
                'cash_profit'          => $fin['cash_profit'],
                'cash_roi'             => $fin['cash_roi'],
                'cash_on_cash_roi'     => $fin['cash_on_cash_roi'],
                'total_score'          => round($new_total, 2),
            ],
            ['id' => $result->id],
            null,
            ['%d']
        ) !== false;
    }
}
