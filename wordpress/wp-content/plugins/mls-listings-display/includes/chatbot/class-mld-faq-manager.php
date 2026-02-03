<?php
/**
 * FAQ Manager
 *
 * Manages FAQ library and provides fallback responses
 * when AI providers are unavailable
 *
 * @package MLS_Listings_Display
 * @subpackage Chatbot
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_FAQ_Manager {

    /**
     * Minimum keyword match score to return FAQ
     *
     * @var float
     */
    const MIN_MATCH_SCORE = 0.3;

    /**
     * Constructor
     */
    public function __construct() {
        // Register AJAX handlers for FAQ management
        add_action('wp_ajax_mld_get_faqs', array($this, 'ajax_get_faqs'));
        add_action('wp_ajax_mld_save_faq', array($this, 'ajax_save_faq'));
        add_action('wp_ajax_mld_delete_faq', array($this, 'ajax_delete_faq'));
        add_action('wp_ajax_mld_search_faqs', array($this, 'ajax_search_faqs'));
    }

    /**
     * Find best matching FAQ for user question
     *
     * @param string $question User question
     * @return array|null FAQ data or null if no match
     */
    public function find_matching_faq($question) {
        global $wpdb;

        // Get all active FAQs
        $faqs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mld_chat_faq_library
             WHERE is_active = 1
             ORDER BY priority DESC",
            ARRAY_A
        );

        if (empty($faqs)) {
            return null;
        }

        // Normalize question
        $question_normalized = $this->normalize_text($question);
        $question_words = $this->extract_keywords($question_normalized);

        $best_match = null;
        $best_score = 0;

        foreach ($faqs as $faq) {
            // Check question match
            $faq_question_normalized = $this->normalize_text($faq['question']);
            $score = $this->calculate_match_score($question_normalized, $faq_question_normalized, $question_words);

            // Check keywords if provided
            if (!empty($faq['keywords'])) {
                $keywords = array_map('trim', explode(',', $faq['keywords']));
                foreach ($keywords as $keyword) {
                    if (stripos($question_normalized, strtolower($keyword)) !== false) {
                        $score += 0.3; // Keyword match bonus
                    }
                }
            }

            // Check category match (optional boost)
            if (!empty($faq['category'])) {
                if (stripos($question_normalized, strtolower($faq['category'])) !== false) {
                    $score += 0.1; // Category match bonus
                }
            }

            // Track best match
            if ($score > $best_score && $score >= self::MIN_MATCH_SCORE) {
                $best_score = $score;
                $best_match = $faq;
            }
        }

        if ($best_match) {
            // Track usage
            $this->track_faq_usage($best_match['id']);

            return array(
                'faq_id' => $best_match['id'],
                'question' => $best_match['question'],
                'answer' => $best_match['answer'],
                'category' => $best_match['category'],
                'match_score' => $best_score,
            );
        }

        return null;
    }

    /**
     * Calculate match score between two texts
     *
     * @param string $text1 First text
     * @param string $text2 Second text
     * @param array $keywords Keywords from text1
     * @return float Match score (0-1)
     */
    private function calculate_match_score($text1, $text2, $keywords) {
        // Exact match gets highest score
        if ($text1 === $text2) {
            return 1.0;
        }

        // Similarity using Levenshtein distance
        $max_length = max(strlen($text1), strlen($text2));
        if ($max_length > 0) {
            $levenshtein_score = 1 - (levenshtein(substr($text1, 0, 255), substr($text2, 0, 255)) / $max_length);
        } else {
            $levenshtein_score = 0;
        }

        // Keyword overlap score
        $text2_words = $this->extract_keywords($text2);
        $common_keywords = array_intersect($keywords, $text2_words);
        $keyword_score = count($keywords) > 0 ? (count($common_keywords) / count($keywords)) : 0;

        // Weighted combination
        return ($levenshtein_score * 0.4) + ($keyword_score * 0.6);
    }

    /**
     * Normalize text for matching
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private function normalize_text($text) {
        // Convert to lowercase
        $text = strtolower($text);

        // Remove punctuation
        $text = preg_replace('/[^\w\s]/', '', $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Extract keywords from text
     *
     * @param string $text Normalized text
     * @return array Keywords
     */
    private function extract_keywords($text) {
        // Common stop words to filter out
        $stop_words = array(
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
            'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the',
            'to', 'was', 'will', 'with', 'what', 'when', 'where', 'who', 'how',
            'can', 'could', 'do', 'does', 'i', 'me', 'my', 'you', 'your',
        );

        $words = explode(' ', $text);
        $keywords = array();

        foreach ($words as $word) {
            // Skip stop words and very short words
            if (strlen($word) > 2 && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Track FAQ usage
     *
     * @param int $faq_id FAQ ID
     */
    private function track_faq_usage($faq_id) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}mld_chat_faq_library
             SET usage_count = usage_count + 1,
                 last_used_at = %s
             WHERE id = %d",
            current_time('mysql'),
            $faq_id
        ));
    }

    /**
     * Get all FAQs
     *
     * @param array $args Query arguments
     * @return array FAQs
     */
    public function get_faqs($args = array()) {
        global $wpdb;

        $defaults = array(
            'category' => null,
            'is_active' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'priority',
            'order' => 'ASC',
        );

        $args = wp_parse_args($args, $defaults);

        $where = array();
        $where_values = array();

        if (!is_null($args['category'])) {
            $where[] = 'category = %s';
            $where_values[] = $args['category'];
        }

        if (!is_null($args['is_active'])) {
            $where[] = 'is_active = %d';
            $where_values[] = $args['is_active'];
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM {$wpdb->prefix}mld_chat_faq_library
                {$where_sql}
                ORDER BY {$args['orderby']} {$args['order']}
                LIMIT %d OFFSET %d";

        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, $where_values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get FAQ by ID
     *
     * @param int $faq_id FAQ ID
     * @return array|null FAQ data
     */
    public function get_faq($faq_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_faq_library WHERE id = %d",
            $faq_id
        ), ARRAY_A);
    }

    /**
     * Create or update FAQ
     *
     * @param array $data FAQ data
     * @return int|false FAQ ID or false on failure
     */
    public function save_faq($data) {
        global $wpdb;

        $faq_id = isset($data['id']) ? (int) $data['id'] : 0;

        $faq_data = array(
            'question' => sanitize_textarea_field($data['question']),
            'answer' => wp_kses_post($data['answer']),
            'category' => sanitize_text_field($data['category']),
            'keywords' => sanitize_text_field($data['keywords']),
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'priority' => isset($data['priority']) ? (int) $data['priority'] : 0,
        );

        if ($faq_id > 0) {
            // Update existing FAQ
            $result = $wpdb->update(
                $wpdb->prefix . 'mld_chat_faq_library',
                $faq_data,
                array('id' => $faq_id),
                array('%s', '%s', '%s', '%s', '%d', '%d'),
                array('%d')
            );

            return $result !== false ? $faq_id : false;
        } else {
            // Insert new FAQ
            $faq_data['created_at'] = current_time('mysql');

            $result = $wpdb->insert(
                $wpdb->prefix . 'mld_chat_faq_library',
                $faq_data,
                array('%s', '%s', '%s', '%s', '%d', '%d', '%s')
            );

            return $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Delete FAQ
     *
     * @param int $faq_id FAQ ID
     * @return bool Success status
     */
    public function delete_faq($faq_id) {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'mld_chat_faq_library',
            array('id' => $faq_id),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Get FAQ categories
     *
     * @return array Categories with counts
     */
    public function get_categories() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT category, COUNT(*) as count
             FROM {$wpdb->prefix}mld_chat_faq_library
             WHERE category IS NOT NULL AND category != ''
             GROUP BY category
             ORDER BY category ASC",
            ARRAY_A
        );
    }

    /**
     * Get FAQ statistics
     *
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;

        return array(
            'total_faqs' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_faq_library"
            ),
            'active_faqs' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}mld_chat_faq_library WHERE is_active = 1"
            ),
            'total_uses' => $wpdb->get_var(
                "SELECT SUM(times_used) FROM {$wpdb->prefix}mld_chat_faq_library"
            ),
            'most_used' => $wpdb->get_results(
                "SELECT question, times_used, last_used_at
                 FROM {$wpdb->prefix}mld_chat_faq_library
                 WHERE times_used > 0
                 ORDER BY times_used DESC
                 LIMIT 5",
                ARRAY_A
            ),
        );
    }

    /**
     * AJAX: Get FAQs
     */
    public function ajax_get_faqs() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $category = isset($_GET['category']) ? sanitize_text_field($_GET['category']) : null;
        $is_active = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;

        $faqs = $this->get_faqs(array(
            'category' => $category,
            'is_active' => $is_active,
        ));

        wp_send_json_success(array('faqs' => $faqs));
    }

    /**
     * AJAX: Save FAQ
     */
    public function ajax_save_faq() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $faq_data = isset($_POST['faq']) ? $_POST['faq'] : array();

        if (empty($faq_data['question']) || empty($faq_data['answer'])) {
            wp_send_json_error(array('message' => 'Question and answer are required'));
        }

        $faq_id = $this->save_faq($faq_data);

        if ($faq_id) {
            wp_send_json_success(array(
                'message' => 'FAQ saved successfully',
                'faq_id' => $faq_id,
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save FAQ'));
        }
    }

    /**
     * AJAX: Delete FAQ
     */
    public function ajax_delete_faq() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $faq_id = isset($_POST['faq_id']) ? (int) $_POST['faq_id'] : 0;

        if (!$faq_id) {
            wp_send_json_error(array('message' => 'FAQ ID required'));
        }

        $success = $this->delete_faq($faq_id);

        if ($success) {
            wp_send_json_success(array('message' => 'FAQ deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete FAQ'));
        }
    }

    /**
     * AJAX: Search FAQs
     */
    public function ajax_search_faqs() {
        check_ajax_referer('mld_chatbot_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';

        if (empty($query)) {
            wp_send_json_error(array('message' => 'Search query required'));
        }

        // Search in questions and answers
        global $wpdb;
        $search_results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mld_chat_faq_library
             WHERE question LIKE %s OR answer LIKE %s OR keywords LIKE %s
             ORDER BY priority ASC
             LIMIT 20",
            '%' . $wpdb->esc_like($query) . '%',
            '%' . $wpdb->esc_like($query) . '%',
            '%' . $wpdb->esc_like($query) . '%'
        ), ARRAY_A);

        wp_send_json_success(array('results' => $search_results));
    }
}

// Initialize FAQ manager
global $mld_faq_manager;
$mld_faq_manager = new MLD_FAQ_Manager();

/**
 * Get global FAQ manager instance
 *
 * @return MLD_FAQ_Manager
 */
function mld_get_faq_manager() {
    global $mld_faq_manager;
    return $mld_faq_manager;
}
