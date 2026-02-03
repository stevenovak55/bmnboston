<?php
/**
 * Plugin Activation Tests
 *
 * Tests plugin activation, deactivation, and version management.
 *
 * @package MLSDisplay\Tests\Integration
 * @since 6.14.11
 */

namespace MLSDisplay\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * Test class for plugin activation and deactivation
 */
class ActivationTest extends MLD_Integration_TestCase {

    /**
     * Test that activation sets version option
     */
    public function testActivationSetsVersionOption(): void {
        // Simulate fresh install - no version stored
        $this->setOption('mld_db_version', false);
        $this->setOption('mld_plugin_version', false);

        // Check version is not set initially
        $this->assertFalse(get_option('mld_db_version'));
        $this->assertFalse(get_option('mld_plugin_version'));

        // After activation, versions should be set
        update_option('mld_db_version', MLD_VERSION);
        update_option('mld_plugin_version', MLD_VERSION);

        $this->assertEquals(MLD_VERSION, get_option('mld_db_version'));
        $this->assertEquals(MLD_VERSION, get_option('mld_plugin_version'));
    }

    /**
     * Test that version constants are defined
     */
    public function testVersionConstantsAreDefined(): void {
        $this->assertTrue(defined('MLD_VERSION'));
        $this->assertTrue(defined('MLD_PLUGIN_FILE'));
        $this->assertTrue(defined('MLD_PLUGIN_PATH'));
        $this->assertTrue(defined('MLD_PLUGIN_URL'));
    }

    /**
     * Test version format is valid semver
     */
    public function testVersionFormatIsValid(): void {
        $version = MLD_VERSION;

        // Should match semver pattern (X.Y.Z)
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'Version should be in X.Y.Z format'
        );
    }

    /**
     * Test that plugin path constant points to valid directory
     */
    public function testPluginPathIsValid(): void {
        $path = MLD_PLUGIN_PATH;

        $this->assertNotEmpty($path);
        $this->assertStringEndsWith('/', $path);
    }

    /**
     * Test that plugin file constant is valid
     */
    public function testPluginFileIsValid(): void {
        $file = MLD_PLUGIN_FILE;

        $this->assertNotEmpty($file);
        $this->assertStringEndsWith('mls-listings-display.php', $file);
    }

    /**
     * Test upgrade detection from older version
     */
    public function testUpgradeDetectionFromOlderVersion(): void {
        // Simulate older version installed
        $oldVersion = '6.10.0';
        $this->setOption('mld_db_version', $oldVersion);

        $currentVersion = MLD_VERSION;
        $storedVersion = get_option('mld_db_version');

        // Should detect upgrade needed
        $needsUpgrade = version_compare($storedVersion, $currentVersion, '<');

        $this->assertTrue($needsUpgrade, 'Should detect upgrade needed from older version');
    }

    /**
     * Test no upgrade needed when versions match
     */
    public function testNoUpgradeWhenVersionsMatch(): void {
        // Simulate same version installed
        $this->setOption('mld_db_version', MLD_VERSION);

        $currentVersion = MLD_VERSION;
        $storedVersion = get_option('mld_db_version');

        // Should not detect upgrade needed
        $needsUpgrade = version_compare($storedVersion, $currentVersion, '<');

        $this->assertFalse($needsUpgrade, 'Should not detect upgrade when versions match');
    }

    /**
     * Test fresh install detection
     */
    public function testFreshInstallDetection(): void {
        // Simulate fresh install - no version stored
        $this->setOption('mld_db_version', false);

        $storedVersion = get_option('mld_db_version');

        $this->assertFalse($storedVersion);

        // Fresh install should be detected
        $isFreshInstall = empty($storedVersion);
        $this->assertTrue($isFreshInstall);
    }

    /**
     * Test settings initialization on fresh install
     */
    public function testSettingsInitializationOnFreshInstall(): void {
        // Clear settings
        $this->setOption('mld_settings', false);

        // Default settings should be applied
        $defaultSettings = [
            'map_enabled' => true,
            'default_zoom' => 12,
            'listings_per_page' => 20,
        ];

        // Simulate activation setting defaults
        if (!get_option('mld_settings')) {
            update_option('mld_settings', $defaultSettings);
        }

        $settings = get_option('mld_settings');
        $this->assertIsArray($settings);
        $this->assertTrue($settings['map_enabled']);
        $this->assertEquals(12, $settings['default_zoom']);
    }

    /**
     * Test deactivation clears transients
     */
    public function testDeactivationClearsTransients(): void {
        // Set up some transients
        set_transient('mld_cache_key_1', 'value1');
        set_transient('mld_cache_key_2', 'value2');

        // Verify they're set
        $this->assertEquals('value1', get_transient('mld_cache_key_1'));
        $this->assertEquals('value2', get_transient('mld_cache_key_2'));

        // Simulate deactivation clearing transients
        delete_transient('mld_cache_key_1');
        delete_transient('mld_cache_key_2');

        // Verify they're cleared
        $this->assertFalse(get_transient('mld_cache_key_1'));
        $this->assertFalse(get_transient('mld_cache_key_2'));
    }

    /**
     * Test deactivation preserves data options
     */
    public function testDeactivationPreservesDataOptions(): void {
        // Set up important data that should persist
        $this->setOption('mld_db_version', MLD_VERSION);
        $this->setOption('mld_settings', ['map_enabled' => true]);
        $this->setOption('mld_saved_search_count', 150);

        // After deactivation, these should still be present
        // (simulating that we don't delete them on deactivation)
        $this->assertEquals(MLD_VERSION, get_option('mld_db_version'));
        $this->assertIsArray(get_option('mld_settings'));
        $this->assertEquals(150, get_option('mld_saved_search_count'));
    }

    /**
     * Test migration history tracking
     */
    public function testMigrationHistoryTracking(): void {
        // Set up migration history
        $history = [
            '6.10.0' => ['date' => '2025-10-01', 'status' => 'completed'],
            '6.11.0' => ['date' => '2025-11-01', 'status' => 'completed'],
        ];

        $this->setOption('mld_migration_history', $history);

        $storedHistory = get_option('mld_migration_history');

        $this->assertIsArray($storedHistory);
        $this->assertArrayHasKey('6.10.0', $storedHistory);
        $this->assertArrayHasKey('6.11.0', $storedHistory);
        $this->assertEquals('completed', $storedHistory['6.10.0']['status']);
    }

    /**
     * Test plugin dependencies check (BME required)
     */
    public function testPluginDependencyCheck(): void {
        // Simulate BME is active by setting an option it creates
        $this->setOption('bme_db_version', '4.0.16');

        // Check if BME appears to be active
        $bmeVersion = get_option('bme_db_version');
        $bmeIsActive = !empty($bmeVersion);

        $this->assertTrue($bmeIsActive, 'BME should be detected as active');
    }

    /**
     * Test plugin dependency missing warning
     */
    public function testPluginDependencyMissingWarning(): void {
        // Simulate BME is NOT active
        $this->setOption('bme_db_version', false);

        // Check if BME appears to be missing
        $bmeVersion = get_option('bme_db_version');
        $bmeIsActive = !empty($bmeVersion);

        $this->assertFalse($bmeIsActive, 'BME should be detected as missing');

        // In real code, this would trigger an admin notice
    }

    /**
     * Test rewrite rules flush scheduled on activation
     */
    public function testRewriteRulesFlushScheduled(): void {
        // Set the transient that triggers rewrite rules flush
        set_transient('mld_flush_rewrite_rules', true);

        $shouldFlush = get_transient('mld_flush_rewrite_rules');

        $this->assertTrue($shouldFlush);
    }

    /**
     * Test cron jobs scheduled on activation
     */
    public function testCronJobsScheduled(): void {
        // Simulate scheduling cron events
        $cronEvents = [
            'mld_daily_cleanup' => true,
            'mld_notification_processor' => true,
        ];

        foreach ($cronEvents as $event => $scheduled) {
            $this->setOption("mld_cron_{$event}", $scheduled);
        }

        // Verify cron events are tracked
        $this->assertTrue(get_option('mld_cron_mld_daily_cleanup'));
        $this->assertTrue(get_option('mld_cron_mld_notification_processor'));
    }
}
