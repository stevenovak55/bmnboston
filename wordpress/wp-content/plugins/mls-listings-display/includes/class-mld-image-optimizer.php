<?php
/**
 * MLD Image Optimizer
 *
 * Handles CloudFront image optimization including WebP support,
 * responsive breakpoints, and progressive loading.
 *
 * @since 4.5.58
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Image_Optimizer {

    /**
     * CloudFront domain for image transformations
     */
    private static $cloudfront_domain = '';

    /**
     * Standard responsive breakpoints for property images
     */
    private static $responsive_breakpoints = [
        'thumbnail' => 400,
        'small'     => 600,
        'medium'    => 800,
        'large'     => 1200,
        'xlarge'    => 1600
    ];

    /**
     * Initialize the image optimizer
     */
    public static function init() {
        // DISABLED: CloudFront already handles image optimization
        // The images are already optimized at the CDN level, so we don't need
        // additional optimization that could interfere with image loading
        return;

        self::$cloudfront_domain = self::detect_cloudfront_domain();

        // Add filters for image optimization
        add_filter('mld_property_image_url', [self::class, 'optimize_image_url'], 10, 3);
        add_filter('mld_property_image_tag', [self::class, 'optimize_image_tag'], 10, 4);

        // Enqueue optimization JavaScript and CSS
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_optimization_assets']);
    }

    /**
     * Detect CloudFront domain from existing image URLs
     */
    private static function detect_cloudfront_domain() {
        global $wpdb;

        // Check if the table exists first before querying
        $table_name = $wpdb->prefix . 'bme_media';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            // Table doesn't exist yet, return empty
            return '';
        }

        // The column is named 'media_url' (lowercase) in Bridge MLS Extractor Pro
        $sample_url = $wpdb->get_var("
            SELECT media_url
            FROM {$table_name}
            WHERE media_url LIKE '%.cloudfront.net%'
            LIMIT 1
        ");

        if ($sample_url && preg_match('/https?:\/\/([^\/]+\.cloudfront\.net)/', $sample_url, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Generate WebP format URL with JPEG fallback
     *
     * @param string $original_url Original image URL
     * @param int $width Target width (optional)
     * @param int $quality Image quality 1-100 (optional)
     * @return array ['webp' => url, 'jpeg' => url]
     */
    public static function get_webp_urls($original_url, $width = null, $quality = 85) {
        if (empty(self::$cloudfront_domain) || strpos($original_url, self::$cloudfront_domain) === false) {
            return [
                'webp' => $original_url,
                'jpeg' => $original_url
            ];
        }

        $params = [];
        if ($width) $params[] = "w_{$width}";
        if ($quality < 100) $params[] = "q_{$quality}";

        $param_string = !empty($params) ? '?' . implode('&', $params) : '';

        // Generate WebP version
        $webp_url = str_replace(
            ['.jpg', '.jpeg', '.png'],
            '.webp',
            $original_url
        ) . $param_string;

        // Keep original format for fallback with optimization
        $jpeg_url = $original_url . $param_string;

        return [
            'webp' => $webp_url,
            'jpeg' => $jpeg_url
        ];
    }

    /**
     * Generate responsive srcset for an image
     *
     * @param string $original_url Original image URL
     * @param string $format 'webp' or 'jpeg'
     * @return string srcset attribute value
     */
    public static function generate_srcset($original_url, $format = 'jpeg') {
        if (empty(self::$cloudfront_domain) || strpos($original_url, self::$cloudfront_domain) === false) {
            return $original_url;
        }

        $srcset = [];

        foreach (self::$responsive_breakpoints as $name => $width) {
            $urls = self::get_webp_urls($original_url, $width);
            $srcset[] = $urls[$format] . " {$width}w";
        }

        return implode(', ', $srcset);
    }

    /**
     * Generate optimized image tag with WebP support and responsive breakpoints
     *
     * @param string $original_url Original image URL
     * @param string $alt Alt text
     * @param array $options Options: width, height, loading, class, quality
     * @return string Optimized HTML img tag
     */
    public static function get_optimized_image_tag($original_url, $alt = '', $options = []) {
        // SIMPLIFIED: Return basic img tag without optimization
        // CloudFront already handles optimization, so we just need a simple img tag
        $defaults = [
            'width' => null,
            'height' => null,
            'loading' => 'lazy',
            'class' => '',
            'quality' => 85,
            'responsive' => true,
            'webp' => true
        ];

        $options = array_merge($defaults, $options);

        // Build simple img tag attributes
        $attributes = [];
        $attributes[] = 'src="' . esc_url($original_url) . '"';
        $attributes[] = 'alt="' . esc_attr($alt) . '"';

        if (!empty($options['class'])) {
            $attributes[] = 'class="' . esc_attr($options['class']) . '"';
        }

        if (!empty($options['loading'])) {
            $attributes[] = 'loading="' . esc_attr($options['loading']) . '"';
        }

        if (!empty($options['width'])) {
            $attributes[] = 'width="' . esc_attr($options['width']) . '"';
        }

        if (!empty($options['height'])) {
            $attributes[] = 'height="' . esc_attr($options['height']) . '"';
        }

        return '<img ' . implode(' ', $attributes) . '>';
    }

    /**
     * Generate blur-up placeholder data URL
     *
     * @param string $original_url Original image URL
     * @return string Base64 encoded blur placeholder
     */
    public static function get_blur_placeholder($original_url) {
        if (empty(self::$cloudfront_domain) || strpos($original_url, self::$cloudfront_domain) === false) {
            // Generate a simple gray placeholder
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg width="400" height="300" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f0f0f0"/></svg>'
            );
        }

        // Use CloudFront to generate a 20px wide blurred version
        $blur_url = str_replace('.jpg', '.jpg?w_20&blur_10', $original_url);

        // In a production environment, you might want to fetch and encode this
        // For now, return the URL directly (browsers will load it)
        return $blur_url;
    }

    /**
     * Enqueue optimization assets
     */
    public static function enqueue_optimization_assets() {
        if (!self::is_property_page()) {
            return;
        }

        // Enqueue progressive loading CSS
        wp_add_inline_style('mld-main-styles', self::get_progressive_loading_css());

        // Enqueue progressive loading JavaScript
        wp_add_inline_script('jquery', self::get_progressive_loading_js());
    }

    /**
     * Check if current page needs image optimization
     */
    private static function is_property_page() {
        global $wp_query;

        return (
            is_page() &&
            (strpos($wp_query->query_vars['pagename'] ?? '', 'property') === 0 ||
             strpos($wp_query->query_vars['pagename'] ?? '', 'search') === 0)
        );
    }

    /**
     * Progressive loading CSS
     */
    private static function get_progressive_loading_css() {
        return '
        .mld-progressive-image {
            position: relative;
            overflow: hidden;
        }

        .mld-progressive-image img {
            transition: opacity 0.3s ease;
        }

        .mld-progressive-image .mld-blur-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            filter: blur(10px);
            transform: scale(1.1);
            opacity: 1;
            transition: opacity 0.3s ease;
        }

        .mld-progressive-image.loaded .mld-blur-placeholder {
            opacity: 0;
        }

        .mld-progressive-image img[loading="lazy"] {
            opacity: 0;
        }

        .mld-progressive-image.loaded img {
            opacity: 1;
        }
        ';
    }

    /**
     * Progressive loading JavaScript
     */
    private static function get_progressive_loading_js() {
        return '
        jQuery(document).ready(function($) {
            // Intersection Observer for progressive loading
            if ("IntersectionObserver" in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            const container = img.closest(".mld-progressive-image");

                            img.onload = () => {
                                if (container) {
                                    container.classList.add("loaded");
                                }
                            };

                            if (img.complete) {
                                img.onload();
                            }

                            observer.unobserve(img);
                        }
                    });
                });

                // Observe all lazy-loaded images
                document.querySelectorAll("img[loading=lazy]").forEach(img => {
                    imageObserver.observe(img);
                });
            }
        });
        ';
    }

    /**
     * Filter for optimizing image URLs
     */
    public static function optimize_image_url($url, $width = null, $quality = 85) {
        $urls = self::get_webp_urls($url, $width, $quality);
        return $urls['jpeg']; // Return optimized JPEG as default
    }

    /**
     * Filter for optimizing image tags
     */
    public static function optimize_image_tag($tag, $url, $alt, $options = []) {
        return self::get_optimized_image_tag($url, $alt, $options);
    }
}

// Initialize the image optimizer
MLD_Image_Optimizer::init();