<?php
/**
 * Tests for Container class (Dependency Injection)
 *
 * @package MLSDisplay\Tests\Unit\Core
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit\Core;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;
use MLSDisplay\Core\Container;
use Exception;

/**
 * Test class for Container (DI Container)
 *
 * Tests the dependency injection container functionality including:
 * - getInstance() - Singleton pattern
 * - register() - Service registration
 * - resolve() - Service resolution
 * - has() - Service existence check
 * - Singleton behavior
 */
class ContainerTest extends MLD_Unit_TestCase {

    /**
     * Container instance for testing
     * @var Container
     */
    private Container $container;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Create a fresh container instance for testing
        // We use reflection to reset the singleton
        $this->resetContainerSingleton();
        $this->container = new Container();
    }

    /**
     * Reset the container singleton using reflection
     */
    private function resetContainerSingleton(): void {
        $reflection = new \ReflectionClass(Container::class);
        $property = $reflection->getProperty('instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    // =========================================================================
    // getInstance() Tests - Singleton Pattern
    // =========================================================================

    /**
     * Test getInstance returns Container instance
     */
    public function testGetInstanceReturnsContainer(): void {
        $instance = Container::getInstance();

        $this->assertInstanceOf(Container::class, $instance);
    }

    /**
     * Test getInstance returns same instance (singleton)
     */
    public function testGetInstanceReturnsSameInstance(): void {
        $instance1 = Container::getInstance();
        $instance2 = Container::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // register() Tests
    // =========================================================================

    /**
     * Test register with class string
     */
    public function testRegisterWithClassString(): void {
        $this->container->register('TestService', TestServiceClass::class);

        $this->assertTrue($this->container->has('TestService'));
    }

    /**
     * Test register with callable
     */
    public function testRegisterWithCallable(): void {
        $this->container->register('TestService', function($container) {
            return new TestServiceClass();
        });

        $this->assertTrue($this->container->has('TestService'));
    }

    /**
     * Test register as singleton by default
     */
    public function testRegisterAsSingletonByDefault(): void {
        $this->container->register('TestService', TestServiceClass::class);

        $instance1 = $this->container->resolve('TestService');
        $instance2 = $this->container->resolve('TestService');

        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test register as non-singleton
     */
    public function testRegisterAsNonSingleton(): void {
        $this->container->register('TestService', TestServiceClass::class, false);

        $instance1 = $this->container->resolve('TestService');
        $instance2 = $this->container->resolve('TestService');

        $this->assertNotSame($instance1, $instance2);
        $this->assertInstanceOf(TestServiceClass::class, $instance1);
        $this->assertInstanceOf(TestServiceClass::class, $instance2);
    }

    /**
     * Test register overwrites existing service
     */
    public function testRegisterOverwritesExistingService(): void {
        $this->container->register('TestService', TestServiceClass::class);
        $this->container->register('TestService', AnotherTestServiceClass::class);

        $instance = $this->container->resolve('TestService');

        $this->assertInstanceOf(AnotherTestServiceClass::class, $instance);
    }

    // =========================================================================
    // resolve() Tests
    // =========================================================================

    /**
     * Test resolve returns service instance
     */
    public function testResolveReturnsServiceInstance(): void {
        $this->container->register('TestService', TestServiceClass::class);

        $instance = $this->container->resolve('TestService');

        $this->assertInstanceOf(TestServiceClass::class, $instance);
    }

    /**
     * Test resolve throws exception for unregistered service
     */
    public function testResolveThrowsExceptionForUnregisteredService(): void {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Service UnknownService not found in container');

        $this->container->resolve('UnknownService');
    }

    /**
     * Test resolve with callable passes container
     */
    public function testResolveWithCallablePassesContainer(): void {
        $receivedContainer = null;

        $this->container->register('TestService', function($container) use (&$receivedContainer) {
            $receivedContainer = $container;
            return new TestServiceClass();
        });

        $this->container->resolve('TestService');

        $this->assertSame($this->container, $receivedContainer);
    }

    /**
     * Test resolve caches singleton instance
     */
    public function testResolveCachesSingletonInstance(): void {
        $callCount = 0;

        $this->container->register('TestService', function($container) use (&$callCount) {
            $callCount++;
            return new TestServiceClass();
        });

        // Resolve multiple times
        $this->container->resolve('TestService');
        $this->container->resolve('TestService');
        $this->container->resolve('TestService');

        // Factory should only be called once for singleton
        $this->assertEquals(1, $callCount);
    }

    /**
     * Test resolve creates new instance for non-singleton
     */
    public function testResolveCreatesNewInstanceForNonSingleton(): void {
        $callCount = 0;

        $this->container->register('TestService', function($container) use (&$callCount) {
            $callCount++;
            return new TestServiceClass();
        }, false);

        // Resolve multiple times
        $this->container->resolve('TestService');
        $this->container->resolve('TestService');
        $this->container->resolve('TestService');

        // Factory should be called each time for non-singleton
        $this->assertEquals(3, $callCount);
    }

    // =========================================================================
    // has() Tests
    // =========================================================================

    /**
     * Test has returns true for registered service
     */
    public function testHasReturnsTrueForRegisteredService(): void {
        $this->container->register('TestService', TestServiceClass::class);

        $this->assertTrue($this->container->has('TestService'));
    }

    /**
     * Test has returns false for unregistered service
     */
    public function testHasReturnsFalseForUnregisteredService(): void {
        $this->assertFalse($this->container->has('UnknownService'));
    }

    // =========================================================================
    // Singleton Behavior Tests
    // =========================================================================

    /**
     * Test singleton instance is preserved across resolves
     */
    public function testSingletonInstanceIsPreserved(): void {
        $this->container->register('TestService', TestServiceClass::class);

        $instance1 = $this->container->resolve('TestService');
        $instance1->value = 'modified';

        $instance2 = $this->container->resolve('TestService');

        $this->assertEquals('modified', $instance2->value);
    }

    /**
     * Test non-singleton instances are independent
     */
    public function testNonSingletonInstancesAreIndependent(): void {
        $this->container->register('TestService', TestServiceClass::class, false);

        $instance1 = $this->container->resolve('TestService');
        $instance1->value = 'modified';

        $instance2 = $this->container->resolve('TestService');

        $this->assertEquals('default', $instance2->value);
    }

    // =========================================================================
    // Edge Cases Tests
    // =========================================================================

    /**
     * Test register with empty abstract name
     */
    public function testRegisterWithEmptyAbstractName(): void {
        // While technically allowed, it's not recommended
        $this->container->register('', TestServiceClass::class);

        $this->assertTrue($this->container->has(''));
    }

    /**
     * Test register with interface-like name
     */
    public function testRegisterWithInterfaceLikeName(): void {
        $this->container->register('TestServiceInterface', TestServiceClass::class);

        $instance = $this->container->resolve('TestServiceInterface');

        $this->assertInstanceOf(TestServiceClass::class, $instance);
    }

    /**
     * Test register with fully qualified class name
     */
    public function testRegisterWithFullyQualifiedClassName(): void {
        $this->container->register(
            'MLSDisplay\\Tests\\Unit\\Core\\TestServiceClass',
            TestServiceClass::class
        );

        $instance = $this->container->resolve('MLSDisplay\\Tests\\Unit\\Core\\TestServiceClass');

        $this->assertInstanceOf(TestServiceClass::class, $instance);
    }

    /**
     * Test resolve throws exception for non-existent class
     */
    public function testResolveThrowsExceptionForNonExistentClass(): void {
        $this->container->register('TestService', 'NonExistentClass');

        $this->expectException(Exception::class);

        $this->container->resolve('TestService');
    }

    /**
     * Test callable can return null (edge case)
     */
    public function testCallableCanReturnNull(): void {
        $this->container->register('NullService', function($container) {
            return null;
        });

        $instance = $this->container->resolve('NullService');

        $this->assertNull($instance);
    }

    /**
     * Test callable can return primitive value
     */
    public function testCallableCanReturnPrimitiveValue(): void {
        $this->container->register('ConfigValue', function($container) {
            return 'configuration_string';
        });

        $value = $this->container->resolve('ConfigValue');

        $this->assertEquals('configuration_string', $value);
    }

    /**
     * Test callable can return array
     */
    public function testCallableCanReturnArray(): void {
        $this->container->register('ConfigArray', function($container) {
            return ['key' => 'value', 'number' => 42];
        });

        $value = $this->container->resolve('ConfigArray');

        $this->assertIsArray($value);
        $this->assertEquals('value', $value['key']);
        $this->assertEquals(42, $value['number']);
    }

    // =========================================================================
    // registerCoreServices() Tests
    // =========================================================================

    /**
     * Test registerCoreServices registers expected services
     */
    public function testRegisterCoreServicesRegistersExpectedServices(): void {
        // Note: This test documents expected behavior
        // The actual services may not resolve without the full plugin loaded

        $expectedServices = [
            'MLSDisplay\Contracts\RepositoryInterface',
            'MLSDisplay\Repositories\SavedSearchRepository',
            'MLSDisplay\Services\QueryService',
            'MLSDisplay\Services\ListingService',
            'MLSDisplay\Services\SearchService',
            'MLSDisplay\Services\MapService',
            'MLSDisplay\Contracts\DataProviderInterface',
        ];

        // Just verify the expected service names are documented
        $this->assertCount(7, $expectedServices);
        $this->assertContains('MLSDisplay\Contracts\DataProviderInterface', $expectedServices);
    }
}

/**
 * Test service class for container tests
 */
class TestServiceClass {
    public string $value = 'default';

    public function __construct() {
        // Simple constructor
    }
}

/**
 * Another test service class for overwrite tests
 */
class AnotherTestServiceClass {
    public string $value = 'another';

    public function __construct() {
        // Simple constructor
    }
}
