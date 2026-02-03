<?php
/**
 * Virtual Tour Utilities
 * 
 * Helper functions for virtual tour processing and security
 * 
 * @package MLS_Listings_Display
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Virtual_Tour_Utils {
    
    /**
     * Allowed domains for embedding
     */
    private static $allowed_domains = [
        'matterport.com',
        'youtube.com',
        'youtube-nocookie.com',
        'youtu.be',
        'vimeo.com',
        'player.vimeo.com',
        'zillow.com',
        'virtualtourscafe.com',
        'tourfactory.com',
        'realvision.com',
        'paradym.com',
        'visualtour.com',
        'circlepix.com',
        'realtor.com'
    ];
    
    /**
     * Process virtual tours from listing data
     * 
     * @param array $listing The listing data
     * @return array Processed tour information
     */
    public static function process_listing_tours($listing) {
        $tours = [];
        
        // Check multiple possible tour fields
        $tour_fields = [
            'virtual_tour_url_unbranded',
            'virtual_tour_url_branded',
            'virtual_tour_link_1',
            'virtual_tour_link_2',
            'virtual_tour_link_3'
        ];
        
        foreach ($tour_fields as $field) {
            if (!empty($listing[$field])) {
                $tour_info = MLD_Virtual_Tour_Detector::detect_tour_type($listing[$field]);
                
                if ($tour_info['type'] !== MLD_Virtual_Tour_Detector::TYPE_UNKNOWN) {
                    // Add source field for reference
                    $tour_info['source_field'] = $field;
                    $tours[] = $tour_info;
                }
            }
        }
        
        // Remove duplicates based on embed URL
        $unique_tours = [];
        $seen_urls = [];
        
        foreach ($tours as $tour) {
            $embed_url = $tour['embed_url'] ?? $tour['url'];
            if (!in_array($embed_url, $seen_urls)) {
                $unique_tours[] = $tour;
                $seen_urls[] = $embed_url;
            }
        }
        
        return $unique_tours;
    }
    
    /**
     * Validate tour URL for security
     * 
     * @param string $url The URL to validate
     * @return bool Whether the URL is safe to embed
     */
    public static function validate_tour_url($url) {
        if (empty($url)) {
            return false;
        }
        
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Parse URL
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }
        
        // Check against allowed domains
        $host = strtolower($parsed['host']);
        foreach (self::$allowed_domains as $allowed_domain) {
            if ($host === $allowed_domain || strpos($host, '.' . $allowed_domain) !== false) {
                return true;
            }
        }
        
        // Allow URLs that explicitly look like virtual tours
        if (self::is_likely_tour_url($url)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if URL is likely a tour based on patterns
     */
    private static function is_likely_tour_url($url) {
        $tour_patterns = [
            '/virtual[\-_]?tour/i',
            '/360[\-_]?tour/i',
            '/3d[\-_]?tour/i',
            '/walkthrough/i',
            '/showcase/i',
            '/view[\-_]?imx/i'
        ];
        
        foreach ($tour_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate tour button HTML
     * 
     * @param array $tour_info Tour information from detector
     * @param array $options Button options
     * @return string HTML for tour button
     */
    public static function generate_tour_button($tour_info, $options = []) {
        $defaults = [
            'class' => 'mld-tour-button',
            'data_attributes' => true
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        $button_class = esc_attr($options['class']);
        $icon = MLD_Virtual_Tour_Detector::get_tour_icon($tour_info['icon'] ?? 'tour');
        $label = esc_html($tour_info['label'] ?? 'Virtual Tour');
        
        $button_html = "<button class=\"{$button_class}\"";
        
        if ($options['data_attributes']) {
            $button_html .= " data-tour-type=\"" . esc_attr($tour_info['type']) . "\"";
            $button_html .= " data-tour-url=\"" . esc_url($tour_info['url']) . "\"";
            $button_html .= " data-embed-url=\"" . esc_url($tour_info['embed_url']) . "\"";
        }
        
        $button_html .= ">{$icon}<span>{$label}</span></button>";
        
        return $button_html;
    }
    
    /**
     * Get responsive iframe wrapper
     * 
     * @param string $embed_code The iframe embed code
     * @param array $options Wrapper options
     * @return string HTML with responsive wrapper
     */
    public static function get_responsive_wrapper($embed_code, $options = []) {
        $defaults = [
            'aspect_ratio' => '16:9',
            'class' => 'mld-tour-wrapper'
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // Calculate padding for aspect ratio
        $ratio_parts = explode(':', $options['aspect_ratio']);
        $padding_percent = ((float) $ratio_parts[1] / (float) $ratio_parts[0]) * 100;
        
        $wrapper_html = '<div class="' . esc_attr($options['class']) . '" style="position: relative; padding-bottom: ' . $padding_percent . '%; height: 0; overflow: hidden;">';
        $wrapper_html .= '<div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;">';
        $wrapper_html .= $embed_code;
        $wrapper_html .= '</div></div>';
        
        return $wrapper_html;
    }
    
    /**
     * Add CSP headers for embedded content
     */
    public static function add_csp_headers() {
        $allowed_sources = implode(' ', array_map(function($domain) {
            return "https://*." . $domain;
        }, self::$allowed_domains));
        
        header("Content-Security-Policy: frame-src 'self' " . $allowed_sources);
    }
    
    /**
     * Get tour type priority for sorting
     * 
     * @param string $type Tour type
     * @return int Priority (lower = higher priority)
     */
    public static function get_tour_priority($type) {
        $priorities = [
            MLD_Virtual_Tour_Detector::TYPE_MATTERPORT => 1,
            MLD_Virtual_Tour_Detector::TYPE_YOUTUBE => 2,
            MLD_Virtual_Tour_Detector::TYPE_VIMEO => 3,
            MLD_Virtual_Tour_Detector::TYPE_ZILLOW => 4,
            MLD_Virtual_Tour_Detector::TYPE_IFRAME => 5,
            MLD_Virtual_Tour_Detector::TYPE_UNKNOWN => 99
        ];
        
        return $priorities[$type] ?? 99;
    }
    
    /**
     * Sort tours by priority and type
     * 
     * @param array $tours Array of tour info
     * @return array Sorted tours
     */
    public static function sort_tours($tours) {
        usort($tours, function($a, $b) {
            $priority_a = self::get_tour_priority($a['type']);
            $priority_b = self::get_tour_priority($b['type']);
            
            if ($priority_a === $priority_b) {
                // If same type, maintain original order
                return 0;
            }
            
            return $priority_a - $priority_b;
        });
        
        return $tours;
    }
}