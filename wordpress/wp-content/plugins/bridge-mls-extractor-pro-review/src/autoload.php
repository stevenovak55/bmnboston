<?php
/**
 * PSR-4 Autoloader for Bridge MLS Extractor Pro
 *
 * @package BridgeMLS
 * @since 1.0.0
 */

/**
 * Autoload function for BridgeMLS namespace
 */
function bridge_mls_autoload($class) {
    // Only handle classes in the BridgeMLS namespace
    if (strpos($class, 'BridgeMLS\\') !== 0) {
        return;
    }

    // Convert namespace to directory path
    $classPath = str_replace('BridgeMLS\\', '', $class);
    $classPath = str_replace('\\', DIRECTORY_SEPARATOR, $classPath);

    // Construct the full file path
    $file = __DIR__ . DIRECTORY_SEPARATOR . $classPath . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require_once $file;
    }
}

// Register the autoloader
spl_autoload_register('bridge_mls_autoload');

/**
 * Bootstrap the Bridge MLS services
 */
function bridge_mls_bootstrap() {
    // Initialize the container
    $container = \BridgeMLS\Core\Container::getInstance();

    // Register core services
    $container->registerCoreServices();

    // Store container in global for backward compatibility
    $GLOBALS["bridge_mls_container"] = $container;

    // Register extraction job handler
    add_action('bme_execute_extraction', 'bridge_mls_handle_extraction_job', 10, 2);

    return $container;
}

/**
 * Handle extraction job
 */
function bridge_mls_handle_extraction_job($filters = array(), $limit = 1000) {
    try {
        $container = \BridgeMLS\Core\Container::getInstance();
        $extractionService = $container->resolve('BridgeMLS\Services\ExtractionService');
        $extractionService->executeExtraction($filters, $limit);
    } catch (Exception $e) {
        error_log('Bridge MLS Extraction Job Error: ' . $e->getMessage());
    }
}

// Auto-bootstrap if not done already
if (!isset($GLOBALS['bridge_mls_container'])) {
    bridge_mls_bootstrap();
}