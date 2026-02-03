<?php
/**
 * Blog Feedback Learner
 *
 * Collects and analyzes feedback signals to improve article generation over time.
 * Tracks edit distance, user ratings, engagement metrics, and SEO performance.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Feedback_Learner
 *
 * Learning and evolution mechanism for the Blog Agent.
 */
class MLD_Blog_Feedback_Learner {

    /**
     * Feedback types
     */
    const TYPE_EDIT_DISTANCE = 'edit_distance';
    const TYPE_USER_RATING = 'user_rating';
    const TYPE_ENGAGEMENT = 'engagement';
    const TYPE_SEO_PERFORMANCE = 'seo_performance';

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Collect weekly engagement metrics (called by cron)
     */
    public function collect_weekly_metrics() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_articles';

        // Get published articles from the last 30 days
        $articles = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wp_post_id, published_at
             FROM $table
             WHERE status = 'published'
             AND published_at > DATE_SUB(%s, INTERVAL 30 DAY)",
            current_time('mysql')
        ), ARRAY_A);

        foreach ($articles as $article) {
            $this->collect_ga4_metrics($article);
            $this->collect_search_console_metrics($article);
        }

        // Update prompt performance statistics
        $this->update_prompt_statistics();
    }

    /**
     * Collect GA4 engagement metrics for an article
     *
     * @param array $article Article data
     */
    private function collect_ga4_metrics($article) {
        // Check if GA4 integration is available
        $ga4_property_id = get_option('mld_ga4_property_id', '');
        if (empty($ga4_property_id)) {
            return;
        }

        $post = get_post($article['wp_post_id']);
        if (!$post) {
            return;
        }

        $url = get_permalink($article['wp_post_id']);
        $path = wp_parse_url($url, PHP_URL_PATH);

        // Note: In production, this would use the GA4 Data API
        // For now, we'll check if Analytics data is available via existing integrations

        // Try to get cached analytics data if available
        $metrics = $this->get_cached_analytics($path);

        if (empty($metrics)) {
            return;
        }

        $this->record_feedback(
            $article['id'],
            self::TYPE_ENGAGEMENT,
            'page_views',
            $metrics['page_views'] ?? 0,
            array('period' => 'last_7_days')
        );

        $this->record_feedback(
            $article['id'],
            self::TYPE_ENGAGEMENT,
            'avg_time_on_page',
            $metrics['avg_time_on_page'] ?? 0,
            array('period' => 'last_7_days')
        );

        $this->record_feedback(
            $article['id'],
            self::TYPE_ENGAGEMENT,
            'bounce_rate',
            $metrics['bounce_rate'] ?? 0,
            array('period' => 'last_7_days')
        );
    }

    /**
     * Collect Search Console metrics for an article
     *
     * @param array $article Article data
     */
    private function collect_search_console_metrics($article) {
        // Check if Search Console integration is available
        // This is a placeholder for future implementation
        // Would use Google Search Console API to get impressions, clicks, position

        $url = get_permalink($article['wp_post_id']);

        // Placeholder metrics
        $metrics = $this->get_cached_search_console_data($url);

        if (empty($metrics)) {
            return;
        }

        $this->record_feedback(
            $article['id'],
            self::TYPE_SEO_PERFORMANCE,
            'impressions',
            $metrics['impressions'] ?? 0,
            array('period' => 'last_7_days')
        );

        $this->record_feedback(
            $article['id'],
            self::TYPE_SEO_PERFORMANCE,
            'clicks',
            $metrics['clicks'] ?? 0,
            array('period' => 'last_7_days')
        );

        $this->record_feedback(
            $article['id'],
            self::TYPE_SEO_PERFORMANCE,
            'avg_position',
            $metrics['position'] ?? 0,
            array('period' => 'last_7_days')
        );
    }

    /**
     * Get cached analytics data
     *
     * @param string $path URL path
     * @return array|null Metrics or null
     */
    private function get_cached_analytics($path) {
        // Check for cached analytics data
        // This could integrate with various analytics plugins/APIs
        return null;
    }

    /**
     * Get cached Search Console data
     *
     * @param string $url Full URL
     * @return array|null Metrics or null
     */
    private function get_cached_search_console_data($url) {
        // Placeholder for Search Console integration
        return null;
    }

    /**
     * Record a feedback entry
     *
     * @param int $article_id Article ID
     * @param string $type Feedback type
     * @param string $metric_name Metric name
     * @param float $metric_value Metric value
     * @param array $metadata Additional metadata
     * @return int|false Feedback ID or false
     */
    public function record_feedback($article_id, $type, $metric_name, $metric_value, $metadata = array()) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_feedback';

        $result = $wpdb->insert($table, array(
            'article_id' => $article_id,
            'feedback_type' => $type,
            'metric_name' => $metric_name,
            'metric_value' => $metric_value,
            'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
        ));

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update prompt performance statistics
     */
    private function update_prompt_statistics() {
        global $wpdb;

        $articles_table = $wpdb->prefix . 'mld_blog_articles';
        $prompts_table = $wpdb->prefix . 'mld_blog_prompts';
        $feedback_table = $wpdb->prefix . 'mld_blog_feedback';

        // Get performance by prompt version
        $stats = $wpdb->get_results(
            "SELECT
                a.prompt_version,
                COUNT(*) as total_uses,
                AVG(a.seo_score) as avg_seo_score,
                AVG(a.edit_distance) as avg_edit_distance,
                AVG(a.user_rating) as avg_rating,
                SUM(CASE WHEN a.status = 'published' THEN 1 ELSE 0 END) as published_count
             FROM $articles_table a
             WHERE a.prompt_version IS NOT NULL
             GROUP BY a.prompt_version",
            ARRAY_A
        );

        foreach ($stats as $stat) {
            if (empty($stat['prompt_version'])) {
                continue;
            }

            // Calculate success rate (published with rating >= 3 or low edit distance)
            $success_rate = 0;
            if ($stat['total_uses'] > 0) {
                $success_count = $stat['published_count'];
                if ($stat['avg_edit_distance'] !== null && $stat['avg_edit_distance'] < 30) {
                    $success_rate = ($success_count / $stat['total_uses']) * 100;
                }
            }

            // Update prompts table
            $wpdb->query($wpdb->prepare(
                "UPDATE $prompts_table
                 SET total_uses = %d,
                     avg_seo_score = %f,
                     avg_edit_distance = %f,
                     success_rate = %f
                 WHERE version = %s",
                $stat['total_uses'],
                $stat['avg_seo_score'] ?? 0,
                $stat['avg_edit_distance'] ?? 0,
                $success_rate,
                $stat['prompt_version']
            ));
        }
    }

    /**
     * Get feedback summary for an article
     *
     * @param int $article_id Article ID
     * @return array Feedback summary
     */
    public function get_article_feedback($article_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_feedback';

        $feedback = $wpdb->get_results($wpdb->prepare(
            "SELECT feedback_type, metric_name, metric_value, recorded_at
             FROM $table
             WHERE article_id = %d
             ORDER BY recorded_at DESC",
            $article_id
        ), ARRAY_A);

        $summary = array(
            'edit_distance' => null,
            'user_rating' => null,
            'engagement' => array(),
            'seo_performance' => array(),
        );

        foreach ($feedback as $entry) {
            switch ($entry['feedback_type']) {
                case self::TYPE_EDIT_DISTANCE:
                    if ($summary['edit_distance'] === null) {
                        $summary['edit_distance'] = floatval($entry['metric_value']);
                    }
                    break;

                case self::TYPE_USER_RATING:
                    if ($summary['user_rating'] === null) {
                        $summary['user_rating'] = intval($entry['metric_value']);
                    }
                    break;

                case self::TYPE_ENGAGEMENT:
                    $summary['engagement'][$entry['metric_name']] = floatval($entry['metric_value']);
                    break;

                case self::TYPE_SEO_PERFORMANCE:
                    $summary['seo_performance'][$entry['metric_name']] = floatval($entry['metric_value']);
                    break;
            }
        }

        return $summary;
    }

    /**
     * Get overall performance statistics
     *
     * @param array $filters Optional filters
     * @return array Performance statistics
     */
    public function get_performance_stats($filters = array()) {
        global $wpdb;

        $articles_table = $wpdb->prefix . 'mld_blog_articles';

        $where = "WHERE 1=1";

        if (!empty($filters['date_from'])) {
            $where .= $wpdb->prepare(" AND created_at >= %s", $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $where .= $wpdb->prepare(" AND created_at <= %s", $filters['date_to']);
        }

        if (!empty($filters['status'])) {
            $where .= $wpdb->prepare(" AND status = %s", $filters['status']);
        }

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_articles,
                AVG(seo_score) as avg_seo_score,
                AVG(geo_score) as avg_geo_score,
                AVG(word_count) as avg_word_count,
                AVG(edit_distance) as avg_edit_distance,
                AVG(user_rating) as avg_user_rating,
                SUM(generation_tokens) as total_tokens,
                SUM(generation_cost) as total_cost,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count
             FROM $articles_table
             $where",
            ARRAY_A
        );

        // Get distribution by prompt version
        $version_stats = $wpdb->get_results(
            "SELECT prompt_version, COUNT(*) as count, AVG(seo_score) as avg_seo
             FROM $articles_table
             $where
             GROUP BY prompt_version
             ORDER BY count DESC",
            ARRAY_A
        );

        $stats['by_prompt_version'] = $version_stats;

        return $stats;
    }

    /**
     * Get prompt recommendations based on performance
     *
     * @return array Recommendations
     */
    public function get_prompt_recommendations() {
        global $wpdb;

        $prompts_table = $wpdb->prefix . 'mld_blog_prompts';

        // Get prompts with enough data
        $prompts = $wpdb->get_results(
            "SELECT *
             FROM $prompts_table
             WHERE total_uses >= 5
             ORDER BY success_rate DESC, avg_seo_score DESC",
            ARRAY_A
        );

        $recommendations = array();

        foreach ($prompts as $prompt) {
            $recommendation = array(
                'prompt_key' => $prompt['prompt_key'],
                'version' => $prompt['version'],
                'total_uses' => $prompt['total_uses'],
                'success_rate' => $prompt['success_rate'],
                'avg_seo_score' => $prompt['avg_seo_score'],
                'status' => 'neutral',
                'message' => '',
            );

            // Evaluate performance
            if ($prompt['success_rate'] >= 80 && $prompt['avg_seo_score'] >= 75) {
                $recommendation['status'] = 'excellent';
                $recommendation['message'] = 'High performing prompt. Consider increasing weight.';
            } elseif ($prompt['success_rate'] >= 60 && $prompt['avg_seo_score'] >= 65) {
                $recommendation['status'] = 'good';
                $recommendation['message'] = 'Performing well. No changes needed.';
            } elseif ($prompt['success_rate'] < 40 || $prompt['avg_seo_score'] < 50) {
                $recommendation['status'] = 'poor';
                $recommendation['message'] = 'Underperforming. Consider revision or deactivation.';
            }

            // Check edit distance (high = users making many changes)
            if ($prompt['avg_edit_distance'] > 50) {
                $recommendation['status'] = 'needs_improvement';
                $recommendation['message'] = 'High edit distance indicates content not meeting expectations.';
            }

            $recommendations[] = $recommendation;
        }

        return $recommendations;
    }

    /**
     * Auto-promote or demote prompts based on performance
     *
     * @param float $promotion_threshold Performance threshold for promotion (default 10%)
     * @return array Actions taken
     */
    public function auto_adjust_prompts($promotion_threshold = 10.0) {
        global $wpdb;

        $prompts_table = $wpdb->prefix . 'mld_blog_prompts';
        $actions = array();

        // Get active prompts grouped by key
        $prompt_groups = $wpdb->get_results(
            "SELECT prompt_key,
                    AVG(success_rate) as avg_success,
                    MIN(success_rate) as min_success,
                    MAX(success_rate) as max_success
             FROM $prompts_table
             WHERE is_active = 1
             AND total_uses >= 10
             GROUP BY prompt_key
             HAVING COUNT(*) > 1",
            ARRAY_A
        );

        foreach ($prompt_groups as $group) {
            // Check if there's a significant performance difference
            $diff = $group['max_success'] - $group['min_success'];

            if ($diff < $promotion_threshold) {
                continue;
            }

            // Get the best performing prompt for this key
            $best = $wpdb->get_row($wpdb->prepare(
                "SELECT id, version, weight
                 FROM $prompts_table
                 WHERE prompt_key = %s
                 AND is_active = 1
                 AND success_rate = %f",
                $group['prompt_key'],
                $group['max_success']
            ), ARRAY_A);

            if (!$best) {
                continue;
            }

            // Increase weight of best performer
            $new_weight = min(200, $best['weight'] + 20);
            $wpdb->update(
                $prompts_table,
                array('weight' => $new_weight),
                array('id' => $best['id'])
            );

            $actions[] = array(
                'action' => 'weight_increased',
                'prompt_key' => $group['prompt_key'],
                'version' => $best['version'],
                'old_weight' => $best['weight'],
                'new_weight' => $new_weight,
                'reason' => "Outperforming by {$diff}%",
            );

            // Get the worst performing prompt
            $worst = $wpdb->get_row($wpdb->prepare(
                "SELECT id, version, weight
                 FROM $prompts_table
                 WHERE prompt_key = %s
                 AND is_active = 1
                 AND success_rate = %f",
                $group['prompt_key'],
                $group['min_success']
            ), ARRAY_A);

            if ($worst && $worst['id'] !== $best['id']) {
                // Decrease weight of worst performer
                $new_weight = max(10, $worst['weight'] - 20);
                $wpdb->update(
                    $prompts_table,
                    array('weight' => $new_weight),
                    array('id' => $worst['id'])
                );

                $actions[] = array(
                    'action' => 'weight_decreased',
                    'prompt_key' => $group['prompt_key'],
                    'version' => $worst['version'],
                    'old_weight' => $worst['weight'],
                    'new_weight' => $new_weight,
                    'reason' => "Underperforming by {$diff}%",
                );
            }
        }

        return $actions;
    }

    /**
     * Generate insights report
     *
     * @param string $period Report period (week, month, quarter)
     * @return array Insights
     */
    public function generate_insights_report($period = 'month') {
        $date_from = null;
        switch ($period) {
            case 'week':
                $date_from = date('Y-m-d', strtotime('-7 days'));
                break;
            case 'month':
                $date_from = date('Y-m-d', strtotime('-30 days'));
                break;
            case 'quarter':
                $date_from = date('Y-m-d', strtotime('-90 days'));
                break;
        }

        $stats = $this->get_performance_stats(array('date_from' => $date_from));
        $recommendations = $this->get_prompt_recommendations();

        $insights = array(
            'period' => $period,
            'date_from' => $date_from,
            'date_to' => current_time('Y-m-d'),
            'summary' => array(
                'total_articles' => $stats['total_articles'],
                'published_rate' => $stats['total_articles'] > 0
                    ? round(($stats['published_count'] / $stats['total_articles']) * 100, 1)
                    : 0,
                'avg_seo_score' => round($stats['avg_seo_score'] ?? 0, 1),
                'avg_geo_score' => round($stats['avg_geo_score'] ?? 0, 1),
                'avg_edit_distance' => round($stats['avg_edit_distance'] ?? 0, 1),
                'total_cost' => round($stats['total_cost'] ?? 0, 4),
            ),
            'trends' => $this->calculate_trends($date_from),
            'top_performing_prompts' => array_filter($recommendations, function($r) {
                return $r['status'] === 'excellent' || $r['status'] === 'good';
            }),
            'prompts_needing_attention' => array_filter($recommendations, function($r) {
                return $r['status'] === 'poor' || $r['status'] === 'needs_improvement';
            }),
            'recommendations' => array(),
        );

        // Generate actionable recommendations
        if ($insights['summary']['avg_seo_score'] < 70) {
            $insights['recommendations'][] = 'SEO scores are below target. Review SEO optimization prompts.';
        }

        if ($insights['summary']['avg_edit_distance'] > 40) {
            $insights['recommendations'][] = 'High edit distance suggests content quality issues. Review content generation prompts.';
        }

        if (!empty($insights['prompts_needing_attention'])) {
            $insights['recommendations'][] = 'Some prompts are underperforming. Consider A/B testing new versions.';
        }

        return $insights;
    }

    /**
     * Calculate performance trends
     *
     * @param string $date_from Start date
     * @return array Trends
     */
    private function calculate_trends($date_from) {
        global $wpdb;

        $articles_table = $wpdb->prefix . 'mld_blog_articles';

        // Get weekly aggregates
        $trends = $wpdb->get_results($wpdb->prepare(
            "SELECT
                YEARWEEK(created_at) as week,
                COUNT(*) as articles,
                AVG(seo_score) as avg_seo,
                AVG(edit_distance) as avg_edit
             FROM $articles_table
             WHERE created_at >= %s
             GROUP BY YEARWEEK(created_at)
             ORDER BY week ASC",
            $date_from
        ), ARRAY_A);

        return $trends;
    }
}
