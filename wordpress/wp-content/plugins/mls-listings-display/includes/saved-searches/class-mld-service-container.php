<?php
/**
 * MLS Service Container
 * 
 * Dependency injection container for service layer
 * 
 * @package MLS_Listings_Display
 * @subpackage Saved_Searches
 * @since 3.3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service Container Class
 * 
 * Simple dependency injection container for managing service instances
 */
class MLD_Service_Container {
    
    /**
     * Container instance
     * 
     * @var MLD_Service_Container
     */
    private static $instance = null;
    
    /**
     * Services array
     * 
     * @var array
     */
    private $services = [];
    
    /**
     * Factories array
     * 
     * @var array
     */
    private $factories = [];
    
    /**
     * Get container instance
     * 
     * @return MLD_Service_Container
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->register_services();
        }
        
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        // Private constructor to enforce singleton
    }
    
    /**
     * Register services
     */
    private function register_services() {
        // Register repositories
        $this->register('search_repository', function() {
            return new MLD_Search_Repository();
        });
        
        $this->register('notification_repository', function() {
            return new MLD_Notification_Repository();
        });
        
        $this->register('property_preferences_repository', function() {
            return new MLD_Property_Preferences_Repository();
        });
        
        // Register services
        $this->register('email_service', function() {
            return new MLD_Email_Service();
        });
        
        $this->register('agent_manager', function() {
            return MLD_Agent_Client_Manager::get_instance();
        });
        
        $this->register('notification_service', function($container) {
            return new MLD_Notification_Service(
                $container->get('notification_repository'),
                $container->get('email_service'),
                $container->get('agent_manager')
            );
        });
        
        $this->register('search_service', function($container) {
            return new MLD_Search_Service(
                $container->get('search_repository'),
                $container->get('notification_service')
            );
        });
        
        $this->register('user_service', function($container) {
            return new MLD_User_Service(
                $container->get('property_preferences_repository')
            );
        });
    }
    
    /**
     * Register a service
     * 
     * @param string $name Service name
     * @param callable $factory Factory function
     * @param bool $singleton Whether to create as singleton
     */
    public function register($name, $factory, $singleton = true) {
        $this->factories[$name] = [
            'factory' => $factory,
            'singleton' => $singleton
        ];
    }
    
    /**
     * Get a service
     * 
     * @param string $name Service name
     * @return mixed Service instance
     * @throws Exception If service not found
     */
    public function get($name) {
        // Check if already instantiated (singleton)
        if (isset($this->services[$name])) {
            return $this->services[$name];
        }
        
        // Check if factory exists
        if (!isset($this->factories[$name])) {
            throw new Exception("Service '{$name}' not found in container");
        }
        
        $factory_data = $this->factories[$name];
        $factory = $factory_data['factory'];
        
        // Create instance
        $instance = $factory($this);
        
        // Store if singleton
        if ($factory_data['singleton']) {
            $this->services[$name] = $instance;
        }
        
        return $instance;
    }
    
    /**
     * Check if service exists
     * 
     * @param string $name Service name
     * @return bool
     */
    public function has($name) {
        return isset($this->factories[$name]);
    }
    
    /**
     * Get search service
     * 
     * @return MLD_Search_Service
     */
    public function search_service() {
        return $this->get('search_service');
    }
    
    /**
     * Get notification service
     * 
     * @return MLD_Notification_Service
     */
    public function notification_service() {
        return $this->get('notification_service');
    }
    
    /**
     * Get user service
     * 
     * @return MLD_User_Service
     */
    public function user_service() {
        return $this->get('user_service');
    }
    
    /**
     * Get email service
     * 
     * @return MLD_Email_Service
     */
    public function email_service() {
        return $this->get('email_service');
    }
    
    /**
     * Get search repository
     * 
     * @return MLD_Search_Repository
     */
    public function search_repository() {
        return $this->get('search_repository');
    }
    
    /**
     * Get notification repository
     * 
     * @return MLD_Notification_Repository
     */
    public function notification_repository() {
        return $this->get('notification_repository');
    }
    
    /**
     * Get property preferences repository
     * 
     * @return MLD_Property_Preferences_Repository
     */
    public function property_preferences_repository() {
        return $this->get('property_preferences_repository');
    }
}