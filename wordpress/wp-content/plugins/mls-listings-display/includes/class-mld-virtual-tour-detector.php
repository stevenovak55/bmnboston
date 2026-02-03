<?php
/**
 * Virtual Tour Type Detector
 * 
 * Detects and categorizes virtual tour URLs for appropriate embedding
 * with enhanced support for multiple tour types and seamless integration
 * in mobile gallery and property views.
 * 
 * Improvements in 4.2.0:
 * - Added native support for YouTube video embedding
 * - Improved tour type detection for Matterport, YouTube, Vimeo
 * - Enhanced iframe generation with advanced configuration options
 * - Supports multiple virtual tours in a single listing
 * 
 * @package MLS_Listings_Display
 * @since 2.4.0
 * @version 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Virtual_Tour_Detector {
    
    /**
     * Tour type constants
     */
    const TYPE_MATTERPORT = 'matterport';
    const TYPE_YOUTUBE = 'youtube';
    const TYPE_VIMEO = 'vimeo';
    const TYPE_ZILLOW = 'zillow';
    const TYPE_IFRAME = 'iframe';
    const TYPE_UNKNOWN = 'unknown';
    
    /**
     * Detect tour type from URL
     * 
     * @param string $url The tour URL
     * @return array Tour info with type, embed_url, and metadata
     */
    public static function detect_tour_type($url) {
        if (empty($url)) {
            return ['type' => self::TYPE_UNKNOWN, 'url' => ''];
        }
        
        $url = trim($url);
        $parsed = parse_url($url);
        
        if (!$parsed || !isset($parsed['host'])) {
            return ['type' => self::TYPE_UNKNOWN, 'url' => $url];
        }
        
        $host = strtolower($parsed['host']);
        
        // Matterport detection
        if (strpos($host, 'matterport.com') !== false) {
            return self::parse_matterport_url($url, $parsed);
        }
        
        // YouTube detection
        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
            return self::parse_youtube_url($url, $parsed);
        }
        
        // Vimeo detection
        if (strpos($host, 'vimeo.com') !== false) {
            return self::parse_vimeo_url($url, $parsed);
        }
        
        // Zillow detection
        if (strpos($host, 'zillow.com') !== false) {
            return self::parse_zillow_url($url, $parsed);
        }
        
        // Check if URL looks embeddable
        if (self::is_embeddable_url($url)) {
            return [
                'type' => self::TYPE_IFRAME,
                'url' => $url,
                'embed_url' => $url,
                'label' => 'Virtual Tour',
                'icon' => 'tour'
            ];
        }
        
        return ['type' => self::TYPE_UNKNOWN, 'url' => $url];
    }
    
    /**
     * Parse Matterport URL
     */
    private static function parse_matterport_url($url, $parsed) {
        $embed_url = $url;
        
        // Extract model ID if present
        if (preg_match('/\/show\/\?m=([a-zA-Z0-9]+)/', $url, $matches)) {
            $model_id = $matches[1];
            $embed_url = "https://my.matterport.com/show/?m={$model_id}&play=1&qs=1";
        }
        
        return [
            'type' => self::TYPE_MATTERPORT,
            'url' => $url,
            'embed_url' => $embed_url,
            'label' => '3D Tour',
            'icon' => '3d',
            'allow_fullscreen' => true,
            'frameborder' => '0'
        ];
    }
    
    /**
     * Parse YouTube URL
     */
    private static function parse_youtube_url($url, $parsed) {
        $video_id = '';
        
        // Handle youtube.com URLs
        if (strpos($parsed['host'], 'youtube.com') !== false) {
            parse_str($parsed['query'] ?? '', $query);
            $video_id = $query['v'] ?? '';
        }
        // Handle youtu.be URLs
        elseif (strpos($parsed['host'], 'youtu.be') !== false) {
            $video_id = trim($parsed['path'], '/');
        }
        
        if (empty($video_id)) {
            return ['type' => self::TYPE_UNKNOWN, 'url' => $url];
        }
        
        return [
            'type' => self::TYPE_YOUTUBE,
            'url' => $url,
            'embed_url' => "https://www.youtube.com/embed/{$video_id}?rel=0&modestbranding=1",
            'video_id' => $video_id,
            'label' => 'Video Tour',
            'icon' => 'video',
            'allow_fullscreen' => true,
            'frameborder' => '0'
        ];
    }
    
    /**
     * Parse Vimeo URL
     */
    private static function parse_vimeo_url($url, $parsed) {
        $video_id = '';
        
        // Extract video ID from path
        if (preg_match('/\/(\d+)/', $parsed['path'], $matches)) {
            $video_id = $matches[1];
        }
        
        if (empty($video_id)) {
            return ['type' => self::TYPE_UNKNOWN, 'url' => $url];
        }
        
        return [
            'type' => self::TYPE_VIMEO,
            'url' => $url,
            'embed_url' => "https://player.vimeo.com/video/{$video_id}?title=0&byline=0&portrait=0",
            'video_id' => $video_id,
            'label' => 'Video Tour',
            'icon' => 'video',
            'allow_fullscreen' => true,
            'frameborder' => '0'
        ];
    }
    
    /**
     * Parse Zillow URL
     */
    private static function parse_zillow_url($url, $parsed) {
        // Extract tour ID from Zillow URL
        if (preg_match('/view-imx\/([a-zA-Z0-9\-]+)/', $url, $matches)) {
            $tour_id = $matches[1];
            
            // Preserve original URL parameters
            $query_string = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            
            return [
                'type' => self::TYPE_ZILLOW,
                'url' => $url,
                'embed_url' => $url, // Zillow tours use the same URL for embedding
                'tour_id' => $tour_id,
                'label' => 'Virtual Tour',
                'icon' => 'tour',
                'allow_fullscreen' => true,
                'frameborder' => '0'
            ];
        }
        
        return ['type' => self::TYPE_UNKNOWN, 'url' => $url];
    }
    
    /**
     * Check if URL is embeddable
     */
    private static function is_embeddable_url($url) {
        // List of known embeddable domains
        $embeddable_domains = [
            'tours.',
            'virtual',
            '360',
            'tour.',
            'view.',
            'walkthrough',
            'showcase'
        ];
        
        foreach ($embeddable_domains as $domain) {
            if (stripos($url, $domain) !== false) {
                return true;
            }
        }
        
        // Check for common tour URL patterns
        if (preg_match('/\.(tour|view|showcase|360)/i', $url)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get icon SVG for tour type
     */
    public static function get_tour_icon($type) {
        switch ($type) {
            case '3d':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2l-2 6H4l5 4-2 6 5-4 5 4-2-6 5-4h-6z"/>
                </svg>';
                
            case 'video':
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>';
                
            case 'tour':
            default:
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 2a10 10 0 0 1 0 20"/>
                    <path d="M12 6v6l4 2"/>
                </svg>';
        }
    }
    
    /**
     * Generate embed code for tour
     */
    public static function generate_embed_code($tour_info, $options = []) {
        if ($tour_info['type'] === self::TYPE_UNKNOWN) {
            return '';
        }
        
        $defaults = [
            'width' => '100%',
            'height' => '100%',
            'class' => 'mld-tour-embed',
            'loading' => 'lazy'
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $attributes = [
            'src' => esc_url($tour_info['embed_url']),
            'width' => esc_attr($options['width']),
            'height' => esc_attr($options['height']),
            'class' => esc_attr($options['class']),
            'loading' => esc_attr($options['loading'])
        ];
        
        if (!empty($tour_info['allow_fullscreen'])) {
            $attributes['allowfullscreen'] = 'allowfullscreen';
        }
        
        if (isset($tour_info['frameborder'])) {
            $attributes['frameborder'] = $tour_info['frameborder'];
        }
        
        // Build iframe
        $iframe = '<iframe';
        foreach ($attributes as $key => $value) {
            $iframe .= " {$key}=\"{$value}\"";
        }
        $iframe .= '></iframe>';
        
        return $iframe;
    }
}