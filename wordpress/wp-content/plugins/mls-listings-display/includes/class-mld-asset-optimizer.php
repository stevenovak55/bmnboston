<?php
/**
 * MLD Asset Optimizer
 *
 * Handles JavaScript and CSS concatenation, minification, and optimization
 * for improved page load performance.
 *
 * @since 4.5.59
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Asset_Optimizer {

    /**
     * Asset bundles configuration
     */
    private static $asset_bundles = [
        'map-core' => [
            'files' => [
                'mld-logger.js',
                'map-api.js',
                'map-core.js',
                'map-filters.js'
            ],
            'dependencies' => ['jquery', 'google-maps-api'],
            'pages' => ['search']
        ],
        'map-features' => [
            'files' => [
                'map-markers.js',
                'map-gallery.js',
                'map-city-boundaries.js',
                'map-schools.js',
                'map-controls-panel.js'
            ],
            'dependencies' => ['jquery', 'mld-map-core-bundle'],
            'pages' => ['search']
        ],
        'map-enhancements' => [
            'files' => [
                'mld-script-loader.js',
                'map-multi-unit-modal.js',
                'mld-saved-searches.js',
                'mld-saved-searches-mobile-fix.js',
                'mobile-scroll-behavior.js'
            ],
            'dependencies' => ['jquery', 'mld-map-features-bundle'],
            'pages' => ['search']
        ],
        'property-detail' => [
            'files' => [
                'mld-logger.js',
                'property-desktop-v3.js',
                'property-mobile-v3.js',
                'modules/mld-modules-init.js'
            ],
            'dependencies' => ['jquery', 'google-maps-api'],
            'pages' => ['property']
        ]
    ];

    /**
     * Critical CSS configuration
     */
    private static $critical_css_pages = [
        'search' => [
            'above_fold_selectors' => [
                '.mld-search-header',
                '.mld-filter-controls',
                '.mld-map-container',
                '.mld-listing-grid',
                '.mld-card-simple-image img:first-child'
            ],
            'critical_size' => '1024x768'
        ],
        'property' => [
            'above_fold_selectors' => [
                '.mld-v3-hero-gallery',
                '.mld-v3-gallery-main img:first-child',
                '.mld-v3-property-header',
                '.mld-v3-price-section'
            ],
            'critical_size' => '1024x768'
        ]
    ];

    /**
     * Initialize the asset optimizer
     */
    public static function init() {
        // Only optimize on production or when specifically enabled
        if (self::should_optimize_assets()) {
            add_action('init', [self::class, 'generate_asset_bundles']);
            add_filter('mld_enqueue_assets', [self::class, 'use_optimized_assets'], 10, 2);
        }

        // Always add resource hints
        add_action('wp_head', [self::class, 'add_resource_hints'], 1);
        add_action('wp_head', [self::class, 'add_critical_css'], 2);
        add_action('wp_enqueue_scripts', [self::class, 'optimize_font_loading'], 1);
        add_action('wp_footer', [self::class, 'register_service_worker'], 30);
    }

    /**
     * Check if asset optimization should be enabled
     */
    private static function should_optimize_assets() {
        // Enable if not in debug mode or if specifically forced
        return !WP_DEBUG || defined('MLD_FORCE_ASSET_OPTIMIZATION');
    }

    /**
     * Check if preloads should be enabled
     */
    private static function should_enable_preloads() {
        // Only enable preloads in production or when specifically enabled
        return !WP_DEBUG || defined('MLD_ENABLE_PRELOADS');
    }

    /**
     * Generate concatenated and minified asset bundles
     */
    public static function generate_asset_bundles() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/mld-cache/';

        // Create cache directory if it doesn't exist
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        foreach (self::$asset_bundles as $bundle_name => $config) {
            $bundle_file = $cache_dir . "mld-{$bundle_name}-bundle.min.js";
            $version_file = $cache_dir . "mld-{$bundle_name}-bundle.version";

            // Check if bundle needs regeneration
            if (self::bundle_needs_update($bundle_file, $version_file, $config['files'])) {
                self::create_js_bundle($bundle_name, $config['files'], $bundle_file);
                file_put_contents($version_file, MLD_VERSION);
            }
        }
    }

    /**
     * Check if a bundle needs to be updated
     */
    private static function bundle_needs_update($bundle_file, $version_file, $source_files) {
        if (!file_exists($bundle_file) || !file_exists($version_file)) {
            return true;
        }

        $bundle_version = file_get_contents($version_file);
        if ($bundle_version !== MLD_VERSION) {
            return true;
        }

        $bundle_time = filemtime($bundle_file);
        foreach ($source_files as $file) {
            $source_path = MLD_PLUGIN_PATH . 'assets/js/' . $file;
            if (file_exists($source_path) && filemtime($source_path) > $bundle_time) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create concatenated and minified JavaScript bundle
     */
    private static function create_js_bundle($bundle_name, $files, $output_file) {
        $concatenated = '';
        $concatenated .= "/* MLD {$bundle_name} Bundle - Generated " . date('Y-m-d H:i:s') . " */\n";

        foreach ($files as $file) {
            $file_path = MLD_PLUGIN_PATH . 'assets/js/' . $file;
            if (file_exists($file_path)) {
                $content = file_get_contents($file_path);

                // Remove strict mode declarations to avoid conflicts
                $content = preg_replace('/["\']use strict["\'];?\s*/', '', $content);

                // Wrap in IIFE to prevent global scope pollution
                $concatenated .= "\n/* === {$file} === */\n";
                $concatenated .= "(function() {\n";
                $concatenated .= $content;
                $concatenated .= "\n})();\n";
            }
        }

        // Apply basic minification
        $minified = self::minify_js($concatenated);

        file_put_contents($output_file, $minified);
    }

    /**
     * Basic JavaScript minification
     */
    private static function minify_js($js) {
        // Remove comments (but preserve license comments)
        $js = preg_replace('/\/\*(?![!*]).*?\*\//s', '', $js);
        $js = preg_replace('/\/\/(?![#@]).*$/m', '', $js);

        // Remove unnecessary whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        $js = preg_replace('/\s*([{}();,])\s*/', '$1', $js);

        // Remove trailing semicolons before closing braces
        $js = preg_replace('/;\s*}/', '}', $js);

        return trim($js);
    }

    /**
     * Use optimized assets instead of individual files
     */
    public static function use_optimized_assets($use_optimized, $page_type) {
        if (!self::should_optimize_assets()) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $cache_url = $upload_dir['baseurl'] . '/mld-cache/';

        // Dequeue individual scripts and enqueue bundles
        foreach (self::$asset_bundles as $bundle_name => $config) {
            if (in_array($page_type, $config['pages'])) {

                // Dequeue individual files
                foreach ($config['files'] as $file) {
                    $handle = 'mld-' . str_replace(['.js', '/'], ['', '-'], $file);
                    wp_dequeue_script($handle);
                }

                // Enqueue bundle
                $bundle_url = $cache_url . "mld-{$bundle_name}-bundle.min.js";
                $bundle_handle = "mld-{$bundle_name}-bundle";

                wp_enqueue_script(
                    $bundle_handle,
                    $bundle_url,
                    $config['dependencies'],
                    MLD_VERSION,
                    true
                );
            }
        }

        return true;
    }

    /**
     * Add resource hints for better performance
     */
    public static function add_resource_hints() {
        global $wp_query;

        // DNS prefetch for external domains
        echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
        echo '<link rel="dns-prefetch" href="//maps.googleapis.com">' . "\n";

        // Add CloudFront preconnect if we detect CloudFront images
        if (self::uses_cloudfront_images()) {
            echo '<link rel="preconnect" href="//dvvjkgh94f2v6.cloudfront.net" crossorigin>' . "\n";
        }

        // Preconnect to Google Maps for search pages
        if (self::is_search_page() || self::is_property_page()) {
            echo '<link rel="preconnect" href="//maps.googleapis.com" crossorigin>' . "\n";
            echo '<link rel="preconnect" href="//maps.gstatic.com" crossorigin>' . "\n";
        }

        // Preload critical assets (only in production)
        if (self::should_enable_preloads()) {
            if (self::is_search_page()) {
                echo '<link rel="preload" href="' . MLD_PLUGIN_URL . 'assets/css/main.css" as="style">' . "\n";
                echo '<link rel="preload" href="' . MLD_PLUGIN_URL . 'assets/css/search-mobile.css" as="style">' . "\n";
            }

            if (self::is_property_page()) {
                echo '<link rel="preload" href="' . MLD_PLUGIN_URL . 'assets/css/main.css" as="style">' . "\n";
                echo '<link rel="preload" href="' . MLD_PLUGIN_URL . 'assets/css/property-desktop-v3.css" as="style">' . "\n";
                echo '<link rel="preload" href="' . MLD_PLUGIN_URL . 'assets/css/property-mobile-v3.css" as="style">' . "\n";
            }
        }
    }

    /**
     * Add critical CSS for above-the-fold content
     */
    public static function add_critical_css() {
        $page_type = null;

        if (self::is_search_page()) {
            $page_type = 'search';
        } elseif (self::is_property_page()) {
            $page_type = 'property';
        }

        if ($page_type && isset(self::$critical_css_pages[$page_type])) {
            $critical_css = self::generate_critical_css($page_type);
            if ($critical_css) {
                echo "<style id='mld-critical-css'>\n{$critical_css}\n</style>\n";
            }
        }
    }

    /**
     * Generate critical CSS for a page type
     */
    private static function generate_critical_css($page_type) {
        $cache_file = WP_CONTENT_DIR . "/cache/mld-critical-{$page_type}.css";

        // Return cached version if available and recent
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 86400) {
            return file_get_contents($cache_file);
        }

        $config = self::$critical_css_pages[$page_type];
        $critical_css = '';

        // Extract critical styles from main CSS file
        $main_css_path = MLD_PLUGIN_PATH . 'assets/css/main.css';
        if (file_exists($main_css_path)) {
            $css_content = file_get_contents($main_css_path);

            foreach ($config['above_fold_selectors'] as $selector) {
                $pattern = '/' . preg_quote($selector, '/') . '\s*\{[^}]*\}/';
                if (preg_match($pattern, $css_content, $matches)) {
                    $critical_css .= $matches[0] . "\n";
                }
            }
        }

        // Add essential layout styles
        $critical_css .= self::get_essential_layout_css($page_type);

        // Cache the result
        if (!empty($critical_css)) {
            wp_mkdir_p(dirname($cache_file));
            file_put_contents($cache_file, $critical_css);
        }

        return $critical_css;
    }

    /**
     * Get essential layout CSS for above-the-fold content
     */
    private static function get_essential_layout_css($page_type) {
        $css = '';

        if ($page_type === 'search') {
            $css .= '
            .mld-search-header { display: flex; align-items: center; margin-bottom: 1rem; }
            .mld-filter-controls { background: #fff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
            .mld-map-container { height: 60vh; position: relative; }
            .mld-listing-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
            .mld-card-simple-image img { width: 100%; height: 200px; object-fit: cover; }
            ';
        }

        if ($page_type === 'property') {
            $css .= '
            .mld-v3-hero-gallery { position: relative; height: 60vh; }
            .mld-v3-gallery-main img { width: 100%; height: 100%; object-fit: cover; }
            .mld-v3-property-header { padding: 2rem 0; }
            .mld-v3-price-section { font-size: 2rem; font-weight: bold; color: #2c3e50; }
            ';
        }

        return $css;
    }

    /**
     * Optimize font loading with display swap
     */
    public static function optimize_font_loading() {
        // Add font-display: swap to Google Fonts
        add_filter('style_loader_tag', function($html, $handle) {
            if (strpos($handle, 'google-fonts') !== false || strpos($html, 'fonts.googleapis.com') !== false) {
                $html = str_replace("rel='stylesheet'", "rel='stylesheet' font-display='swap'", $html);
            }
            return $html;
        }, 10, 2);
    }

    /**
     * Helper methods
     */
    private static function is_search_page() {
        global $wp_query;
        return is_page() && (strpos($wp_query->query_vars['pagename'] ?? '', 'search') === 0);
    }

    private static function is_property_page() {
        global $wp_query;
        return is_page() && (strpos($wp_query->query_vars['pagename'] ?? '', 'property') === 0);
    }

    private static function uses_cloudfront_images() {
        global $wpdb;

        // Quick check for CloudFront URLs in media table
        // The column is named 'media_url' (lowercase) in Bridge MLS Extractor Pro
        $has_cloudfront = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}bme_media
            WHERE media_url LIKE '%.cloudfront.net%'
            LIMIT 1
        ");

        return $has_cloudfront > 0;
    }

    /**
     * Register service worker for caching
     */
    public static function register_service_worker() {
        // Only register on MLS pages
        if (!self::is_search_page() && !self::is_property_page()) {
            return;
        }

        $sw_url = MLD_PLUGIN_URL . 'assets/js/mld-service-worker.js';
        ?>
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo $sw_url; ?>')
                    .then(function(registration) {
                        console.log('[MLD] Service Worker registered successfully:', registration.scope);

                        // Update cache when new version is available
                        registration.addEventListener('updatefound', function() {
                            console.log('[MLD] Service Worker update found');
                        });
                    })
                    .catch(function(error) {
                        console.log('[MLD] Service Worker registration failed:', error);
                    });
            });
        }
        </script>
        <?php
    }

    /**
     * Clear asset cache
     */
    public static function clear_cache() {
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/mld-cache/';

        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . 'mld-*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Clear critical CSS cache
        $critical_cache_dir = WP_CONTENT_DIR . '/cache/';
        $critical_files = glob($critical_cache_dir . 'mld-critical-*.css');
        foreach ($critical_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// Clear cache when plugin is updated
register_activation_hook(MLD_PLUGIN_FILE, ['MLD_Asset_Optimizer', 'clear_cache']);

// Initialize the asset optimizer
MLD_Asset_Optimizer::init();