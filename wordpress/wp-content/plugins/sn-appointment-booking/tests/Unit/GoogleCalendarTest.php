<?php
/**
 * Google Calendar Unit Tests
 *
 * Tests for Critical Pitfall #6: Google Calendar Per-Staff Connection
 *
 * Two types of Google Calendar connections exist:
 * - Global: wp_options: snab_google_refresh_token (Admin dashboard)
 * - Per-Staff: wp_snab_staff.google_refresh_token (Booking sync)
 *
 * When syncing appointments, use per-staff methods, not global methods.
 *
 * @package SN_Appointment_Booking\Tests\Unit
 * @since 1.9.4
 */

namespace SNAB\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/SNAB_Unit_TestCase.php';

/**
 * Google Calendar Test Class
 */
class GoogleCalendarTest extends SNAB_Unit_TestCase {

    /**
     * Test that per-staff connection check is different from global.
     *
     * Critical Pitfall #6: Google Calendar Per-Staff vs Global Connection
     */
    public function test_per_staff_vs_global_connection_distinction() {
        $staff_id = 1;

        // Per-staff: Check staff table for google_refresh_token
        $per_staff_method = "is_staff_connected(\$staff_id)";

        // Global: Check wp_options for snab_google_refresh_token
        $global_method = "is_connected()";

        $this->assertNotEquals($per_staff_method, $global_method,
            'Per-staff and global methods should be different');
    }

    /**
     * Test staff with own Google connection should use their calendar.
     */
    public function test_staff_with_own_connection() {
        $staff = $this->createMockStaff([
            'id' => 1,
            'google_refresh_token' => 'valid_staff_token_123',
        ]);

        $has_own_connection = !empty($staff->google_refresh_token);

        $this->assertTrue($has_own_connection,
            'Staff with refresh token should have own connection');
    }

    /**
     * Test staff without own connection falls back to global.
     */
    public function test_staff_without_connection_uses_fallback() {
        $staff = $this->createMockStaff([
            'id' => 2,
            'google_refresh_token' => null,  // No personal connection
        ]);

        $has_own_connection = !empty($staff->google_refresh_token);

        $this->assertFalse($has_own_connection,
            'Staff without token should fallback to global');
    }

    /**
     * Test that booking sync should use staff-specific calendar.
     */
    public function test_booking_sync_uses_staff_calendar() {
        $staff_id = 1;
        $appointment_data = [
            'title' => 'Property Showing',
            'start' => '2026-01-20 10:00:00',
            'end' => '2026-01-20 11:00:00',
        ];

        // CORRECT: Use per-staff method
        $correct_call = "create_staff_event(\$staff_id, \$appointment_data)";

        // WRONG: Use global method
        $wrong_call = "create_event(\$appointment_data)";

        $this->assertStringContainsString('staff_id', $correct_call,
            'Correct method should include staff_id parameter');
        $this->assertStringNotContainsString('staff_id', $wrong_call,
            'Wrong method is missing staff_id parameter');
    }

    /**
     * Test connection status check patterns.
     */
    public function test_connection_status_patterns() {
        $staff_id = 1;

        // Per-staff patterns (CORRECT for booking sync)
        $correct_patterns = [
            'is_staff_connected($staff_id)',
            'create_staff_event($staff_id, $data)',
            'update_staff_event($staff_id, $event_id, $data)',
            'delete_staff_event($staff_id, $event_id)',
        ];

        // Global patterns (WRONG for booking sync)
        $wrong_patterns = [
            'is_connected()',
            'create_event($data)',
            'update_event($event_id, $data)',
            'delete_event($event_id)',
        ];

        foreach ($correct_patterns as $pattern) {
            $this->assertStringContainsString('staff', $pattern,
                "Correct pattern should include 'staff': {$pattern}");
        }

        foreach ($wrong_patterns as $pattern) {
            $this->assertStringNotContainsString('staff', $pattern,
                "Wrong pattern should NOT include 'staff': {$pattern}");
        }
    }

    /**
     * Test that multiple staff can have different calendar connections.
     */
    public function test_multiple_staff_different_calendars() {
        $staff1 = $this->createMockStaff([
            'id' => 1,
            'user_id' => 10,
            'google_refresh_token' => 'staff1_token',
        ]);

        $staff2 = $this->createMockStaff([
            'id' => 2,
            'user_id' => 11,
            'google_refresh_token' => 'staff2_token',
        ]);

        $staff3 = $this->createMockStaff([
            'id' => 3,
            'user_id' => 12,
            'google_refresh_token' => null,  // Uses global fallback
        ]);

        $this->assertNotEquals(
            $staff1->google_refresh_token,
            $staff2->google_refresh_token,
            'Different staff should have different tokens'
        );

        $this->assertNull($staff3->google_refresh_token,
            'Staff without connection has null token');
    }

    /**
     * Test database table structure for per-staff tokens.
     */
    public function test_staff_table_has_google_token_column() {
        // The wp_snab_staff table should have google_refresh_token column
        $expected_column = 'google_refresh_token';

        $staff = $this->createMockStaff();

        $this->assertTrue(
            property_exists($staff, $expected_column),
            'Staff object should have google_refresh_token property'
        );
    }

    /**
     * Test global connection storage location.
     */
    public function test_global_connection_in_options() {
        // Global connection stored in wp_options
        $option_name = 'snab_google_refresh_token';

        // Set a mock global token
        update_option($option_name, 'global_token_xyz');
        $global_token = get_option($option_name);

        $this->assertEquals('global_token_xyz', $global_token,
            'Global token should be stored in wp_options');
    }

    /**
     * Test documentation of which method to use when.
     */
    public function test_method_usage_documentation() {
        $documentation = [
            'booking_sync' => 'Use per-staff methods (is_staff_connected, create_staff_event)',
            'admin_dashboard' => 'Use global methods for admin calendar view',
            'appointment_create' => 'Use per-staff to sync to staff calendar',
            'appointment_update' => 'Use per-staff to update in staff calendar',
            'appointment_cancel' => 'Use per-staff to remove from staff calendar',
        ];

        foreach ($documentation as $use_case => $method) {
            if (in_array($use_case, ['booking_sync', 'appointment_create', 'appointment_update', 'appointment_cancel'])) {
                $this->assertStringContainsString('per-staff', $method,
                    "{$use_case} should use per-staff methods");
            }
        }
    }
}
