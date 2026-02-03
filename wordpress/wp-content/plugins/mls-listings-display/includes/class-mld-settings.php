<?php
/**
 * MLD Settings Class
 * Manages plugin settings including API keys
 *
 * @package MLS_Listings_Display
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Settings {
    
    /**
     * Option name for storing settings
     */
    const OPTION_NAME = 'mld_settings';
    
    /**
     * Default settings
     */
    private static $defaults = array(
        'mld_google_maps_api_key' => '',
        'mld_walk_score_api_key' => '',
        // Mapbox removed for performance optimization - Google Maps only
        'enable_walk_score' => true,
        'enable_school_ratings' => true,
        'enable_climate_risk' => true,
        'enable_market_insights' => true,
        'use_v2_templates' => false,
        'cache_duration' => 3600,
        'debug_mode' => false
    );
    
    /**
     * Get all settings
     *
     * @return array
     */
    public static function get_all() {
        $settings = get_option(self::OPTION_NAME, array());
        return wp_parse_args($settings, self::$defaults);
    }
    
    /**
     * Get a specific setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null) {
        $settings = self::get_all();
        
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        
        if ($default !== null) {
            return $default;
        }
        
        return isset(self::$defaults[$key]) ? self::$defaults[$key] : null;
    }
    
    /**
     * Update a setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool
     */
    public static function update($key, $value) {
        $settings = self::get_all();
        $settings[$key] = $value;
        return update_option(self::OPTION_NAME, $settings);
    }
    
    /**
     * Update multiple settings
     *
     * @param array $new_settings Array of settings to update
     * @return bool
     */
    public static function update_multiple($new_settings) {
        $settings = self::get_all();
        $settings = array_merge($settings, $new_settings);
        return update_option(self::OPTION_NAME, $settings);
    }
    
    /**
     * Delete a setting
     *
     * @param string $key Setting key
     * @return bool
     */
    public static function delete($key) {
        $settings = self::get_all();
        unset($settings[$key]);
        return update_option(self::OPTION_NAME, $settings);
    }
    
    /**
     * Reset all settings to defaults
     *
     * @return bool
     */
    public static function reset() {
        return update_option(self::OPTION_NAME, self::$defaults);
    }
    
    /**
     * Get Walk Score API key
     *
     * @return string
     */
    public static function get_walk_score_api_key() {
        return self::get('mld_walk_score_api_key', '');
    }
    
    /**
     * Get Google Maps API key
     *
     * @return string
     */
    public static function get_google_maps_api_key() {
        return self::get('mld_google_maps_api_key', '');
    }
    
    /**
     * Get map provider (always Google Maps now)
     *
     * @return string Always returns 'google'
     */
    public static function get_map_provider() {
        return 'google'; // Mapbox removed for performance optimization
    }
    
    /**
     * Check if Walk Score is enabled
     *
     * @return bool
     */
    public static function is_walk_score_enabled() {
        $api_key = self::get_walk_score_api_key();
        return !empty($api_key);
    }
    
    /**
     * Check if V2 templates are enabled
     *
     * @return bool
     */
    public static function use_v2_templates() {
        return self::get('use_v2_templates', false);
    }
    
    /**
     * Localize settings for JavaScript
     *
     * @return array
     */
    public static function get_js_settings() {
        $settings = array(
            'walkScoreApiKey' => self::get_walk_score_api_key(),
            'googleMapsApiKey' => self::get_google_maps_api_key(),
            'mapProvider' => self::get_map_provider(), // Always 'google' now
            'enableWalkScore' => self::is_walk_score_enabled(),
            'enableSchoolRatings' => self::get('enable_school_ratings', true),
            'enableClimateRisk' => self::get('enable_climate_risk', true),
            'debugMode' => self::get('debug_mode', false)
        );
        
        // Debug output
        if (self::get('debug_mode', false)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('MLD Settings: ' . print_r($settings, true));
            }
        }
        
        return $settings;
    }
}