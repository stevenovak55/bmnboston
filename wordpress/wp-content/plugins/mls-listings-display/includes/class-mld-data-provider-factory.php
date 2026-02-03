<?php
/**
 * MLS Data Provider Factory
 * 
 * Factory class for creating and managing data provider instances
 * This allows the Display plugin to work with different data sources
 *
 * @package MLS_Listings_Display
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Require the interface file if not already loaded
if (!interface_exists('MLD_Data_Provider_Interface')) {
    require_once dirname(__FILE__) . '/interface-mld-data-provider.php';
}

/**
 * Data Provider Factory class
 */
class MLD_Data_Provider_Factory {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Current data provider instance
     */
    private $provider = null;
    
    /**
     * Available providers
     */
    private $providers = [];
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->register_providers();
        $this->init_provider();
    }
    
    /**
     * Register available data providers
     */
    private function register_providers() {
        // Register BME provider
        $this->providers['bme'] = [
            'class' => 'MLD_BME_Data_Provider',
            'name' => 'Bridge MLS Extractor Pro',
            'priority' => 10
        ];
        
        // Allow other plugins to register providers
        $this->providers = apply_filters('mld_register_data_providers', $this->providers);
        
        // Sort by priority
        uasort($this->providers, function($a, $b) {
            return ($a['priority'] ?? 10) - ($b['priority'] ?? 10);
        });
    }
    
    /**
     * Initialize the data provider
     */
    private function init_provider() {
        // Try each provider in priority order
        foreach ($this->providers as $key => $provider_info) {
            $class_name = $provider_info['class'];
            
            // Check if class exists
            if (!class_exists($class_name)) {
                // Try to load the class file
                $file_path = MLD_PLUGIN_PATH . 'includes/class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
                if (file_exists($file_path)) {
                    require_once $file_path;
                }
            }
            
            // Check again after loading
            if (class_exists($class_name)) {
                try {
                    $provider = new $class_name();
                    
                    // Check if provider is available
                    if ($provider instanceof MLD_Data_Provider_Interface && $provider->is_available()) {
                        $this->provider = $provider;
                        MLD_Logger::info("Data provider initialized: {$provider_info['name']}");
                        break;
                    }
                } catch (Exception $e) {
                    MLD_Logger::error("Failed to initialize provider {$class_name}: " . $e->getMessage());
                }
            }
        }
        
        // If no provider is available, use a null provider
        if (!$this->provider) {
            $this->provider = new MLD_Null_Data_Provider();
            MLD_Logger::warning('No data provider available, using null provider');
        }
    }
    
    /**
     * Get the current data provider
     * 
     * @return MLD_Data_Provider_Interface
     */
    public function get_provider() {
        if (!$this->provider) {
            $this->init_provider();
        }
        return $this->provider;
    }
    
    /**
     * Set a specific provider
     * 
     * @param MLD_Data_Provider_Interface $provider
     */
    public function set_provider(MLD_Data_Provider_Interface $provider) {
        $this->provider = $provider;
    }
    
    /**
     * Get available providers
     * 
     * @return array
     */
    public function get_available_providers() {
        $available = [];
        
        foreach ($this->providers as $key => $provider_info) {
            $class_name = $provider_info['class'];
            
            if (class_exists($class_name)) {
                try {
                    $provider = new $class_name();
                    if ($provider instanceof MLD_Data_Provider_Interface && $provider->is_available()) {
                        $available[$key] = $provider_info;
                    }
                } catch (Exception $e) {
                    // Provider not available
                }
            }
        }
        
        return $available;
    }
    
    /**
     * Check if any provider is available
     * 
     * @return bool
     */
    public function has_provider() {
        return $this->provider && !($this->provider instanceof MLD_Null_Data_Provider);
    }
}

/**
 * Null Data Provider
 * 
 * Used when no real data provider is available
 */
class MLD_Null_Data_Provider implements MLD_Data_Provider_Interface {
    
    public function get_tables() {
        return [];
    }
    
    public function get_listings($filters = [], $limit = 20, $offset = 0) {
        return [];
    }
    
    public function get_listing($listing_id) {
        return null;
    }
    
    public function get_listing_count($filters = []) {
        return 0;
    }
    
    public function get_distinct_values($field, $filters = []) {
        return [];
    }
    
    public function search_listings($keyword, $filters = [], $limit = 20) {
        return [];
    }
    
    public function get_listing_media($listing_id) {
        return [];
    }
    
    public function is_available() {
        return false;
    }
    
    public function get_version() {
        return '0.0.0';
    }
}

/**
 * Helper function to get the data provider
 * 
 * @return MLD_Data_Provider_Interface
 */
function mld_get_data_provider() {
    return MLD_Data_Provider_Factory::get_instance()->get_provider();
}