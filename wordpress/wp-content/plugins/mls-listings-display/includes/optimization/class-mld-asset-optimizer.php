<?php
/**
 * MLS Display Asset Optimizer
 *
 * Comprehensive frontend optimization system with lazy loading, critical CSS,
 * resource hints, and performance monitoring for MLS Display plugin.
 *
 * @package MLS_Listings_Display
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Asset_Optimizer {

    /**
     * Optimization configuration
     */
    private $config = [
        'lazy_loading' => true,
        'critical_css' => true,
        'resource_hints' => true,
        'defer_js' => true,
        'minify_css' => true,
        'image_optimization' => true,
        'service_worker' => false,
        'performance_budget' => [
            'max_bundle_size' => 250 * 1024,    // 250KB per bundle
            'max_css_size' => 50 * 1024,        // 50KB critical CSS
            'max_images_per_page' => 50,        // Max lazy-loaded images
        ]
    ];

    /**
     * Asset bundles configuration
     */
    private $bundles = [
        'critical' => [
            'css' => [],
            'js' => [],
            'inline' => true,
            'priority' => 'high'
        ],
        'main' => [
            'css' => [],
            'js' => [],
            'defer' => true,
            'priority' => 'medium'
        ],
        'maps' => [
            'css' => [],
            'js' => [],
            'defer' => true,
            'conditional' => 'has_map',
            'priority' => 'low'
        ],
        'search' => [
            'css' => [],
            'js' => [],
            'defer' => true,
            'conditional' => 'is_search_page',
            'priority' => 'medium'
        ]
    ];

    /**
     * Performance metrics
     */
    private $performance_metrics = [
        'assets_optimized' => 0,
        'images_lazy_loaded' => 0,
        'css_inlined' => 0,
        'js_deferred' => 0,
        'total_size_saved' => 0
    ];

    /**
     * Initialize optimizer
     */
    public function __construct() {
        $this->load_config();
        $this->init_hooks();
    }

    /**
     * Load configuration from options
     */
    private function load_config() {
        $saved_config = get_option('mld_asset_optimizer_config', []);
        $this->config = array_merge($this->config, $saved_config);
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Frontend optimization hooks
        add_action('wp_enqueue_scripts', [$this, 'optimize_frontend_assets'], 5);
        add_action('wp_head', [$this, 'add_resource_hints'], 2);
        add_action('wp_head', [$this, 'add_critical_css'], 3);
        add_action('wp_footer', [$this, 'add_deferred_assets'], 20);

        // Image optimization hooks
        add_filter('wp_get_attachment_image_attributes', [$this, 'add_lazy_loading_attributes'], 10, 2);
        add_filter('the_content', [$this, 'optimize_content_images'], 20);

        // MLS-specific optimization hooks
        add_filter('mld_listing_image', [$this, 'optimize_listing_image'], 10, 3);
        add_filter('mld_gallery_images', [$this, 'optimize_gallery_images'], 10, 2);

        // Performance monitoring hooks
        add_action('wp_footer', [$this, 'add_performance_monitoring'], 30);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_mld_generate_critical_css', [$this, 'ajax_generate_critical_css']);
        add_action('wp_ajax_mld_clear_optimization_cache', [$this, 'ajax_clear_cache']);
    }

    /**
     * Optimize frontend assets
     */
    public function optimize_frontend_assets() {
        if (is_admin()) {
            return;
        }

        // Determine page type for conditional loading
        $page_type = $this->determine_page_type();

        // Dequeue non-critical assets
        $this->dequeue_non_critical_assets($page_type);

        // Enqueue optimized bundles
        $this->enqueue_optimized_bundles($page_type);

        // Add preload hints for critical resources
        $this->add_preload_hints($page_type);
    }

    /**
     * Determine current page type
     *
     * @return string Page type
     */
    private function determine_page_type() {
        global $post;

        // Check for BMN Schools pages (district detail and school detail pages need maps)
        $is_district_page = get_query_var('bmn_district_page');
        $is_school_page = get_query_var('bmn_school_page');
        if ($is_district_page || $is_school_page) {
            return 'map'; // These pages need Google Maps for location display
        }

        if (is_page() && $post) {
            $content = $post->post_content;

            if (strpos($content, '[mls_search') !== false) {
                return 'search';
            }
            if (strpos($content, '[mls_map') !== false) {
                return 'map';
            }
            if (strpos($content, '[mls_property') !== false) {
                return 'property';
            }
        }

        if (is_singular('property')) {
            return 'property';
        }

        return 'general';
    }

    /**
     * Dequeue non-critical assets
     *
     * @param string $page_type Page type
     */
    private function dequeue_non_critical_assets($page_type) {
        // Remove non-essential CSS on non-MLS pages
        if ($page_type === 'general') {
            wp_dequeue_style('mld-main-styles');
            wp_dequeue_style('mld-map-styles');
            wp_dequeue_script('mld-map-scripts');
        }

        // Remove map assets on non-map pages
        if (!in_array($page_type, ['map', 'search'])) {
            wp_dequeue_style('mld-map-styles');
            wp_dequeue_script('google-maps-api');
            wp_dequeue_script('mld-map-core');
        }

        // Remove search-specific assets on non-search pages
        if ($page_type !== 'search') {
            wp_dequeue_script('mld-search-filters');
            wp_dequeue_script('mld-search-mobile');
        }
    }

    /**
     * Enqueue optimized bundles
     *
     * @param string $page_type Page type
     */
    private function enqueue_optimized_bundles($page_type) {
        $plugin_url = plugin_dir_url(dirname(__FILE__));

        // Critical CSS bundle (inlined)
        $this->enqueue_critical_bundle($page_type);

        // Main JavaScript bundle (deferred)
        wp_enqueue_script(
            'mld-main-bundle',
            $plugin_url . 'assets/bundles/main.min.js',
            [],
            $this->get_bundle_version('main'),
            true
        );

        // Conditional bundles
        if (in_array($page_type, ['map', 'search'])) {
            wp_enqueue_script(
                'mld-map-bundle',
                $plugin_url . 'assets/bundles/map.min.js',
                ['mld-main-bundle'],
                $this->get_bundle_version('map'),
                true
            );
        }

        if ($page_type === 'search') {
            wp_enqueue_script(
                'mld-search-bundle',
                $plugin_url . 'assets/bundles/search.min.js',
                ['mld-main-bundle'],
                $this->get_bundle_version('search'),
                true
            );
        }

        // Add defer attribute to JavaScript bundles
        if ($this->config['defer_js']) {
            add_filter('script_loader_tag', [$this, 'add_defer_attribute'], 10, 2);
        }
    }

    /**
     * Enqueue critical CSS bundle
     *
     * @param string $page_type Page type
     */
    private function enqueue_critical_bundle($page_type) {
        if (!$this->config['critical_css']) {
            return;
        }

        $critical_css = $this->get_critical_css($page_type);
        if ($critical_css) {
            // Inline critical CSS
            wp_add_inline_style('wp-block-library', $critical_css);
            $this->performance_metrics['css_inlined']++;
        }
    }

    /**
     * Add resource hints
     */
    public function add_resource_hints() {
        if (!$this->config['resource_hints']) {
            return;
        }

        // DNS prefetch for external domains
        echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
        echo '<link rel="dns-prefetch" href="//maps.googleapis.com">' . "\n";
        echo '<link rel="dns-prefetch" href="//api.bridgeinteractive.com">' . "\n";

        // Preconnect to critical external resources
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '<link rel="preconnect" href="https://maps.googleapis.com">' . "\n";

        // Module preload for modern browsers
        if ($this->supports_es_modules()) {
            echo '<link rel="modulepreload" href="' . plugin_dir_url(dirname(__FILE__)) . 'assets/modules/main.mjs">' . "\n";
        }
    }

    /**
     * Add critical CSS
     */
    public function add_critical_css() {
        if (!$this->config['critical_css']) {
            return;
        }

        $page_type = $this->determine_page_type();
        $critical_css = $this->get_critical_css($page_type);

        if ($critical_css) {
            echo '<style id="mld-critical-css">' . "\n";
            echo $critical_css;
            echo "\n" . '</style>' . "\n";

            // Preload full CSS for faster subsequent page loads
            $main_css_url = plugin_dir_url(dirname(__FILE__)) . 'assets/css/main.min.css';
            echo '<link rel="preload" href="' . $main_css_url . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n";
            echo '<noscript><link rel="stylesheet" href="' . $main_css_url . '"></noscript>' . "\n";
        }
    }

    /**
     * Add deferred assets
     */
    public function add_deferred_assets() {
        // Load non-critical CSS
        $this->load_non_critical_css();

        // Add performance monitoring script
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->add_performance_monitoring_script();
        }
    }

    /**
     * Load non-critical CSS
     */
    private function load_non_critical_css() {
        $page_type = $this->determine_page_type();

        // Load page-specific CSS
        $css_files = [
            'general' => ['main.min.css'],
            'search' => ['main.min.css', 'search.min.css'],
            'map' => ['main.min.css', 'map.min.css'],
            'property' => ['main.min.css', 'property.min.css']
        ];

        $files_to_load = $css_files[$page_type] ?? $css_files['general'];

        foreach ($files_to_load as $file) {
            $this->load_css_async($file);
        }
    }

    /**
     * Load CSS asynchronously
     *
     * @param string $filename CSS filename
     */
    private function load_css_async($filename) {
        $css_url = plugin_dir_url(dirname(__FILE__)) . 'assets/css/' . $filename;
        $version = $this->get_bundle_version(pathinfo($filename, PATHINFO_FILENAME));

        echo '<script>';
        echo "if(!document.querySelector('link[href=\"{$css_url}\"]')){";
        echo "var link=document.createElement('link');";
        echo "link.rel='stylesheet';";
        echo "link.href='{$css_url}?v={$version}';";
        echo "document.head.appendChild(link);";
        echo '}';
        echo '</script>' . "\n";
    }

    /**
     * Add lazy loading attributes to images
     *
     * @param array $attr Image attributes
     * @param WP_Post $attachment Attachment post object
     * @return array Modified attributes
     */
    public function add_lazy_loading_attributes($attr, $attachment) {
        if (!$this->config['lazy_loading']) {
            return $attr;
        }

        // Skip lazy loading for above-the-fold images
        if ($this->is_above_the_fold_image($attr)) {
            return $attr;
        }

        $attr['loading'] = 'lazy';
        $attr['data-src'] = $attr['src'];
        $attr['src'] = $this->get_placeholder_image();
        $attr['class'] = (isset($attr['class']) ? $attr['class'] . ' ' : '') . 'mld-lazy-image';

        $this->performance_metrics['images_lazy_loaded']++;

        return $attr;
    }

    /**
     * Optimize content images
     *
     * @param string $content Content HTML
     * @return string Optimized content
     */
    public function optimize_content_images($content) {
        if (!$this->config['lazy_loading']) {
            return $content;
        }

        // Use regex to find and optimize images in content
        $content = preg_replace_callback(
            '/<img([^>]+)>/i',
            [$this, 'optimize_image_tag'],
            $content
        );

        return $content;
    }

    /**
     * Optimize individual image tag
     *
     * @param array $matches Regex matches
     * @return string Optimized image tag
     */
    private function optimize_image_tag($matches) {
        $img_tag = $matches[0];
        $attributes = $matches[1];

        // Skip if already optimized
        if (strpos($attributes, 'data-src') !== false) {
            return $img_tag;
        }

        // Extract src attribute
        if (preg_match('/src=["\']([^"\']+)["\']/', $attributes, $src_matches)) {
            $original_src = $src_matches[1];
            $placeholder = $this->get_placeholder_image();

            // Replace src with placeholder and add data-src
            $optimized_attributes = preg_replace(
                '/src=["\']([^"\']+)["\']/',
                'src="' . $placeholder . '" data-src="' . $original_src . '"',
                $attributes
            );

            // Add lazy loading class
            if (strpos($optimized_attributes, 'class=') !== false) {
                $optimized_attributes = preg_replace(
                    '/class=["\']([^"\']*)["\']/',
                    'class="$1 mld-lazy-image"',
                    $optimized_attributes
                );
            } else {
                $optimized_attributes .= ' class="mld-lazy-image"';
            }

            // Add loading attribute
            $optimized_attributes .= ' loading="lazy"';

            return '<img' . $optimized_attributes . '>';
        }

        return $img_tag;
    }

    /**
     * Optimize listing image
     *
     * @param string $image_html Image HTML
     * @param array $listing Listing data
     * @param array $options Image options
     * @return string Optimized image HTML
     */
    public function optimize_listing_image($image_html, $listing, $options = []) {
        if (!$this->config['image_optimization']) {
            return $image_html;
        }

        // Add responsive images and WebP support
        $image_html = $this->add_responsive_images($image_html, $options);
        $image_html = $this->add_webp_support($image_html);

        return $image_html;
    }

    /**
     * Optimize gallery images
     *
     * @param array $images Gallery images
     * @param array $options Gallery options
     * @return array Optimized images
     */
    public function optimize_gallery_images($images, $options = []) {
        if (!$this->config['image_optimization']) {
            return $images;
        }

        foreach ($images as &$image) {
            // Add responsive srcset
            $image['srcset'] = $this->generate_responsive_srcset($image['url']);
            $image['sizes'] = $this->get_gallery_sizes($options);

            // Add WebP variant
            $image['webp_url'] = $this->get_webp_url($image['url']);
        }

        return $images;
    }

    /**
     * Add responsive images
     *
     * @param string $image_html Image HTML
     * @param array $options Image options
     * @return string HTML with responsive images
     */
    private function add_responsive_images($image_html, $options) {
        // Extract image URL
        if (preg_match('/src=["\']([^"\']+)["\']/', $image_html, $matches)) {
            $image_url = $matches[1];
            $srcset = $this->generate_responsive_srcset($image_url);
            $sizes = $this->get_responsive_sizes($options);

            // Add srcset and sizes attributes
            $image_html = preg_replace(
                '/(<img[^>]+)>/',
                '$1 srcset="' . $srcset . '" sizes="' . $sizes . '">',
                $image_html
            );
        }

        return $image_html;
    }

    /**
     * Add WebP support
     *
     * @param string $image_html Image HTML
     * @return string HTML with WebP support
     */
    private function add_webp_support($image_html) {
        // Extract image URL and create WebP version
        if (preg_match('/src=["\']([^"\']+)["\']/', $image_html, $matches)) {
            $image_url = $matches[1];
            $webp_url = $this->get_webp_url($image_url);

            if ($webp_url !== $image_url) {
                // Wrap in picture element with WebP source
                $picture_html = '<picture>';
                $picture_html .= '<source srcset="' . $webp_url . '" type="image/webp">';
                $picture_html .= $image_html;
                $picture_html .= '</picture>';

                return $picture_html;
            }
        }

        return $image_html;
    }

    /**
     * Generate responsive srcset
     *
     * @param string $image_url Base image URL
     * @return string Srcset attribute value
     */
    private function generate_responsive_srcset($image_url) {
        $breakpoints = [400, 600, 800, 1200, 1600];
        $srcset = [];

        foreach ($breakpoints as $width) {
            $responsive_url = $this->get_responsive_image_url($image_url, $width);
            $srcset[] = $responsive_url . ' ' . $width . 'w';
        }

        return implode(', ', $srcset);
    }

    /**
     * Get responsive image URL
     *
     * @param string $image_url Original image URL
     * @param int $width Target width
     * @return string Responsive image URL
     */
    private function get_responsive_image_url($image_url, $width) {
        // Check if it's a CloudFront URL and add width parameter
        if (strpos($image_url, 'cloudfront.net') !== false) {
            $separator = strpos($image_url, '?') !== false ? '&' : '?';
            return $image_url . $separator . 'w=' . $width . '&q=85';
        }

        // For local images, could implement WordPress image size generation
        return $image_url;
    }

    /**
     * Get WebP URL
     *
     * @param string $image_url Original image URL
     * @return string WebP image URL
     */
    private function get_webp_url($image_url) {
        // Check if it's a CloudFront URL and add format parameter
        if (strpos($image_url, 'cloudfront.net') !== false) {
            $separator = strpos($image_url, '?') !== false ? '&' : '?';
            return $image_url . $separator . 'f=webp&q=85';
        }

        // For local images, could implement WebP conversion
        $webp_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $image_url);

        // Check if WebP version exists
        $upload_dir = wp_upload_dir();
        $webp_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $webp_url);

        if (file_exists($webp_path)) {
            return $webp_url;
        }

        return $image_url;
    }

    /**
     * Get responsive sizes attribute
     *
     * @param array $options Image options
     * @return string Sizes attribute value
     */
    private function get_responsive_sizes($options) {
        $context = $options['context'] ?? 'listing';

        switch ($context) {
            case 'gallery':
                return '(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 33vw';
            case 'hero':
                return '100vw';
            case 'thumbnail':
                return '(max-width: 600px) 50vw, 25vw';
            default:
                return '(max-width: 600px) 100vw, (max-width: 1200px) 50vw, 400px';
        }
    }

    /**
     * Get gallery sizes
     *
     * @param array $options Gallery options
     * @return string Sizes attribute value
     */
    private function get_gallery_sizes($options) {
        $columns = $options['columns'] ?? 3;

        switch ($columns) {
            case 1:
                return '100vw';
            case 2:
                return '(max-width: 600px) 100vw, 50vw';
            case 3:
                return '(max-width: 600px) 100vw, (max-width: 900px) 50vw, 33vw';
            case 4:
                return '(max-width: 600px) 100vw, (max-width: 900px) 50vw, 25vw';
            default:
                return '(max-width: 600px) 100vw, (max-width: 900px) 50vw, 33vw';
        }
    }

    /**
     * Check if image is above the fold
     *
     * @param array $attr Image attributes
     * @return bool Whether image is above the fold
     */
    private function is_above_the_fold_image($attr) {
        // Check for hero or featured image classes
        $class = $attr['class'] ?? '';

        $above_fold_classes = [
            'hero-image',
            'featured-image',
            'mld-primary-image',
            'wp-post-image'
        ];

        foreach ($above_fold_classes as $hero_class) {
            if (strpos($class, $hero_class) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get placeholder image
     *
     * @return string Placeholder image URL
     */
    private function get_placeholder_image() {
        // Generate a lightweight SVG placeholder
        $svg = '<svg width="400" height="300" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="100%" height="100%" fill="#f0f0f0"/>';
        $svg .= '<text x="50%" y="50%" text-anchor="middle" dy="0.3em" fill="#999">Loading...</text>';
        $svg .= '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Add defer attribute to scripts
     *
     * @param string $tag Script tag
     * @param string $handle Script handle
     * @return string Modified script tag
     */
    public function add_defer_attribute($tag, $handle) {
        $defer_handles = [
            'mld-main-bundle',
            'mld-map-bundle',
            'mld-search-bundle'
        ];

        if (in_array($handle, $defer_handles)) {
            return str_replace(' src', ' defer src', $tag);
        }

        return $tag;
    }

    /**
     * Get critical CSS for page type
     *
     * @param string $page_type Page type
     * @return string Critical CSS
     */
    private function get_critical_css($page_type) {
        $cache_key = 'mld_critical_css_' . $page_type;
        $critical_css = get_transient($cache_key);

        if ($critical_css === false) {
            $critical_css = $this->generate_critical_css($page_type);
            set_transient($cache_key, $critical_css, DAY_IN_SECONDS);
        }

        return $critical_css;
    }

    /**
     * Generate critical CSS for page type
     *
     * @param string $page_type Page type
     * @return string Generated critical CSS
     */
    private function generate_critical_css($page_type) {
        // Base critical CSS (above-the-fold)
        $critical_css = '
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .mld-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .mld-header { background: #fff; border-bottom: 1px solid #e1e5e9; }
        .mld-loading { display: flex; justify-content: center; padding: 40px; }
        .mld-spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #007cba; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        ';

        // Page-specific critical CSS
        switch ($page_type) {
            case 'search':
                $critical_css .= '
                .mld-search-container { display: grid; grid-template-columns: 300px 1fr; gap: 20px; }
                .mld-filters { background: #f8f9fa; padding: 20px; border-radius: 4px; }
                .mld-results { min-height: 400px; }
                .mld-listing-card { border: 1px solid #e1e5e9; border-radius: 4px; margin-bottom: 20px; }
                ';
                break;

            case 'map':
                $critical_css .= '
                .mld-map-container { height: 500px; position: relative; }
                .mld-map-controls { position: absolute; top: 10px; left: 10px; z-index: 1000; }
                .mld-sidebar { width: 400px; height: 500px; overflow-y: auto; }
                ';
                break;

            case 'property':
                $critical_css .= '
                .mld-property-header { margin-bottom: 30px; }
                .mld-property-title { font-size: 2rem; margin-bottom: 10px; }
                .mld-property-price { font-size: 1.5rem; color: #007cba; font-weight: bold; }
                .mld-property-gallery { margin-bottom: 30px; }
                ';
                break;
        }

        // Responsive breakpoints
        $critical_css .= '
        @media (max-width: 768px) {
            .mld-search-container { grid-template-columns: 1fr; }
            .mld-container { padding: 0 15px; }
        }
        ';

        return $this->minify_css($critical_css);
    }

    /**
     * Minify CSS
     *
     * @param string $css CSS content
     * @return string Minified CSS
     */
    private function minify_css($css) {
        if (!$this->config['minify_css']) {
            return $css;
        }

        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

        return trim($css);
    }

    /**
     * Get bundle version
     *
     * @param string $bundle Bundle name
     * @return string Version string
     */
    private function get_bundle_version($bundle) {
        $versions = get_option('mld_asset_versions', []);
        return $versions[$bundle] ?? MLD_VERSION;
    }

    /**
     * Check if browser supports ES modules
     *
     * @return bool Whether ES modules are supported
     */
    private function supports_es_modules() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Simple check for modern browsers
        return !preg_match('/MSIE|Trident|Edge\/1[2-8]/', $user_agent);
    }

    /**
     * Add performance monitoring script
     */
    private function add_performance_monitoring_script() {
        ?>
        <script>
        (function() {
            if (!window.performance || !window.performance.timing) return;

            var timing = performance.timing;
            var interactive = timing.domInteractive - timing.navigationStart;
            var complete = timing.loadEventEnd - timing.navigationStart;

            // Send metrics to server
            if (complete > 0) {
                var data = {
                    action: 'mld_performance_metrics',
                    interactive: interactive,
                    complete: complete,
                    url: location.pathname
                };

                navigator.sendBeacon(ajaxurl, new URLSearchParams(data));
            }
        })();
        </script>
        <?php
    }

    /**
     * Add performance monitoring
     */
    public function add_performance_monitoring() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $this->add_performance_monitoring_script();

        // Log performance metrics
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('MLD Asset Optimizer Metrics: ' . json_encode($this->performance_metrics));
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'MLS Asset Optimizer',
            'MLS Performance',
            'manage_options',
            'mld-asset-optimizer',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_admin_settings();
        }

        $stats = $this->get_optimization_stats();
        ?>
        <div class="wrap">
            <h1>MLS Asset Optimizer</h1>

            <div class="mld-optimizer-dashboard">
                <div class="stats-section">
                    <h2>Optimization Statistics</h2>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Assets Optimized</h3>
                            <span class="stat-value"><?php echo $stats['assets_optimized']; ?></span>
                        </div>
                        <div class="stat-card">
                            <h3>Images Lazy Loaded</h3>
                            <span class="stat-value"><?php echo $stats['images_lazy_loaded']; ?></span>
                        </div>
                        <div class="stat-card">
                            <h3>Size Saved</h3>
                            <span class="stat-value"><?php echo size_format($stats['total_size_saved']); ?></span>
                        </div>
                        <div class="stat-card">
                            <h3>Cache Hit Rate</h3>
                            <span class="stat-value"><?php echo $stats['cache_hit_rate']; ?>%</span>
                        </div>
                    </div>
                </div>

                <div class="settings-section">
                    <h2>Optimization Settings</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('mld_optimizer_settings'); ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">Lazy Loading</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="lazy_loading" value="1" <?php checked($this->config['lazy_loading']); ?>>
                                        Enable lazy loading for images
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Critical CSS</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="critical_css" value="1" <?php checked($this->config['critical_css']); ?>>
                                        Generate and inline critical CSS
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Resource Hints</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="resource_hints" value="1" <?php checked($this->config['resource_hints']); ?>>
                                        Add DNS prefetch and preconnect hints
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Defer JavaScript</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="defer_js" value="1" <?php checked($this->config['defer_js']); ?>>
                                        Defer non-critical JavaScript
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(); ?>
                    </form>
                </div>

                <div class="tools-section">
                    <h2>Optimization Tools</h2>
                    <button type="button" class="button" onclick="generateCriticalCSS()">Regenerate Critical CSS</button>
                    <button type="button" class="button" onclick="clearOptimizationCache()">Clear Optimization Cache</button>
                </div>
            </div>
        </div>

        <script>
        function generateCriticalCSS() {
            var button = event.target;
            button.disabled = true;
            button.textContent = 'Generating...';

            jQuery.post(ajaxurl, {
                action: 'mld_generate_critical_css',
                _wpnonce: '<?php echo wp_create_nonce('mld_generate_critical_css'); ?>'
            }, function(response) {
                button.disabled = false;
                button.textContent = 'Regenerate Critical CSS';
                alert(response.success ? 'Critical CSS generated successfully!' : 'Error: ' + response.data);
            });
        }

        function clearOptimizationCache() {
            var button = event.target;
            button.disabled = true;
            button.textContent = 'Clearing...';

            jQuery.post(ajaxurl, {
                action: 'mld_clear_optimization_cache',
                _wpnonce: '<?php echo wp_create_nonce('mld_clear_cache'); ?>'
            }, function(response) {
                button.disabled = false;
                button.textContent = 'Clear Optimization Cache';
                alert(response.success ? 'Cache cleared successfully!' : 'Error: ' + response.data);
            });
        }
        </script>

        <style>
        .mld-optimizer-dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-gap: 20px;
            margin-top: 20px;
        }

        .tools-section {
            grid-column: span 2;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            grid-gap: 15px;
            margin-top: 15px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            text-align: center;
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #646970;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #1d2327;
        }

        .settings-section, .stats-section, .tools-section {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        </style>
        <?php
    }

    /**
     * Save admin settings
     */
    private function save_admin_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'mld_optimizer_settings')) {
            return;
        }

        $this->config['lazy_loading'] = !empty($_POST['lazy_loading']);
        $this->config['critical_css'] = !empty($_POST['critical_css']);
        $this->config['resource_hints'] = !empty($_POST['resource_hints']);
        $this->config['defer_js'] = !empty($_POST['defer_js']);

        update_option('mld_asset_optimizer_config', $this->config);

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

    /**
     * Get optimization statistics
     *
     * @return array Statistics
     */
    private function get_optimization_stats() {
        return [
            'assets_optimized' => get_option('mld_assets_optimized_count', 0),
            'images_lazy_loaded' => get_option('mld_images_lazy_loaded_count', 0),
            'total_size_saved' => get_option('mld_total_size_saved', 0),
            'cache_hit_rate' => get_option('mld_cache_hit_rate', 0)
        ];
    }

    /**
     * AJAX handler for generating critical CSS
     */
    public function ajax_generate_critical_css() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'mld_generate_critical_css')) {
            wp_send_json_error('Invalid nonce');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Clear existing critical CSS cache
        $page_types = ['general', 'search', 'map', 'property'];
        foreach ($page_types as $type) {
            delete_transient('mld_critical_css_' . $type);
        }

        wp_send_json_success('Critical CSS cache cleared and will be regenerated on next page load');
    }

    /**
     * AJAX handler for clearing optimization cache
     */
    public function ajax_clear_cache() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'mld_clear_cache')) {
            wp_send_json_error('Invalid nonce');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Clear all optimization-related transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mld_%' OR option_name LIKE '_transient_timeout_mld_%'");

        wp_send_json_success('Optimization cache cleared successfully');
    }
}