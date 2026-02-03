<?php
/**
 * Lightweight dependency injection container for MLS Listings Display
 *
 * @package MLSDisplay\Core
 * @since 4.8.0
 */

namespace MLSDisplay\Core;

use Exception;

/**
 * Service Container for dependency injection
 */
class Container {

    /**
     * Registered services
     * @var array
     */
    private array $services = [];

    /**
     * Service instances (singletons)
     * @var array
     */
    private array $instances = [];

    /**
     * Singleton instance
     * @var Container|null
     */
    private static ?Container $instance = null;

    /**
     * Get container instance
     */
    public static function getInstance(): Container {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a service
     */
    public function register(string $abstract, callable|string $concrete, bool $singleton = true): void {
        $this->services[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton
        ];
    }

    /**
     * Resolve a service from the container
     */
    public function resolve(string $abstract): mixed {
        if (!isset($this->services[$abstract])) {
            throw new Exception("Service {$abstract} not found in container");
        }

        $service = $this->services[$abstract];

        // Return singleton instance if already created
        if ($service['singleton'] && isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Create new instance
        $instance = $this->createInstance($service['concrete']);

        // Store singleton
        if ($service['singleton']) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Create instance from concrete definition
     */
    private function createInstance(callable|string $concrete): mixed {
        if (is_callable($concrete)) {
            return $concrete($this);
        }

        if (is_string($concrete) && class_exists($concrete)) {
            return new $concrete();
        }

        throw new Exception("Unable to resolve concrete: " . print_r($concrete, true));
    }

    /**
     * Check if service is registered
     */
    public function has(string $abstract): bool {
        return isset($this->services[$abstract]);
    }

    /**
     * Register all core services
     */
    public function registerCoreServices(): void {
        // Repositories
        $this->register(
            'MLSDisplay\Contracts\RepositoryInterface',
            'MLSDisplay\Repositories\ListingRepository'
        );

        $this->register(
            'MLSDisplay\Repositories\SavedSearchRepository',
            'MLSDisplay\Repositories\SavedSearchRepository'
        );

        // Services
        $this->register(
            'MLSDisplay\Services\QueryService',
            'MLSDisplay\Services\QueryService'
        );

        $this->register(
            'MLSDisplay\Services\ListingService',
            'MLSDisplay\Services\ListingService'
        );

        $this->register(
            'MLSDisplay\Services\SearchService',
            'MLSDisplay\Services\SearchService'
        );

        $this->register(
            'MLSDisplay\Services\MapService',
            'MLSDisplay\Services\MapService'
        );

        // Data Providers
        $this->register(
            'MLSDisplay\Contracts\DataProviderInterface',
            function($container) {
                return new \MLSDisplay\Services\BridgeMLSDataProvider();
            }
        );
    }
}