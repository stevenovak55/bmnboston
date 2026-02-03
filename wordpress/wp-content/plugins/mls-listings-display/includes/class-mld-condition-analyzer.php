<?php
/**
 * MLD Condition Analyzer
 *
 * Uses Claude Vision API to analyze property photos and assess condition.
 * Provides AI-powered condition ratings for CMA comparables.
 *
 * @package MLS_Listings_Display
 * @since 6.75.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Condition_Analyzer {

    /**
     * Claude API endpoint for messages
     */
    const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * API version
     */
    const API_VERSION = '2023-06-01';

    /**
     * Default model for condition analysis (Haiku for speed and cost)
     */
    const DEFAULT_MODEL = 'claude-3-5-haiku-20241022';

    /**
     * Cache TTL in seconds (7 days)
     */
    const CACHE_TTL = 604800;

    /**
     * Maximum photos to analyze per request
     */
    const MAX_PHOTOS = 5;

    /**
     * Condition mapping from AI response to CMA condition values
     */
    const CONDITION_MAP = array(
        'new_construction' => array(
            'label' => 'New Construction',
            'adjustment_percent' => 20,
        ),
        'fully_renovated' => array(
            'label' => 'Fully Renovated',
            'adjustment_percent' => 12,
        ),
        'some_updates' => array(
            'label' => 'Some Updates',
            'adjustment_percent' => 0,
        ),
        'needs_updating' => array(
            'label' => 'Needs Updating',
            'adjustment_percent' => -12,
        ),
        'distressed' => array(
            'label' => 'Distressed',
            'adjustment_percent' => -30,
        ),
    );

    /**
     * Get API key
     *
     * @return string|null API key or null if not configured
     */
    private static function get_api_key() {
        // Try wp-config.php constant first
        if (defined('ANTHROPIC_API_KEY') && !empty(ANTHROPIC_API_KEY)) {
            return ANTHROPIC_API_KEY;
        }

        // Fall back to MLD chat settings table (same as chatbot)
        global $wpdb;
        $table_name = $wpdb->prefix . 'mld_chat_settings';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return null;
        }

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$table_name} WHERE setting_key = %s",
            'claude_api_key'
        ));

        if ($result) {
            // Check if encrypted (encrypted keys are longer and look like base64)
            if (strlen($result) > 100 && preg_match('/^[A-Za-z0-9+\/=]+$/', $result)) {
                // Decrypt using WordPress salts (same method as abstract-mld-ai-provider.php)
                $key = wp_salt('auth');
                $decrypted = openssl_decrypt($result, 'AES-256-CBC', $key, 0, substr($key, 0, 16));
                if ($decrypted) {
                    return $decrypted;
                }
            }
            return $result;
        }

        return null;
    }

    /**
     * Analyze property condition from photos
     *
     * @param string $listing_id The listing ID for caching
     * @param array $photo_urls Array of photo URLs to analyze
     * @param bool $force_refresh Whether to bypass cache
     * @return array Analysis result with condition, confidence, and reasoning
     */
    public static function analyze($listing_id, $photo_urls, $force_refresh = false) {
        // Validate inputs
        if (empty($listing_id)) {
            return self::error_response('Missing listing_id');
        }

        if (empty($photo_urls) || !is_array($photo_urls)) {
            return self::error_response('Missing or invalid photo_urls');
        }

        // Check cache first (unless force refresh)
        if (!$force_refresh) {
            $cached = self::get_cached_analysis($listing_id);
            if ($cached !== false) {
                $cached['cached'] = true;
                return array('success' => true, 'data' => $cached);
            }
        }

        // Validate API key
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return self::error_response('Claude API key not configured');
        }

        // Limit photos
        $photo_urls = array_slice($photo_urls, 0, self::MAX_PHOTOS);

        // Fetch and encode photos
        $image_content = self::prepare_images($photo_urls);
        if (empty($image_content)) {
            return self::error_response('Failed to fetch property photos');
        }

        // Build the prompt
        $prompt = self::build_analysis_prompt();

        // Call Claude Vision API
        $result = self::call_claude_vision($api_key, $image_content, $prompt);

        if (!$result['success']) {
            return $result;
        }

        // Parse the response
        $analysis = self::parse_analysis_response($result['text']);

        if (!$analysis['success']) {
            return $analysis;
        }

        // Cache the result
        self::cache_analysis($listing_id, $analysis['data']);

        return $analysis;
    }

    /**
     * Build the analysis prompt for Claude
     *
     * @return string The prompt
     */
    private static function build_analysis_prompt() {
        return <<<PROMPT
You are a real estate appraiser assessing property condition from photos. Be DECISIVE and SPECIFIC.

Rate the property condition as ONE of these categories based on these SPECIFIC visual criteria:

**new_construction** (rare - only for brand new homes):
- Model home appearance, no signs of occupancy
- Builder-grade finishes still pristine
- Construction materials visible or brand new landscaping

**fully_renovated** (recently updated throughout):
Kitchen MUST have: Quartz/granite/marble countertops, shaker or modern flat-panel cabinets, stainless steel appliances, subway tile or modern backsplash, undermount sink
Bathrooms MUST have: Modern vanity with stone top, updated fixtures (brushed nickel/matte black/chrome), glass shower doors or modern tile surround
Flooring: New hardwood, luxury vinyl plank, or quality tile throughout
Overall: Recessed lighting, modern paint colors (grays, whites), updated trim and doors

**some_updates** (partial updates, mix of old and new):
Kitchen has: Some updates but NOT all (e.g., new appliances but original cabinets, OR granite counters but dated oak cabinets)
Bathrooms: Functional but not fully modernized
Mix of old and new elements throughout
May have one fully updated room but others are original

**needs_updating** (dated but functional - BE CRITICAL HERE):
Kitchen has ANY of: Oak or honey-colored cabinets, laminate or tile countertops, white/almond appliances, no backsplash or dated tile backsplash, raised panel cabinet doors from 1990s-2000s
Bathrooms have ANY of: Builder-grade oak vanity, cultured marble tops, brass fixtures, dated tile, fiberglass tub surround
Flooring: Dated carpet, worn hardwood, linoleum, ceramic tile from 1990s-2000s
Light fixtures: Brass, boob lights, fluorescent, dated ceiling fans

**distressed** (major issues visible):
Visible damage, water stains, peeling paint, missing fixtures
Cabinets falling apart, broken tiles, severe wear
Evidence of deferred maintenance throughout

IMPORTANT DECISION RULES:
1. If the kitchen has oak/honey cabinets OR laminate counters OR dated appliances = "needs_updating" (NOT "some_updates")
2. If BOTH kitchen AND bathrooms have been FULLY modernized with the criteria above = "fully_renovated"
3. If only ONE room is updated but others are dated = "some_updates"
4. When in doubt between "some_updates" and "needs_updating", look at the KITCHEN - if it has ANY dated elements (oak cabinets, laminate, old appliances), choose "needs_updating"

Respond ONLY with this exact JSON format:
{
  "condition": "one_of_the_five_categories",
  "confidence": 0-100,
  "reasoning": "One sentence citing SPECIFIC features you observed (e.g., 'Oak cabinets with laminate counters and brass fixtures indicate needs_updating')",
  "features": [
    {"feature": "Kitchen", "assessment": "Updated|Original|Dated|Damaged|Not Visible", "details": "specific materials observed"},
    {"feature": "Bathrooms", "assessment": "Updated|Original|Dated|Damaged|Not Visible", "details": "specific materials observed"},
    {"feature": "Flooring", "assessment": "Good|Fair|Poor|Not Visible", "details": "type and condition"},
    {"feature": "Overall Finishes", "assessment": "Modern|Average|Dated|Poor", "details": "fixtures, paint, trim"}
  ]
}
PROMPT;
    }

    /**
     * Prepare images for the API request
     *
     * @param array $photo_urls Array of photo URLs
     * @return array Array of image content blocks for Claude API
     */
    private static function prepare_images($photo_urls) {
        $image_content = array();

        foreach ($photo_urls as $url) {
            // Fetch the image
            $response = wp_remote_get($url, array(
                'timeout' => 10,
                'sslverify' => false,
            ));

            if (is_wp_error($response)) {
                error_log('[MLD Condition Analyzer] Failed to fetch image: ' . $url . ' - ' . $response->get_error_message());
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');

            if (empty($body)) {
                continue;
            }

            // Determine media type
            $media_type = 'image/jpeg'; // Default
            if (strpos($content_type, 'png') !== false) {
                $media_type = 'image/png';
            } elseif (strpos($content_type, 'gif') !== false) {
                $media_type = 'image/gif';
            } elseif (strpos($content_type, 'webp') !== false) {
                $media_type = 'image/webp';
            }

            // Base64 encode the image
            $base64_data = base64_encode($body);

            $image_content[] = array(
                'type' => 'image',
                'source' => array(
                    'type' => 'base64',
                    'media_type' => $media_type,
                    'data' => $base64_data,
                ),
            );
        }

        return $image_content;
    }

    /**
     * Call Claude Vision API
     *
     * @param string $api_key The API key
     * @param array $image_content Array of image content blocks
     * @param string $prompt The analysis prompt
     * @return array Result with success, text, or error
     */
    private static function call_claude_vision($api_key, $image_content, $prompt) {
        // Build message content with images first, then text
        $content = $image_content;
        $content[] = array(
            'type' => 'text',
            'text' => $prompt,
        );

        $payload = array(
            'model' => self::DEFAULT_MODEL,
            'max_tokens' => 1024,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $content,
                ),
            ),
        );

        $response = wp_remote_post(self::API_ENDPOINT, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => self::API_VERSION,
            ),
            'body' => json_encode($payload),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            error_log('[MLD Condition Analyzer] API request failed: ' . $response->get_error_message());
            return self::error_response('Failed to connect to Claude API: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown API error';
            error_log('[MLD Condition Analyzer] API error ' . $status_code . ': ' . $error_message);
            return self::error_response('Claude API error: ' . $error_message);
        }

        // Extract text from response
        $text = '';
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if ($block['type'] === 'text') {
                    $text .= $block['text'];
                }
            }
        }

        if (empty($text)) {
            return self::error_response('Empty response from Claude API');
        }

        return array(
            'success' => true,
            'text' => $text,
            'usage' => isset($body['usage']) ? $body['usage'] : null,
        );
    }

    /**
     * Parse the analysis response from Claude
     *
     * @param string $text The response text
     * @return array Parsed analysis result
     */
    private static function parse_analysis_response($text) {
        // Try to extract JSON from the response
        $json_start = strpos($text, '{');
        $json_end = strrpos($text, '}');

        if ($json_start === false || $json_end === false) {
            error_log('[MLD Condition Analyzer] No JSON found in response: ' . substr($text, 0, 200));
            return self::error_response('Invalid response format from Claude');
        }

        $json_text = substr($text, $json_start, $json_end - $json_start + 1);
        $data = json_decode($json_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[MLD Condition Analyzer] JSON parse error: ' . json_last_error_msg());
            return self::error_response('Failed to parse Claude response');
        }

        // Validate required fields
        if (empty($data['condition'])) {
            return self::error_response('Missing condition in response');
        }

        // Validate condition is one of the allowed values
        if (!isset(self::CONDITION_MAP[$data['condition']])) {
            // Try to map to closest condition
            $data['condition'] = 'some_updates'; // Default fallback
        }

        // Get condition label
        $condition_info = self::CONDITION_MAP[$data['condition']];

        // Build response
        $result = array(
            'condition' => $data['condition'],
            'condition_label' => $condition_info['label'],
            'confidence' => isset($data['confidence']) ? intval($data['confidence']) : 70,
            'reasoning' => isset($data['reasoning']) ? $data['reasoning'] : 'Assessment based on visible property features.',
            'features_detected' => array(),
            'cached' => false,
        );

        // Parse features
        if (isset($data['features']) && is_array($data['features'])) {
            foreach ($data['features'] as $feature) {
                if (isset($feature['feature']) && isset($feature['assessment'])) {
                    $result['features_detected'][] = array(
                        'feature' => $feature['feature'],
                        'assessment' => $feature['assessment'],
                        'details' => isset($feature['details']) ? $feature['details'] : null,
                    );
                }
            }
        }

        return array(
            'success' => true,
            'data' => $result,
        );
    }

    /**
     * Get cached analysis for a listing
     *
     * @param string $listing_id The listing ID
     * @return array|false Cached data or false if not found
     */
    private static function get_cached_analysis($listing_id) {
        $cache_key = 'mld_condition_analysis_' . sanitize_key($listing_id);
        return get_transient($cache_key);
    }

    /**
     * Cache analysis result
     *
     * @param string $listing_id The listing ID
     * @param array $data The analysis data
     */
    private static function cache_analysis($listing_id, $data) {
        $cache_key = 'mld_condition_analysis_' . sanitize_key($listing_id);
        set_transient($cache_key, $data, self::CACHE_TTL);
    }

    /**
     * Clear cached analysis for a listing
     *
     * @param string $listing_id The listing ID
     */
    public static function clear_cache($listing_id) {
        $cache_key = 'mld_condition_analysis_' . sanitize_key($listing_id);
        delete_transient($cache_key);
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @return array Error response
     */
    private static function error_response($message) {
        return array(
            'success' => false,
            'error' => $message,
        );
    }

    /**
     * Check if the analyzer is available (API key configured)
     *
     * @return bool Whether the analyzer can be used
     */
    public static function is_available() {
        return !empty(self::get_api_key());
    }
}
