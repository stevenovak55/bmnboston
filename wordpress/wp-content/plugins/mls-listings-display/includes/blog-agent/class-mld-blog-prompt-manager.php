<?php
/**
 * Blog Prompt Manager
 *
 * Manages prompt templates with version control and A/B testing support.
 * Handles weighted random selection for testing new prompt variants.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Prompt_Manager
 *
 * Prompt template management with A/B testing.
 */
class MLD_Blog_Prompt_Manager {

    /**
     * Current selected versions for this session
     *
     * @var array
     */
    private $session_versions = array();

    /**
     * Cache of loaded prompts
     *
     * @var array
     */
    private $prompt_cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Get a prompt by key with A/B testing support
     *
     * @param string $key Prompt key
     * @param bool $use_ab_testing Whether to apply A/B testing
     * @return array|null Prompt data
     */
    public function get_prompt($key, $use_ab_testing = true) {
        // Check session cache first
        if (isset($this->session_versions[$key])) {
            return $this->session_versions[$key];
        }

        // Check prompt cache
        if (isset($this->prompt_cache[$key])) {
            $prompts = $this->prompt_cache[$key];
        } else {
            $prompts = $this->load_prompts_by_key($key);
            $this->prompt_cache[$key] = $prompts;
        }

        if (empty($prompts)) {
            return null;
        }

        // Select prompt based on A/B testing or highest weight
        if ($use_ab_testing && count($prompts) > 1) {
            $selected = $this->select_weighted_random($prompts);
        } else {
            // Select highest weight active prompt
            usort($prompts, function($a, $b) {
                return $b['weight'] <=> $a['weight'];
            });
            $selected = $prompts[0];
        }

        // Store in session cache
        $this->session_versions[$key] = $selected;

        // Increment usage count
        $this->increment_usage($selected['id']);

        return $selected;
    }

    /**
     * Load prompts from database by key
     *
     * @param string $key Prompt key
     * @return array Prompts
     */
    private function load_prompts_by_key($key) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE prompt_key = %s
             AND is_active = 1
             ORDER BY weight DESC",
            $key
        ), ARRAY_A);
    }

    /**
     * Select a prompt using weighted random selection
     *
     * @param array $prompts Array of prompts with weights
     * @return array Selected prompt
     */
    private function select_weighted_random($prompts) {
        $total_weight = array_sum(array_column($prompts, 'weight'));

        if ($total_weight <= 0) {
            return $prompts[0];
        }

        $random = mt_rand(1, $total_weight);
        $cumulative = 0;

        foreach ($prompts as $prompt) {
            $cumulative += $prompt['weight'];
            if ($random <= $cumulative) {
                return $prompt;
            }
        }

        return $prompts[0];
    }

    /**
     * Increment usage count for a prompt
     *
     * @param int $prompt_id Prompt ID
     */
    private function increment_usage($prompt_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET total_uses = total_uses + 1 WHERE id = %d",
            $prompt_id
        ));
    }

    /**
     * Get current version string for tracking
     *
     * @return string Version identifier
     */
    public function get_current_version() {
        $versions = array();

        foreach ($this->session_versions as $key => $prompt) {
            $versions[] = $key . ':' . ($prompt['version'] ?? '1.0.0');
        }

        return implode(',', $versions);
    }

    /**
     * Create a new prompt version
     *
     * @param string $key Prompt key
     * @param array $data Prompt data
     * @return int|false Prompt ID or false
     */
    public function create_prompt($key, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        // Generate version number
        $latest_version = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(version) FROM $table WHERE prompt_key = %s",
            $key
        ));

        if ($latest_version) {
            $parts = explode('.', $latest_version);
            $parts[2] = intval($parts[2] ?? 0) + 1;
            $new_version = implode('.', $parts);
        } else {
            $new_version = '1.0.0';
        }

        $result = $wpdb->insert($table, array(
            'prompt_key' => $key,
            'prompt_name' => $data['name'] ?? $key,
            'prompt_content' => $data['content'],
            'version' => $new_version,
            'weight' => $data['weight'] ?? 10, // New prompts start with low weight
            'is_active' => $data['is_active'] ?? 1,
        ));

        // Clear cache
        unset($this->prompt_cache[$key]);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update an existing prompt
     *
     * @param int $prompt_id Prompt ID
     * @param array $data Update data
     * @return bool Success
     */
    public function update_prompt($prompt_id, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $update_data = array();

        if (isset($data['name'])) {
            $update_data['prompt_name'] = $data['name'];
        }
        if (isset($data['content'])) {
            $update_data['prompt_content'] = $data['content'];
        }
        if (isset($data['weight'])) {
            $update_data['weight'] = max(1, intval($data['weight']));
        }
        if (isset($data['is_active'])) {
            $update_data['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update($table, $update_data, array('id' => $prompt_id));

        // Clear cache
        $this->prompt_cache = array();

        return $result !== false;
    }

    /**
     * Deactivate a prompt
     *
     * @param int $prompt_id Prompt ID
     * @return bool Success
     */
    public function deactivate_prompt($prompt_id) {
        return $this->update_prompt($prompt_id, array('is_active' => false));
    }

    /**
     * Get all prompts for a key
     *
     * @param string $key Prompt key
     * @param bool $include_inactive Include inactive prompts
     * @return array Prompts
     */
    public function get_all_prompts($key, $include_inactive = false) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $where = $include_inactive ? '' : 'AND is_active = 1';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE prompt_key = %s
             $where
             ORDER BY version DESC",
            $key
        ), ARRAY_A);
    }

    /**
     * Get all available prompt keys
     *
     * @return array Prompt keys with metadata
     */
    public function get_prompt_keys() {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        return $wpdb->get_results(
            "SELECT
                prompt_key,
                COUNT(*) as version_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                MAX(version) as latest_version,
                SUM(total_uses) as total_uses
             FROM $table
             GROUP BY prompt_key
             ORDER BY prompt_key",
            ARRAY_A
        );
    }

    /**
     * Get prompt performance comparison
     *
     * @param string $key Prompt key
     * @return array Performance data
     */
    public function get_prompt_performance($key) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                version,
                weight,
                is_active,
                total_uses,
                success_rate,
                avg_seo_score,
                avg_edit_distance,
                created_at
             FROM $table
             WHERE prompt_key = %s
             ORDER BY version DESC",
            $key
        ), ARRAY_A);
    }

    /**
     * Clone a prompt as a new version
     *
     * @param int $prompt_id Source prompt ID
     * @param string $new_content Modified content
     * @return int|false New prompt ID or false
     */
    public function clone_prompt($prompt_id, $new_content = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $prompt_id
        ), ARRAY_A);

        if (!$source) {
            return false;
        }

        return $this->create_prompt($source['prompt_key'], array(
            'name' => $source['prompt_name'],
            'content' => $new_content ?? $source['prompt_content'],
            'weight' => 10, // Start with low weight for testing
            'is_active' => true,
        ));
    }

    /**
     * Adjust weight based on performance
     *
     * @param int $prompt_id Prompt ID
     * @param int $adjustment Weight adjustment (+/-)
     * @return bool Success
     */
    public function adjust_weight($prompt_id, $adjustment) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $current_weight = $wpdb->get_var($wpdb->prepare(
            "SELECT weight FROM $table WHERE id = %d",
            $prompt_id
        ));

        if ($current_weight === null) {
            return false;
        }

        $new_weight = max(1, min(200, intval($current_weight) + $adjustment));

        return $this->update_prompt($prompt_id, array('weight' => $new_weight));
    }

    /**
     * Set the winning prompt for A/B test
     *
     * @param string $key Prompt key
     * @param int $winner_id Winning prompt ID
     * @return bool Success
     */
    public function set_ab_winner($key, $winner_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        // Set winner to 100% weight
        $wpdb->update(
            $table,
            array('weight' => 100),
            array('id' => $winner_id)
        );

        // Deactivate all other versions
        $wpdb->query($wpdb->prepare(
            "UPDATE $table
             SET is_active = 0
             WHERE prompt_key = %s AND id != %d",
            $key,
            $winner_id
        ));

        // Clear cache
        unset($this->prompt_cache[$key]);

        return true;
    }

    /**
     * Export prompts for backup
     *
     * @param string|null $key Optional specific key
     * @return array Export data
     */
    public function export_prompts($key = null) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';

        $where = '';
        if ($key) {
            $where = $wpdb->prepare("WHERE prompt_key = %s", $key);
        }

        $prompts = $wpdb->get_results(
            "SELECT prompt_key, prompt_name, prompt_content, version, weight, is_active
             FROM $table
             $where
             ORDER BY prompt_key, version DESC",
            ARRAY_A
        );

        return array(
            'exported_at' => current_time('mysql'),
            'prompts' => $prompts,
        );
    }

    /**
     * Import prompts from backup
     *
     * @param array $data Import data
     * @param bool $overwrite Overwrite existing
     * @return array Import results
     */
    public function import_prompts($data, $overwrite = false) {
        global $wpdb;

        $table = $wpdb->prefix . 'mld_blog_prompts';
        $results = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => array(),
        );

        if (empty($data['prompts'])) {
            return $results;
        }

        foreach ($data['prompts'] as $prompt) {
            // Check if exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE prompt_key = %s AND version = %s",
                $prompt['prompt_key'],
                $prompt['version']
            ));

            if ($existing && !$overwrite) {
                $results['skipped']++;
                continue;
            }

            if ($existing) {
                $wpdb->update(
                    $table,
                    array(
                        'prompt_name' => $prompt['prompt_name'],
                        'prompt_content' => $prompt['prompt_content'],
                        'weight' => $prompt['weight'],
                        'is_active' => $prompt['is_active'],
                    ),
                    array('id' => $existing)
                );
            } else {
                $wpdb->insert($table, array(
                    'prompt_key' => $prompt['prompt_key'],
                    'prompt_name' => $prompt['prompt_name'],
                    'prompt_content' => $prompt['prompt_content'],
                    'version' => $prompt['version'],
                    'weight' => $prompt['weight'],
                    'is_active' => $prompt['is_active'],
                ));
            }

            $results['imported']++;
        }

        // Clear cache
        $this->prompt_cache = array();

        return $results;
    }
}
