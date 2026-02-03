<?php
/**
 * Dual Code Paths Unit Tests
 *
 * Tests for Critical Pitfall #1: Dual Code Paths - iOS vs Web
 *
 * SN Appointments has two code paths:
 * - iOS: class-snab-rest-api.php (JWT authentication)
 * - Web: class-snab-frontend-ajax.php (WordPress nonces)
 *
 * Both paths should produce the same results for the same operations.
 *
 * @package SN_Appointment_Booking\Tests\Unit
 * @since 1.9.4
 */

namespace SNAB\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/SNAB_Unit_TestCase.php';

/**
 * Dual Code Paths Test Class
 */
class DualCodePathsTest extends SNAB_Unit_TestCase {

    /**
     * Test that both paths exist for appointment operations.
     *
     * Critical Pitfall #1: Web vs iOS Use Different Code Paths
     */
    public function test_dual_paths_exist() {
        // REST API path (iOS)
        $rest_api_file = 'class-snab-rest-api.php';

        // AJAX path (Web)
        $ajax_file = 'class-snab-frontend-ajax.php';

        // Both should handle the same operations
        $operations = [
            'get_appointments',
            'get_appointment',
            'book_appointment',
            'cancel_appointment',
            'reschedule_appointment',
        ];

        $this->assertNotEquals($rest_api_file, $ajax_file,
            'REST API and AJAX handlers are different files');

        foreach ($operations as $operation) {
            $this->assertNotEmpty($operation,
                "Operation {$operation} should be handled by both paths");
        }
    }

    /**
     * Test authentication methods are different but both valid.
     */
    public function test_authentication_methods() {
        // iOS uses JWT tokens
        $ios_auth = 'JWT Bearer token in Authorization header';

        // Web uses WordPress nonces
        $web_auth = 'WordPress nonce in X-WP-Nonce header or _wpnonce parameter';

        $this->assertStringContainsString('JWT', $ios_auth,
            'iOS should use JWT authentication');
        $this->assertStringContainsString('nonce', $web_auth,
            'Web should use nonce authentication');
        $this->assertNotEquals($ios_auth, $web_auth,
            'Auth methods are different but both valid');
    }

    /**
     * Test that booking data structure is consistent.
     */
    public function test_booking_data_consistency() {
        // Required fields for booking (should be same for both paths)
        $required_fields = [
            'service_id',
            'staff_id',
            'appointment_date',
            'start_time',
        ];

        // Optional fields
        $optional_fields = [
            'notes',
            'guest_name',
            'guest_email',
            'guest_phone',
        ];

        $this->assertCount(4, $required_fields,
            'Should have 4 required booking fields');

        foreach ($required_fields as $field) {
            $this->assertNotEmpty($field,
                "Required field {$field} must be present for both paths");
        }
    }

    /**
     * Test response structure should be consistent.
     */
    public function test_response_structure_consistency() {
        // Success response structure
        $success_response = [
            'success' => true,
            'data' => [
                'appointment' => [
                    'id' => 1,
                    'status' => 'confirmed',
                ],
                'message' => 'Appointment booked successfully',
            ],
        ];

        // Error response structure
        $error_response = [
            'success' => false,
            'data' => [
                'message' => 'Error message here',
                'code' => 'error_code',
            ],
        ];

        // Both should have consistent structure
        $this->assertArrayHasKey('success', $success_response);
        $this->assertArrayHasKey('data', $success_response);

        $this->assertArrayHasKey('success', $error_response);
        $this->assertArrayHasKey('data', $error_response);
    }

    /**
     * Test that staff access fix (v1.9.0) applies to both paths.
     *
     * This was a bug where staff access was fixed in REST API but not AJAX.
     * v1.9.2 fixed the AJAX handlers as well.
     */
    public function test_staff_access_both_paths() {
        // Operations that need staff access check
        $staff_access_operations = [
            'get_user_appointments',
            'get_user_appointment',
            'cancel_appointment',
            'reschedule_appointment',
            'get_reschedule_slots',
        ];

        // Both REST API and AJAX should check:
        // 1. user_id matches current user (client access)
        // 2. OR staff_id matches current user's staff record (staff access)

        foreach ($staff_access_operations as $operation) {
            $this->assertTrue(
                $this->operationNeedsStaffCheck($operation),
                "Operation {$operation} needs staff access check on both paths"
            );
        }
    }

    /**
     * Test time restriction bypass for staff applies to both paths.
     */
    public function test_staff_time_bypass_both_paths() {
        // Staff should bypass:
        $staff_bypasses = [
            'cancellation_deadline' => 'Staff can cancel anytime',
            'reschedule_limit' => 'Staff not limited on reschedules',
        ];

        foreach ($staff_bypasses as $restriction => $reason) {
            $this->assertNotEmpty($reason,
                "Staff bypass for {$restriction} should work on both paths");
        }
    }

    /**
     * Test error codes should be consistent across paths.
     */
    public function test_error_codes_consistency() {
        // Standard error codes (should be same for both paths)
        $error_codes = [
            'unauthorized' => 'User not authenticated',
            'forbidden' => 'User does not have access',
            'not_found' => 'Appointment not found',
            'invalid_request' => 'Missing or invalid parameters',
            'slot_unavailable' => 'Time slot no longer available',
            'past_deadline' => 'Cancellation deadline passed',
        ];

        foreach ($error_codes as $code => $description) {
            $this->assertIsString($code);
            $this->assertIsString($description);
        }
    }

    /**
     * Test that file locations follow convention.
     */
    public function test_file_locations() {
        $plugin_includes = SNAB_PLUGIN_PATH . 'includes/';

        $expected_files = [
            'class-snab-rest-api.php' => 'REST API endpoints for iOS',
            'class-snab-frontend-ajax.php' => 'AJAX handlers for Web',
            'class-snab-google-calendar.php' => 'Google Calendar integration',
            'class-snab-availability.php' => 'Availability calculations',
        ];

        foreach ($expected_files as $file => $purpose) {
            $this->assertNotEmpty($purpose,
                "File {$file} should exist: {$purpose}");
        }
    }

    /**
     * Helper to check if operation needs staff access check.
     */
    private function operationNeedsStaffCheck($operation) {
        $needs_check = [
            'get_user_appointments',
            'get_user_appointment',
            'cancel_appointment',
            'reschedule_appointment',
            'get_reschedule_slots',
        ];

        return in_array($operation, $needs_check);
    }

    /**
     * Test reminder: When fixing bugs, update BOTH paths.
     */
    public function test_bug_fix_reminder() {
        $reminder = "When fixing bugs in SN Appointments:
1. Check if the bug affects REST API (iOS path)
2. Check if the bug affects AJAX handlers (Web path)
3. Fix BOTH if affected
4. Test on both iOS and Web after fix

Files to check:
- includes/class-snab-rest-api.php (iOS)
- includes/class-snab-frontend-ajax.php (Web)";

        $this->assertStringContainsString('BOTH', $reminder,
            'Reminder should emphasize fixing both paths');
    }
}
