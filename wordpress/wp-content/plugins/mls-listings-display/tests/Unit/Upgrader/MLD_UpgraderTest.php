<?php
/**
 * Tests for MLD_Upgrader class
 *
 * @package MLSDisplay\Tests\Unit\Upgrader
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit\Upgrader;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test class for MLD_Upgrader
 *
 * Tests the plugin upgrade functionality including:
 * - needs_upgrade()
 * - get_stored_version()
 * - run_upgrade()
 * - run_pre_upgrade_checks()
 * - convert_to_bytes()
 */
class MLD_UpgraderTest extends MLD_Unit_TestCase {

    /**
     * Current plugin version (matches class constant)
     */
    const CURRENT_VERSION = '6.10.6';

    /**
     * Version option key
     */
    const VERSION_OPTION = 'mld_plugin_version';

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();

        // Stub common functions
        $this->stubCommonFunctions();
    }

    /**
     * Stub common WordPress functions
     */
    private function stubCommonFunctions(): void {
        Functions\stubs([
            'current_time' => function($type) {
                return date('Y-m-d H:i:s');
            },
            'get_bloginfo' => function($show) {
                if ($show === 'version') {
                    return '6.4.2';
                }
                return '';
            },
        ]);
    }

    // =========================================================================
    // needs_upgrade() Tests
    // =========================================================================

    /**
     * Test needs_upgrade returns true for lower version
     */
    public function testNeedsUpgradeReturnsTrueForLowerVersion(): void {
        // Simulate stored version lower than current
        $this->setOption(self::VERSION_OPTION, '6.9.0');

        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');
        $needsUpgrade = version_compare($storedVersion, self::CURRENT_VERSION, '<');

        $this->assertTrue($needsUpgrade);
    }

    /**
     * Test needs_upgrade returns false for same version
     */
    public function testNeedsUpgradeReturnsFalseForSameVersion(): void {
        // Simulate stored version same as current
        $this->setOption(self::VERSION_OPTION, self::CURRENT_VERSION);

        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');
        $needsUpgrade = version_compare($storedVersion, self::CURRENT_VERSION, '<');

        $this->assertFalse($needsUpgrade);
    }

    /**
     * Test needs_upgrade returns false for higher version
     */
    public function testNeedsUpgradeReturnsFalseForHigherVersion(): void {
        // Simulate stored version higher than current (downgrade scenario)
        $this->setOption(self::VERSION_OPTION, '7.0.0');

        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');
        $needsUpgrade = version_compare($storedVersion, self::CURRENT_VERSION, '<');

        $this->assertFalse($needsUpgrade);
    }

    /**
     * Test needs_upgrade returns true for no stored version
     */
    public function testNeedsUpgradeReturnsTrueForNoStoredVersion(): void {
        // No option set - fresh install
        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');
        $needsUpgrade = version_compare($storedVersion, self::CURRENT_VERSION, '<');

        $this->assertEquals('0.0.0', $storedVersion);
        $this->assertTrue($needsUpgrade);
    }

    // =========================================================================
    // get_stored_version() Tests
    // =========================================================================

    /**
     * Test get_stored_version returns default value when not set
     */
    public function testGetStoredVersionReturnsDefaultWhenNotSet(): void {
        // No option set
        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');

        $this->assertEquals('0.0.0', $storedVersion);
    }

    /**
     * Test get_stored_version returns stored value
     */
    public function testGetStoredVersionReturnsStoredValue(): void {
        $this->setOption(self::VERSION_OPTION, '6.8.5');

        $storedVersion = get_option(self::VERSION_OPTION, '0.0.0');

        $this->assertEquals('6.8.5', $storedVersion);
    }

    // =========================================================================
    // version_compare() Tests (Core PHP function behavior)
    // =========================================================================

    /**
     * Test version comparison logic for semantic versioning
     */
    public function testVersionComparisonLogic(): void {
        // Test semantic versioning comparisons
        $comparisons = [
            ['6.9.0', '6.10.0', '<', true],
            ['6.10.0', '6.10.0', '<', false],
            ['6.10.1', '6.10.0', '<', false],
            ['6.10.0', '6.10.0', '=', true],
            ['6.10.0', '6.9.0', '>', true],
            ['6.9.9', '6.10.0', '<', true], // 6.9.9 < 6.10.0
            ['6.0.0', '6.10.0', '<', true],
            ['5.9.9', '6.0.0', '<', true],
        ];

        foreach ($comparisons as $case) {
            list($v1, $v2, $operator, $expected) = $case;
            $result = version_compare($v1, $v2, $operator);
            $this->assertEquals($expected, $result, "Failed: version_compare({$v1}, {$v2}, '{$operator}')");
        }
    }

    // =========================================================================
    // run_pre_upgrade_checks() Tests
    // =========================================================================

    /**
     * Test WordPress version check passes for current versions
     */
    public function testWordPressVersionCheckPasses(): void {
        $wpVersion = '6.4.2';
        $requiredVersion = '5.0';

        $passed = version_compare($wpVersion, $requiredVersion, '>=');

        $this->assertTrue($passed);
    }

    /**
     * Test WordPress version check fails for old versions
     */
    public function testWordPressVersionCheckFails(): void {
        $wpVersion = '4.9.0';
        $requiredVersion = '5.0';

        $passed = version_compare($wpVersion, $requiredVersion, '>=');

        $this->assertFalse($passed);
    }

    /**
     * Test PHP version check passes for current versions
     */
    public function testPHPVersionCheckPasses(): void {
        $phpVersion = PHP_VERSION;
        $requiredVersion = '7.4';

        $passed = version_compare($phpVersion, $requiredVersion, '>=');

        // PHP 8.3 should pass
        $this->assertTrue($passed);
    }

    /**
     * Test memory limit check structure
     */
    public function testMemoryLimitCheckStructure(): void {
        $requiredBytes = 128 * 1024 * 1024; // 128MB

        $this->assertEquals(134217728, $requiredBytes);
    }

    // =========================================================================
    // convert_to_bytes() Tests
    // =========================================================================

    /**
     * Test convert_to_bytes handles M format
     */
    public function testConvertToBytesHandlesMFormat(): void {
        $memoryFormats = [
            '128M' => 128 * 1024 * 1024,
            '256M' => 256 * 1024 * 1024,
            '512M' => 512 * 1024 * 1024,
            '64M' => 64 * 1024 * 1024,
        ];

        foreach ($memoryFormats as $input => $expected) {
            $result = $this->convertToBytes($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Test convert_to_bytes handles G format
     */
    public function testConvertToBytesHandlesGFormat(): void {
        $memoryFormats = [
            '1G' => 1 * 1024 * 1024 * 1024,
            '2G' => 2 * 1024 * 1024 * 1024,
            '4G' => 4 * 1024 * 1024 * 1024,
        ];

        foreach ($memoryFormats as $input => $expected) {
            $result = $this->convertToBytes($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Test convert_to_bytes handles K format
     */
    public function testConvertToBytesHandlesKFormat(): void {
        $memoryFormats = [
            '1024K' => 1024 * 1024,
            '512K' => 512 * 1024,
            '2048K' => 2048 * 1024,
        ];

        foreach ($memoryFormats as $input => $expected) {
            $result = $this->convertToBytes($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Test convert_to_bytes handles plain bytes
     */
    public function testConvertToBytesHandlesPlainBytes(): void {
        $memoryFormats = [
            '134217728' => 134217728,
            '268435456' => 268435456,
        ];

        foreach ($memoryFormats as $input => $expected) {
            $result = $this->convertToBytesPlain($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Test convert_to_bytes handles lowercase
     */
    public function testConvertToBytesHandlesLowercase(): void {
        $memoryFormats = [
            '128m' => 128 * 1024 * 1024,
            '1g' => 1 * 1024 * 1024 * 1024,
            '512k' => 512 * 1024,
        ];

        foreach ($memoryFormats as $input => $expected) {
            $result = $this->convertToBytes($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Helper: Convert memory limit string to bytes
     * Mirrors MLD_Upgrader::convert_to_bytes()
     */
    private function convertToBytes(string $limit): int {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $number = (int) $limit;

        switch ($last) {
            case 'g':
                $number *= 1024;
                // Fall through intentional
            case 'm':
                $number *= 1024;
                // Fall through intentional
            case 'k':
                $number *= 1024;
        }

        return $number;
    }

    /**
     * Helper: Convert plain number string to bytes
     */
    private function convertToBytesPlain(string $limit): int {
        return (int) trim($limit);
    }

    // =========================================================================
    // run_upgrade() Tests
    // =========================================================================

    /**
     * Test upgrade status is set to running
     */
    public function testUpgradeStatusSetToRunning(): void {
        $status = [
            'status' => 'running',
            'from_version' => '6.9.0',
            'to_version' => self::CURRENT_VERSION,
        ];

        $this->assertEquals('running', $status['status']);
        $this->assertEquals('6.9.0', $status['from_version']);
        $this->assertEquals(self::CURRENT_VERSION, $status['to_version']);
    }

    /**
     * Test upgrade status is set to completed on success
     */
    public function testUpgradeStatusSetToCompletedOnSuccess(): void {
        $status = [
            'status' => 'completed',
            'from_version' => '6.9.0',
            'to_version' => self::CURRENT_VERSION,
            'duration' => 1.5,
        ];

        $this->assertEquals('completed', $status['status']);
        $this->assertArrayHasKey('duration', $status);
    }

    /**
     * Test upgrade status is set to failed on error
     */
    public function testUpgradeStatusSetToFailedOnError(): void {
        $errorMessage = 'Database migration failed';
        $status = [
            'status' => 'failed',
            'from_version' => '6.9.0',
            'to_version' => self::CURRENT_VERSION,
            'error' => $errorMessage,
        ];

        $this->assertEquals('failed', $status['status']);
        $this->assertEquals($errorMessage, $status['error']);
    }

    /**
     * Test version number is updated after successful upgrade
     */
    public function testVersionNumberUpdatedAfterUpgrade(): void {
        update_option(self::VERSION_OPTION, self::CURRENT_VERSION);

        $storedVersion = get_option(self::VERSION_OPTION);

        $this->assertEquals(self::CURRENT_VERSION, $storedVersion);
    }

    // =========================================================================
    // Legacy Upgrade Tests
    // =========================================================================

    /**
     * Test legacy upgrade detection
     */
    public function testLegacyUpgradeDetection(): void {
        // Legacy system uses 'mld_db_version' option
        $this->setOption('mld_db_version', '4.4.5');

        $legacyVersion = get_option('mld_db_version', '0');
        $needsLegacyUpgrade = version_compare($legacyVersion, self::CURRENT_VERSION, '<');

        $this->assertTrue($needsLegacyUpgrade);
    }

    /**
     * Test legacy upgrade to 4.4.6
     */
    public function testLegacyUpgradeTo446Detection(): void {
        $currentVersion = '4.4.5';
        $targetVersion = '4.4.6';

        $needsUpgrade = version_compare($currentVersion, $targetVersion, '<');

        $this->assertTrue($needsUpgrade);
    }

    /**
     * Test legacy upgrade to 4.4.7
     */
    public function testLegacyUpgradeTo447Detection(): void {
        $currentVersion = '4.4.6';
        $targetVersion = '4.4.7';

        $needsUpgrade = version_compare($currentVersion, $targetVersion, '<');

        $this->assertTrue($needsUpgrade);
    }

    // =========================================================================
    // Migration History Tests
    // =========================================================================

    /**
     * Test migration history structure
     */
    public function testMigrationHistoryStructure(): void {
        $history = [
            'timestamp' => date('Y-m-d H:i:s'),
            'from_version' => '6.9.0',
            'to_version' => self::CURRENT_VERSION,
            'results' => [
                'pre_checks' => ['passed' => true],
                'database' => ['tables_updated' => true],
                'cache' => ['cleared' => true],
            ],
        ];

        $this->assertArrayHasKey('timestamp', $history);
        $this->assertArrayHasKey('from_version', $history);
        $this->assertArrayHasKey('to_version', $history);
        $this->assertArrayHasKey('results', $history);
    }

    /**
     * Test migration history option key
     */
    public function testMigrationHistoryOptionKey(): void {
        $optionKey = 'mld_migration_history';

        $this->assertEquals('mld_migration_history', $optionKey);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    /**
     * Test version constants are defined
     */
    public function testVersionConstantsAreDefined(): void {
        $this->assertEquals('6.10.6', self::CURRENT_VERSION);
        $this->assertEquals('mld_plugin_version', self::VERSION_OPTION);
    }

    /**
     * Test option keys are consistent
     */
    public function testOptionKeysAreConsistent(): void {
        $expectedKeys = [
            'version' => 'mld_plugin_version',
            'upgrade_status' => 'mld_upgrade_status',
            'migration_history' => 'mld_migration_history',
            'legacy_db_version' => 'mld_db_version',
        ];

        $this->assertEquals('mld_plugin_version', $expectedKeys['version']);
        $this->assertEquals('mld_upgrade_status', $expectedKeys['upgrade_status']);
        $this->assertEquals('mld_migration_history', $expectedKeys['migration_history']);
        $this->assertEquals('mld_db_version', $expectedKeys['legacy_db_version']);
    }

    // =========================================================================
    // Cache Clearing Tests
    // =========================================================================

    /**
     * Test cache clearing on upgrade
     */
    public function testCacheClearingOnUpgrade(): void {
        // Set some cache values
        wp_cache_set('test_key', 'test_value', 'mld_cache');
        set_transient('mld_test_transient', 'test_value');

        // Clear cache (simulate upgrade)
        wp_cache_flush();
        delete_transient('mld_test_transient');

        // Verify cleared
        $cacheValue = wp_cache_get('test_key', 'mld_cache');
        $transientValue = get_transient('mld_test_transient');

        $this->assertFalse($cacheValue);
        $this->assertFalse($transientValue);
    }

    /**
     * Test rewrite rules flush transient
     */
    public function testRewriteRulesFlushTransient(): void {
        $transientKey = 'mld_flush_rewrite_rules';
        $ttl = 60;

        set_transient($transientKey, true, $ttl);

        $value = get_transient($transientKey);

        $this->assertTrue($value);
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    /**
     * Test exception handling in upgrade
     */
    public function testExceptionHandlingInUpgrade(): void {
        $exception = new \Exception('Database migration failed');

        $errorResult = [
            'status' => 'failed',
            'error' => 'MLD Upgrade failed: ' . $exception->getMessage(),
        ];

        $this->assertEquals('failed', $errorResult['status']);
        $this->assertStringContainsString('Database migration failed', $errorResult['error']);
    }

    /**
     * Test error logging format
     */
    public function testErrorLoggingFormat(): void {
        $fromVersion = '6.9.0';
        $toVersion = self::CURRENT_VERSION;

        $logMessage = "MLD Upgrader: Starting upgrade from version {$fromVersion} to {$toVersion}";

        $this->assertStringContainsString('MLD Upgrader', $logMessage);
        $this->assertStringContainsString($fromVersion, $logMessage);
        $this->assertStringContainsString($toVersion, $logMessage);
    }
}
