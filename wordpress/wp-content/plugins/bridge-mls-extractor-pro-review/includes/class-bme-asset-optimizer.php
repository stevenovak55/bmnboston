<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Asset Optimization and CDN Integration Manager
 * Version: 1.0.0 (CDN Support & Asset Optimization)
 */
class BME_Asset_Optimizer {
    
    private $cdn_config;
    private $optimization_settings;
    private $image_cache = [];
    
    public function __construct() {
        $this->cdn_config = get_option('bme_pro_cdn_config', [
            'enabled' => false,
            'provider' => 'cloudflare',
            'base_url' => '',
            'zones' => [],
            'api_key' => '',
            'purge_on_update' => true
        ]);
        
        $this->optimization_settings = get_option('bme_pro_asset_optimization', [
            'enable_image_optimization' => true,
            'enable_lazy_loading' => true,
            'enable_webp_conversion' => true,
            'compression_quality' => 85,
            'responsive_images' => true,
            'cache_optimized_images' => true
        ]);
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        if ($this->optimization_settings['enable_image_optimization']) {
            add_filter('wp_get_attachment_image_src', [$this, 'optimize_attachment_image'], 10, 4);
            add_filter('wp_calculate_image_srcset', [$this, 'optimize_srcset_images'], 10, 5);
        }
        
        if ($this->optimization_settings['enable_lazy_loading']) {
            add_filter('wp_get_attachment_image_attributes', [$this, 'add_lazy_loading'], 10, 3);
        }
        
        if ($this->cdn_config['enabled']) {
            add_filter('wp_get_attachment_url', [$this, 'cdn_rewrite_url'], 10, 2);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_cdn_assets'], 5);
        }
        
        // Asset optimization hooks
        add_action('wp_enqueue_scripts', [$this, 'optimize_loaded_assets'], 99);
        add_action('wp_head', [$this, 'add_optimization_meta'], 1);
    }
    
    /**
     * Optimize attachment images with CDN and compression
     */
    public function optimize_attachment_image($image, $attachment_id, $size, $icon) {
        if (!$image || !$this->optimization_settings['enable_image_optimization']) {
            return $image;
        }
        
        // Handle size parameter which can be a string or array
        $size_key = is_array($size) ? implode('x', $size) : $size;
        $cache_key = "optimized_image_{$attachment_id}_{$size_key}";
        
        // Check cache first
        if (isset($this->image_cache[$cache_key])) {
            return $this->image_cache[$cache_key];
        }
        
        $original_url = $image[0];
        $width = $image[1];
        $height = $image[2];
        
        // Apply optimizations
        $optimized_url = $this->apply_image_optimizations($original_url, $width, $height, $size);
        
        $optimized_image = [
            $optimized_url,
            $width,
            $height,
            $image[3] ?? true
        ];
        
        // Cache the result
        $this->image_cache[$cache_key] = $optimized_image;
        
        return $optimized_image;
    }
    
    /**
     * Apply comprehensive image optimizations
     */
    private function apply_image_optimizations($url, $width, $height, $size) {
        $optimized_url = $url;
        
        // CDN rewrite
        if ($this->cdn_config['enabled']) {
            $optimized_url = $this->rewrite_url_for_cdn($optimized_url);
        }
        
        // WebP conversion if supported
        if ($this->optimization_settings['enable_webp_conversion'] && $this->browser_supports_webp()) {
            $optimized_url = $this->convert_to_webp_url($optimized_url);
        }
        
        // Add optimization parameters
        $optimization_params = $this->build_optimization_params($width, $height, $size);
        if (!empty($optimization_params)) {
            $optimized_url = add_query_arg($optimization_params, $optimized_url);
        }
        
        return $optimized_url;
    }
    
    /**
     * Rewrite URLs for CDN delivery
     */
    public function cdn_rewrite_url($url, $attachment_id = null) {
        if (!$this->cdn_config['enabled'] || empty($this->cdn_config['base_url'])) {
            return $url;
        }
        
        $site_url = get_site_url();
        
        // Only rewrite URLs from our site
        if (strpos($url, $site_url) !== 0) {
            return $url;
        }
        
        // Replace site URL with CDN URL
        $cdn_url = str_replace($site_url, rtrim($this->cdn_config['base_url'], '/'), $url);
        
        // Log CDN rewrite for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("BME CDN: Rewrote {$url} to {$cdn_url}");
        }
        
        return $cdn_url;
    }
    
    /**
     * Add lazy loading attributes to images
     */
    public function add_lazy_loading($attr, $attachment, $size) {
        if (!$this->optimization_settings['enable_lazy_loading']) {
            return $attr;
        }
        
        // Don't lazy load if already has loading attribute
        if (isset($attr['loading'])) {
            return $attr;
        }
        
        // Add native lazy loading
        $attr['loading'] = 'lazy';
        
        // Add intersection observer fallback class
        $attr['class'] = ($attr['class'] ?? '') . ' bme-lazy-image';
        
        return $attr;
    }
    
    /**
     * Optimize responsive image srcsets
     */
    public function optimize_srcset_images($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->optimization_settings['responsive_images'] || empty($sources)) {
            return $sources;
        }
        
        foreach ($sources as $width => &$source) {
            $source['url'] = $this->apply_image_optimizations(
                $source['url'],
                $width,
                $source['descriptor'] === 'w' ? 0 : $width, // Handle width/pixel density descriptors
                'srcset'
            );
        }
        
        return $sources;
    }
    
    /**
     * Optimize loaded CSS and JS assets
     */
    public function optimize_loaded_assets() {
        global $wp_scripts, $wp_styles;
        
        // Optimize JavaScript assets
        if (is_object($wp_scripts)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if ($script->src && $this->should_optimize_asset($script->src)) {
                    $wp_scripts->registered[$handle]->src = $this->cdn_rewrite_url($script->src);
                }
            }
        }
        
        // Optimize CSS assets
        if (is_object($wp_styles)) {
            foreach ($wp_styles->registered as $handle => $style) {
                if ($style->src && $this->should_optimize_asset($style->src)) {
                    $wp_styles->registered[$handle]->src = $this->cdn_rewrite_url($style->src);
                }
            }
        }
    }
    
    /**
     * Add optimization meta tags
     */
    public function add_optimization_meta() {
        // Add resource hints for CDN
        if ($this->cdn_config['enabled'] && !empty($this->cdn_config['base_url'])) {
            $cdn_domain = parse_url($this->cdn_config['base_url'], PHP_URL_HOST);
            echo "<link rel='dns-prefetch' href='//{$cdn_domain}'>\n";
            echo "<link rel='preconnect' href='https://{$cdn_domain}' crossorigin>\n";
        }
        
        // Add optimization metadata
        echo "<meta name='bme-asset-optimization' content='enabled'>\n";
        
        if ($this->optimization_settings['enable_webp_conversion']) {
            echo "<meta name='bme-webp-support' content='auto-detect'>\n";
        }
    }
    
    /**
     * Enqueue CDN-optimized assets
     */
    public function enqueue_cdn_assets() {
        if (!$this->cdn_config['enabled']) {
            return;
        }
        
        // Enqueue lazy loading JavaScript if needed
        if ($this->optimization_settings['enable_lazy_loading']) {
            wp_enqueue_script(
                'bme-lazy-loading',
                $this->cdn_rewrite_url(BME_PLUGIN_URL . 'assets/js/lazy-loading.js'),
                [],
                BME_PRO_VERSION ?? '1.0',
                true
            );
        }
        
        // Add inline optimization script
        $optimization_config = [
            'webp_support' => $this->browser_supports_webp(),
            'lazy_loading' => $this->optimization_settings['enable_lazy_loading'],
            'cdn_base' => $this->cdn_config['base_url']
        ];
        
        wp_add_inline_script(
            'bme-lazy-loading',
            'window.BME_AssetOptimization = ' . json_encode($optimization_config) . ';',
            'before'
        );
    }
    
    /**
     * Build optimization parameters for images
     */
    private function build_optimization_params($width, $height, $size) {
        $params = [];
        
        // Add compression quality
        if ($this->optimization_settings['compression_quality'] < 100) {
            $params['q'] = $this->optimization_settings['compression_quality'];
        }
        
        // Add size optimization
        if ($width > 0 && $height > 0) {
            $params['w'] = $width;
            $params['h'] = $height;
            $params['fit'] = 'crop';
        }
        
        // Add format optimization
        if ($this->browser_supports_webp() && $this->optimization_settings['enable_webp_conversion']) {
            $params['f'] = 'webp';
        }
        
        return $params;
    }
    
    /**
     * Check if browser supports WebP
     */
    private function browser_supports_webp() {
        static $supports_webp = null;
        
        if ($supports_webp === null) {
            $accept_header = $_SERVER['HTTP_ACCEPT'] ?? '';
            $supports_webp = strpos($accept_header, 'image/webp') !== false;
        }
        
        return $supports_webp;
    }
    
    /**
     * Convert URL to WebP version
     */
    private function convert_to_webp_url($url) {
        // Simple WebP URL conversion
        // In a real implementation, this would integrate with image processing service
        if (preg_match('/\.(jpg|jpeg|png)$/i', $url)) {
            return add_query_arg(['format' => 'webp'], $url);
        }
        
        return $url;
    }
    
    /**
     * Check if asset should be optimized
     */
    private function should_optimize_asset($src) {
        // Don't optimize external assets unless specifically configured
        $site_url = get_site_url();
        return strpos($src, $site_url) === 0 || strpos($src, '//') !== 0;
    }
    
    /**
     * Purge CDN cache for specific URLs
     */
    public function purge_cdn_cache($urls = []) {
        if (!$this->cdn_config['enabled'] || !$this->cdn_config['purge_on_update']) {
            return false;
        }
        
        switch ($this->cdn_config['provider']) {
            case 'cloudflare':
                return $this->purge_cloudflare_cache($urls);
                
            case 'aws_cloudfront':
                return $this->purge_cloudfront_cache($urls);
                
            case 'custom':
                return $this->purge_custom_cdn_cache($urls);
                
            default:
                error_log("BME CDN: Unknown provider {$this->cdn_config['provider']}");
                return false;
        }
    }
    
    /**
     * Purge Cloudflare cache
     */
    private function purge_cloudflare_cache($urls) {
        if (empty($this->cdn_config['api_key']) || empty($this->cdn_config['zones'])) {
            return false;
        }
        
        foreach ($this->cdn_config['zones'] as $zone_id) {
            $api_url = "https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache";
            
            $headers = [
                'Authorization' => 'Bearer ' . $this->cdn_config['api_key'],
                'Content-Type' => 'application/json'
            ];
            
            $body = json_encode([
                'files' => empty($urls) ? ['purge_everything' => true] : $urls
            ]);
            
            $response = wp_remote_post($api_url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                error_log('BME CDN: Cloudflare purge failed - ' . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                error_log("BME CDN: Cloudflare purge failed with code {$response_code}");
                return false;
            }
        }
        
        error_log('BME CDN: Cloudflare cache purged successfully');
        return true;
    }
    
    /**
     * Purge AWS CloudFront cache (placeholder)
     */
    private function purge_cloudfront_cache($urls) {
        // Implementation would require AWS SDK
        error_log('BME CDN: CloudFront purging not implemented yet');
        return false;
    }
    
    /**
     * Purge custom CDN cache (placeholder)
     */
    private function purge_custom_cdn_cache($urls) {
        // Custom implementation based on CDN provider API
        error_log('BME CDN: Custom CDN purging not implemented yet');
        return false;
    }
    
    /**
     * Get asset optimization statistics
     */
    public function get_optimization_stats() {
        $stats = [
            'cdn_enabled' => $this->cdn_config['enabled'],
            'cdn_provider' => $this->cdn_config['provider'],
            'optimization_features' => [
                'image_optimization' => $this->optimization_settings['enable_image_optimization'],
                'lazy_loading' => $this->optimization_settings['enable_lazy_loading'],
                'webp_conversion' => $this->optimization_settings['enable_webp_conversion'],
                'responsive_images' => $this->optimization_settings['responsive_images']
            ],
            'cache_hits' => count($this->image_cache),
            'browser_webp_support' => $this->browser_supports_webp()
        ];
        
        return $stats;
    }
    
    /**
     * Update CDN configuration
     */
    public function update_cdn_config($new_config) {
        $this->cdn_config = array_merge($this->cdn_config, $new_config);
        update_option('bme_pro_cdn_config', $this->cdn_config);
        
        error_log('BME CDN: Configuration updated');
        return true;
    }
    
    /**
     * Update optimization settings
     */
    public function update_optimization_settings($new_settings) {
        $this->optimization_settings = array_merge($this->optimization_settings, $new_settings);
        update_option('bme_pro_asset_optimization', $this->optimization_settings);
        
        error_log('BME Asset Optimization: Settings updated');
        return true;
    }
}