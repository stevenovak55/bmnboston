<?php
/**
 * Lightweight dependency injection container for Bridge MLS Extractor Pro
 *
 * @package BridgeMLS\Core
 * @since 1.0.0
 */

namespace BridgeMLS\Core;

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
            'BridgeMLS\Repositories\ListingRepository',
            'BridgeMLS\Repositories\ListingRepository'
        );

        $this->register(
            'BridgeMLS\Repositories\PhotoRepository',
            'BridgeMLS\Repositories\PhotoRepository'
        );

        $this->register(
            'BridgeMLS\Repositories\AgentRepository',
            'BridgeMLS\Repositories\AgentRepository'
        );

        // Services
        $this->register(
            'BridgeMLS\Services\ExtractionService',
            'BridgeMLS\Services\ExtractionService'
        );

        $this->register(
            'BridgeMLS\Services\DataProcessingService',
            'BridgeMLS\Services\DataProcessingService'
        );

        $this->register(
            'BridgeMLS\Services\SyncService',
            'BridgeMLS\Services\SyncService'
        );

        // NOTE: AdminService and DatabaseService removed in v4.0.17 (dead code - never instantiated)
        // All admin functionality handled by BME_Admin class in includes/

        // Extraction Engine
        $this->register(
            'BridgeMLS\Contracts\ExtractionEngineInterface',
            function($container) {
                return new \BridgeMLS\Services\MLSExtractionEngine();
            }
        );
    }
}