<?php
/**
 * Fix Facebook crawler access
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Facebook_Fix {
    
    public function __construct() {
        // Allow Facebook crawler
        add_action('init', array($this, 'allow_facebook_crawler'), 1);
        
        // Add filter to modify security plugin behavior
        add_filter('wordfence_should_block_request', array($this, 'whitelist_facebook'), 10, 2);
        add_filter('sucuri_should_block_request', array($this, 'whitelist_facebook'), 10, 2);
        
        // Headers to allow social media crawlers
        add_action('send_headers', array($this, 'send_crawler_headers'), 1);
    }
    
    /**
     * Check if this is a social media crawler
     */
    private function is_social_crawler() {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        // Facebook crawlers
        if (strpos($user_agent, 'facebookexternalhit') !== false ||
            strpos($user_agent, 'Facebot') !== false) {
            return true;
        }
        
        // LinkedIn
        if (strpos($user_agent, 'LinkedInBot') !== false) {
            return true;
        }
        
        // Twitter
        if (strpos($user_agent, 'Twitterbot') !== false) {
            return true;
        }
        
        // WhatsApp
        if (strpos($user_agent, 'WhatsApp') !== false) {
            return true;
        }
        
        // Telegram
        if (strpos($user_agent, 'TelegramBot') !== false) {
            return true;
        }
        
        // Pinterest
        if (strpos($user_agent, 'Pinterest') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Allow Facebook crawler access
     */
    public function allow_facebook_crawler() {
        // Only on property pages
        if (get_query_var('mls_number', false) === false) {
            return;
        }
        
        if ($this->is_social_crawler()) {
            // Remove any security blocks for social crawlers
            remove_all_actions('template_redirect', 5);
            
            // Ensure proper headers
            if (!headers_sent()) {
                header('X-Robots-Tag: all');
                header('Cache-Control: public, max-age=3600');
            }
        }
    }
    
    /**
     * Whitelist Facebook in Wordfence
     */
    public function whitelist_facebook($should_block, $request) {
        if ($this->is_social_crawler()) {
            return false; // Don't block
        }
        return $should_block;
    }
    
    /**
     * Send proper headers for crawlers
     */
    public function send_crawler_headers() {
        // Only on property pages
        if (get_query_var('mls_number', false) === false) {
            return;
        }
        
        if ($this->is_social_crawler()) {
            // Ensure we send 200 OK
            status_header(200);
            
            // Remove any security headers that might block
            header_remove('X-Frame-Options');
            header_remove('X-Content-Type-Options');
            
            // Add crawler-friendly headers
            header('X-Robots-Tag: all');
            header('Cache-Control: public, max-age=3600');
        }
    }
}