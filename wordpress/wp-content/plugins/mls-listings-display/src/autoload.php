<?php
/**
 * PSR-4 Autoloader for MLS Listings Display
 *
 * @package MLSDisplay
 * @since 4.8.0
 */

/**
 * Autoload function for MLSDisplay namespace
 */
function mls_display_autoload($class) {
    // Only handle classes in the MLSDisplay namespace
    if (strpos($class, 'MLSDisplay\\') !== 0) {
        return;
    }

    // Convert namespace to directory path
    $classPath = str_replace('MLSDisplay\\', '', $class);
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $classPath);

    // Construct the full file path
    $file = __DIR__ . DIRECTORY_SEPARATOR . $classPath . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require_once $file;
    }
}

// Register the autoloader
spl_autoload_register('mls_display_autoload');

/**
 * Bootstrap the MLS Display services
 */
function mls_display_bootstrap() {
    // Initialize the container
    $container = \MLSDisplay\Core\Container::getInstance();

    // Register core services
    $container->registerCoreServices();

    // Store container in global for backward compatibility
    $GLOBALS['mls_display_container'] = $container;

    return $container;
}

// Auto-bootstrap if not done already
if (!isset($GLOBALS['mls_display_container'])) {
    mls_display_bootstrap();
}