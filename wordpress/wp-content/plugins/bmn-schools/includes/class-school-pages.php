<?php
/**
 * School Pages Handler
 *
 * Manages rewrite rules and template routing for school district and school pages.
 *
 * URL Structure:
 * - /schools/                           → District browse page
 * - /schools/{district-slug}/           → District detail page
 * - /schools/{district-slug}/{school}/  → School detail page (Phase 2)
 *
 * @package BMN_Schools
 * @since 0.7.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * School Pages class.
 *
 * @since 0.7.0
 */
class BMN_School_Pages {

    /**
     * The single instance of the class.
     *
     * @var BMN_School_Pages|null
     */
    private static $instance = null;

    /**
     * Theme directory path.
     *
     * @var string
     */
    private $theme_path;

    /**
     * Main BMN_School_Pages Instance.
     *
     * @since 0.7.0
     * @return BMN_School_Pages
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since 0.7.0
     */
    private function __construct() {
        $this->theme_path = get_stylesheet_directory();

        // Register hooks
        add_action('init', [$this, 'add_rewrite_rules'], 1);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_filter('template_include', [$this, 'template_include'], 99);
        add_filter('request', [$this, 'handle_school_request'], 1);
        add_action('pre_get_posts', [$this, 'pre_get_posts'], 1);
    }

    /**
     * Add rewrite rules for school pages.
     *
     * @since 0.7.0
     */
    public function add_rewrite_rules() {
        // District browse page: /schools/
        add_rewrite_rule(
            '^schools/?$',
            'index.php?bmn_schools_browse=1',
            'top'
        );

        // Methodology page: /schools/methodology/
        add_rewrite_rule(
            '^schools/methodology/?$',
            'index.php?bmn_methodology_page=1',
            'top'
        );

        // District detail page: /schools/{district-slug}/
        add_rewrite_rule(
            '^schools/([a-z0-9-]+)/?$',
            'index.php?bmn_district_page=1&bmn_district_slug=$matches[1]',
            'top'
        );

        // School detail page (Phase 2): /schools/{district-slug}/{school-slug}/
        add_rewrite_rule(
            '^schools/([a-z0-9-]+)/([a-z0-9-]+)/?$',
            'index.php?bmn_school_page=1&bmn_district_slug=$matches[1]&bmn_school_slug=$matches[2]',
            'top'
        );
    }

    /**
     * Add custom query variables.
     *
     * @since 0.7.0
     * @param array $vars Existing query vars.
     * @return array Modified query vars.
     */
    public function add_query_vars($vars) {
        $vars[] = 'bmn_schools_browse';
        $vars[] = 'bmn_methodology_page';
        $vars[] = 'bmn_district_page';
        $vars[] = 'bmn_district_slug';
        $vars[] = 'bmn_school_page';
        $vars[] = 'bmn_school_slug';
        return $vars;
    }

    /**
     * Handle school page requests early in the request cycle.
     *
     * @since 0.7.0
     * @param array $query_vars Query variables.
     * @return array Modified query variables.
     */
    public function handle_school_request($query_vars) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check for schools browse URL
        if (preg_match('#^/schools/?$#', $request_uri)) {
            if (!isset($query_vars['bmn_schools_browse'])) {
                $query_vars['bmn_schools_browse'] = 1;
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);
            }
        }
        // Check for methodology page URL
        elseif (preg_match('#^/schools/methodology/?$#', $request_uri)) {
            if (!isset($query_vars['bmn_methodology_page'])) {
                $query_vars['bmn_methodology_page'] = 1;
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);
            }
        }
        // Check for district detail URL
        elseif (preg_match('#^/schools/([a-z0-9-]+)/?$#', $request_uri, $matches)) {
            if (!isset($query_vars['bmn_district_page'])) {
                $query_vars['bmn_district_page'] = 1;
                $query_vars['bmn_district_slug'] = $matches[1];
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);
            }
        }
        // Check for school detail URL (Phase 2)
        elseif (preg_match('#^/schools/([a-z0-9-]+)/([a-z0-9-]+)/?$#', $request_uri, $matches)) {
            if (!isset($query_vars['bmn_school_page'])) {
                $query_vars['bmn_school_page'] = 1;
                $query_vars['bmn_district_slug'] = $matches[1];
                $query_vars['bmn_school_slug'] = $matches[2];
                unset($query_vars['pagename']);
                unset($query_vars['name']);
                unset($query_vars['error']);
            }
        }

        return $query_vars;
    }

    /**
     * Hook into pre_get_posts to ensure queries are processed correctly.
     *
     * @since 0.7.0
     * @param WP_Query $query The query object.
     */
    public function pre_get_posts($query) {
        if (!is_admin() && $query->is_main_query()) {
            $is_schools_browse = get_query_var('bmn_schools_browse');
            $is_methodology_page = get_query_var('bmn_methodology_page');
            $is_district_page = get_query_var('bmn_district_page');
            $is_school_page = get_query_var('bmn_school_page');

            if ($is_schools_browse || $is_methodology_page || $is_district_page || $is_school_page) {
                $query->is_404 = false;
                $query->is_page = false;
                $query->is_single = false;
                $query->is_singular = true;
            }
        }
    }

    /**
     * Include the appropriate template based on query vars.
     *
     * @since 0.7.0
     * @param string $template The template path.
     * @return string Modified template path.
     */
    public function template_include($template) {
        // Schools browse page
        if (get_query_var('bmn_schools_browse')) {
            return $this->load_browse_template();
        }

        // Methodology page
        if (get_query_var('bmn_methodology_page')) {
            return $this->load_methodology_template();
        }

        // District detail page
        if (get_query_var('bmn_district_page')) {
            return $this->load_district_template();
        }

        // School detail page (Phase 2)
        if (get_query_var('bmn_school_page')) {
            return $this->load_school_template();
        }

        return $template;
    }

    /**
     * Load the schools browse template.
     *
     * @since 0.7.0
     * @return string Template path.
     */
    private function load_browse_template() {
        $template = $this->theme_path . '/page-schools-browse.php';

        if (file_exists($template)) {
            // Enqueue CSS
            $this->enqueue_schools_assets();
            return $template;
        }

        // Fallback to a basic template if the theme file doesn't exist
        BMN_Schools_Logger::log('warning', 'template', 'Schools browse template not found', [
            'expected_path' => $template
        ]);

        return get_404_template();
    }

    /**
     * Load the methodology page template.
     *
     * @since 0.7.1
     * @return string Template path.
     */
    private function load_methodology_template() {
        $template = $this->theme_path . '/page-schools-methodology.php';

        if (file_exists($template)) {
            $this->enqueue_schools_assets();
            return $template;
        }

        BMN_Schools_Logger::log('warning', 'template', 'Schools methodology template not found', [
            'expected_path' => $template
        ]);

        return get_404_template();
    }

    /**
     * Load the district detail template.
     *
     * @since 0.7.0
     * @return string Template path.
     */
    private function load_district_template() {
        $slug = sanitize_title(get_query_var('bmn_district_slug'));

        if (empty($slug)) {
            return $this->render_404();
        }

        // Verify district exists
        $district = $this->find_district_by_slug($slug);

        if (!$district) {
            // Try fuzzy match for common variations
            $district = $this->find_district_fuzzy($slug);

            if ($district) {
                // Redirect to correct URL
                $correct_slug = sanitize_title($district->name);
                if ($correct_slug !== $slug) {
                    wp_redirect(home_url('/schools/' . $correct_slug . '/'), 301);
                    exit;
                }
            }

            return $this->render_404();
        }

        $template = $this->theme_path . '/page-school-district-detail.php';

        if (file_exists($template)) {
            // Enqueue CSS
            $this->enqueue_schools_assets();
            return $template;
        }

        // Try fallback to existing page-school-district.php
        $fallback = $this->theme_path . '/page-school-district.php';
        if (file_exists($fallback)) {
            $this->enqueue_schools_assets();
            return $fallback;
        }

        BMN_Schools_Logger::log('warning', 'template', 'District detail template not found', [
            'expected_path' => $template,
            'district_slug' => $slug
        ]);

        return get_404_template();
    }

    /**
     * Load the school detail template.
     *
     * @since 0.7.0
     * @return string Template path.
     */
    private function load_school_template() {
        $district_slug = sanitize_title(get_query_var('bmn_district_slug'));
        $school_slug = sanitize_title(get_query_var('bmn_school_slug'));

        if (empty($district_slug) || empty($school_slug)) {
            return $this->render_404();
        }

        // Verify district exists
        $district = $this->find_district_by_slug($district_slug);

        if (!$district) {
            return $this->render_404();
        }

        // Verify school exists in this district
        $school = $this->find_school_by_slug($school_slug, $district->id);

        if (!$school) {
            return $this->render_404();
        }

        $template = $this->theme_path . '/page-school-detail.php';

        if (file_exists($template)) {
            $this->enqueue_schools_assets();
            return $template;
        }

        BMN_Schools_Logger::log('warning', 'template', 'School detail template not found', [
            'expected_path' => $template,
            'district_slug' => $district_slug,
            'school_slug' => $school_slug
        ]);

        return get_404_template();
    }

    /**
     * Find a school by its URL slug within a specific district.
     *
     * @since 0.7.0
     * @param string $slug The URL slug.
     * @param int    $district_id The district ID.
     * @return object|null School object or null if not found.
     */
    public function find_school_by_slug($slug, $district_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_schools';

        // Get all schools in this district and check slugs
        $schools = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$table} WHERE district_id = %d",
            $district_id
        ));

        foreach ($schools as $school) {
            if (sanitize_title($school->name) === $slug) {
                // Get full school data
                return $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    $school->id
                ));
            }
        }

        return null;
    }

    /**
     * Find a district by its URL slug.
     *
     * @since 0.7.0
     * @param string $slug The URL slug.
     * @return object|null District object or null if not found.
     */
    public function find_district_by_slug($slug) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_districts';

        // Get all districts and check slugs
        // (We convert name to slug for comparison)
        $districts = wp_cache_get('bmn_district_slugs');

        if ($districts === false) {
            $districts = $wpdb->get_results("SELECT id, name, city FROM {$table}");
            wp_cache_set('bmn_district_slugs', $districts, '', 3600); // Cache for 1 hour
        }

        foreach ($districts as $district) {
            if (sanitize_title($district->name) === $slug) {
                // Get full district data
                return $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    $district->id
                ));
            }
        }

        return null;
    }

    /**
     * Try to find a district with fuzzy matching.
     *
     * Handles common variations like:
     * - "boston" matching "Boston School District"
     * - "boston-public-schools" matching "Boston"
     *
     * @since 0.7.0
     * @param string $slug The URL slug to match.
     * @return object|null District object or null if not found.
     */
    public function find_district_fuzzy($slug) {
        global $wpdb;

        $table = $wpdb->prefix . 'bmn_school_districts';

        // Try to match by city
        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE LOWER(city) = %s LIMIT 1",
            str_replace('-', ' ', $slug)
        ));

        if ($district) {
            return $district;
        }

        // Try to match by partial name
        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE LOWER(name) LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like(str_replace('-', ' ', $slug)) . '%'
        ));

        return $district;
    }

    /**
     * Render 404 page.
     *
     * @since 0.7.0
     * @return string 404 template path.
     */
    private function render_404() {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        return get_404_template();
    }

    /**
     * Enqueue CSS and JS assets for school pages.
     *
     * @since 0.7.0
     */
    private function enqueue_schools_assets() {
        // CSS
        $css_file = $this->theme_path . '/assets/css/schools.css';
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'bmn-schools-pages',
                get_stylesheet_directory_uri() . '/assets/css/schools.css',
                [],
                filemtime($css_file)
            );
        }

        // JS
        $js_file = $this->theme_path . '/assets/js/schools.js';
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'bmn-schools-pages',
                get_stylesheet_directory_uri() . '/assets/js/schools.js',
                ['jquery'],
                filemtime($js_file),
                true
            );

            // Pass data to JavaScript
            wp_localize_script('bmn-schools-pages', 'bmnSchoolsData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => rest_url('bmn-schools/v1/'),
                'nonce' => wp_create_nonce('bmn_schools_nonce'),
            ]);
        }
    }

    /**
     * Generate a URL-safe slug from a district name.
     *
     * @since 0.7.0
     * @param string $name The district name.
     * @return string URL-safe slug.
     */
    public static function generate_district_slug($name) {
        // Remove common suffixes
        $name = str_replace(
            [' School District', ' Public Schools', ' Schools', ' Regional'],
            '',
            $name
        );

        // Standard WordPress sanitization
        return sanitize_title($name);
    }

    /**
     * Get the URL for a district page.
     *
     * @since 0.7.0
     * @param object|int $district District object or ID.
     * @return string District page URL.
     */
    public static function get_district_url($district) {
        if (is_numeric($district)) {
            global $wpdb;
            $table = $wpdb->prefix . 'bmn_school_districts';
            $district = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM {$table} WHERE id = %d",
                $district
            ));
        }

        if (!$district || empty($district->name)) {
            return home_url('/schools/');
        }

        $slug = sanitize_title($district->name);
        return home_url('/schools/' . $slug . '/');
    }

    /**
     * Get the URL for a school page (Phase 2).
     *
     * @since 0.7.0
     * @param object $school School object with name and district_id.
     * @return string School page URL.
     */
    public static function get_school_url($school) {
        if (!$school || empty($school->name)) {
            return home_url('/schools/');
        }

        // Get district
        global $wpdb;
        $table = $wpdb->prefix . 'bmn_school_districts';
        $district = $wpdb->get_row($wpdb->prepare(
            "SELECT name FROM {$table} WHERE id = %d",
            $school->district_id
        ));

        if (!$district) {
            return home_url('/schools/');
        }

        $district_slug = sanitize_title($district->name);
        $school_slug = sanitize_title($school->name);

        return home_url('/schools/' . $district_slug . '/' . $school_slug . '/');
    }

    /**
     * Register rewrite rules on plugin activation.
     *
     * @since 0.7.0
     */
    public static function activate() {
        $instance = self::get_instance();
        $instance->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on plugin deactivation.
     *
     * @since 0.7.0
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
