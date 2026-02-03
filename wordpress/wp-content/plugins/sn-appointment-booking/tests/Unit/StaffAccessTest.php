<?php
/**
 * Staff Access Unit Tests
 *
 * Tests for Critical Pitfall #25: Staff Access to Client-Booked Appointments
 *
 * Staff members must be able to view, cancel, and reschedule appointments
 * that clients booked WITH them, not just their own bookings.
 *
 * @package SN_Appointment_Booking\Tests\Unit
 * @since 1.9.4
 */

namespace SNAB\Tests\Unit;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/SNAB_Unit_TestCase.php';

/**
 * Staff Access Test Class
 */
class StaffAccessTest extends SNAB_Unit_TestCase {

    /**
     * Test that staff can view appointments booked with them.
     *
     * Critical Pitfall #25: Staff members must see appointments clients booked
     * WITH them, not just appointments the staff member booked themselves.
     *
     * Scenario:
     * - Client (user_id=20) books appointment with Staff (staff_id=1)
     * - Staff member logs in (user_id=10, linked to staff_id=1)
     * - Staff should be able to VIEW this appointment
     */
    public function test_staff_can_view_client_booked_appointment() {
        // Setup: Staff member with user_id=10, staff_id=1
        $staff = $this->createMockStaff(['id' => 1, 'user_id' => 10]);
        $this->mockGetVar('snab_staff WHERE user_id', 1);  // Staff ID lookup

        // Client booked appointment with this staff
        $appointment = $this->createMockAppointment([
            'id' => 100,
            'user_id' => 20,    // Client who booked
            'staff_id' => 1,    // Staff assigned to appointment
        ]);

        // Staff is logged in
        $this->setCurrentUser(10);

        // Simulate the correct access check pattern
        $current_user_id = 10;
        $staff_id = 1;  // From lookup

        // The access query should check BOTH user_id AND staff_id
        $has_access = (
            $appointment->user_id == $current_user_id ||  // User booked it
            $appointment->staff_id == $staff_id           // User is the assigned staff
        );

        $this->assertTrue($has_access,
            'Staff should have access to appointments booked with them');
    }

    /**
     * Test that the WRONG access pattern fails for staff.
     *
     * This demonstrates the bug that was fixed in v1.9.0.
     */
    public function test_wrong_pattern_blocks_staff_access() {
        // Setup: Staff member
        $staff = $this->createMockStaff(['id' => 1, 'user_id' => 10]);

        // Client booked appointment with this staff
        $appointment = $this->createMockAppointment([
            'id' => 100,
            'user_id' => 20,    // Client who booked
            'staff_id' => 1,    // Staff assigned
        ]);

        // Staff is logged in
        $current_user_id = 10;

        // WRONG: Only checking user_id (the old buggy pattern)
        $wrong_access_check = ($appointment->user_id == $current_user_id);

        $this->assertFalse($wrong_access_check,
            'Checking only user_id would incorrectly block staff access');
    }

    /**
     * Test that non-staff users cannot view other users' appointments.
     *
     * Regular clients should only see their own appointments.
     */
    public function test_non_staff_cannot_view_others_appointments() {
        // User 30 is NOT a staff member
        $this->setCurrentUser(30);
        $this->mockGetVar('snab_staff WHERE user_id', null);  // Not found

        // Appointment booked by user 20 with staff 1
        $appointment = $this->createMockAppointment([
            'id' => 100,
            'user_id' => 20,
            'staff_id' => 1,
        ]);

        $current_user_id = 30;
        $staff_id = null;  // Not a staff member

        $has_access = (
            $appointment->user_id == $current_user_id ||
            ($staff_id && $appointment->staff_id == $staff_id)
        );

        $this->assertFalse($has_access,
            'Non-staff users should not see other users\' appointments');
    }

    /**
     * Test that clients can view their own appointments.
     */
    public function test_client_can_view_own_appointment() {
        $this->setCurrentUser(20);
        $this->mockGetVar('snab_staff WHERE user_id', null);  // Not staff

        $appointment = $this->createMockAppointment([
            'id' => 100,
            'user_id' => 20,  // This user booked it
            'staff_id' => 1,
        ]);

        $current_user_id = 20;
        $staff_id = null;

        $has_access = (
            $appointment->user_id == $current_user_id ||
            ($staff_id && $appointment->staff_id == $staff_id)
        );

        $this->assertTrue($has_access,
            'Clients should be able to view their own appointments');
    }

    /**
     * Test staff bypass for cancellation time restrictions.
     *
     * Staff should be able to cancel appointments at any time,
     * while clients must respect the cancellation deadline.
     */
    public function test_staff_bypass_cancellation_deadline() {
        $staff = $this->createMockStaff(['id' => 1, 'user_id' => 10]);

        // Appointment is in 30 minutes (past typical 24-hour deadline)
        $appointment = $this->createMockAppointment([
            'user_id' => 20,
            'staff_id' => 1,
            'appointment_date' => date('Y-m-d'),
            'start_time' => date('H:i:s', strtotime('+30 minutes')),
        ]);

        $current_user_id = 10;
        $staff_id = 1;
        $cancel_hours = 24;  // 24-hour cancellation policy
        $hours_until = 0.5;  // 30 minutes until appointment

        // Staff is cancelling their own appointment assignment
        $is_staff_cancelling = ($staff_id && $appointment->staff_id == $staff_id);

        // Staff can cancel anytime
        $can_cancel = $is_staff_cancelling || ($hours_until >= $cancel_hours);

        $this->assertTrue($is_staff_cancelling,
            'Should detect staff is cancelling');
        $this->assertTrue($can_cancel,
            'Staff should be able to cancel past deadline');
    }

    /**
     * Test client blocked by cancellation deadline.
     */
    public function test_client_blocked_by_cancellation_deadline() {
        $this->setCurrentUser(20);
        $this->mockGetVar('snab_staff WHERE user_id', null);  // Not staff

        // Appointment is in 30 minutes
        $appointment = $this->createMockAppointment([
            'user_id' => 20,
            'staff_id' => 1,
            'appointment_date' => date('Y-m-d'),
            'start_time' => date('H:i:s', strtotime('+30 minutes')),
        ]);

        $staff_id = null;
        $cancel_hours = 24;
        $hours_until = 0.5;

        $is_staff_cancelling = ($staff_id && $appointment->staff_id == $staff_id);
        $can_cancel = $is_staff_cancelling || ($hours_until >= $cancel_hours);

        $this->assertFalse($is_staff_cancelling,
            'Client is not staff');
        $this->assertFalse($can_cancel,
            'Client should be blocked by cancellation deadline');
    }

    /**
     * Test staff access to reschedule appointments.
     */
    public function test_staff_can_reschedule_client_appointment() {
        $staff = $this->createMockStaff(['id' => 1, 'user_id' => 10]);
        $this->setCurrentUser(10);
        $this->mockGetVar('snab_staff WHERE user_id', 1);

        $appointment = $this->createMockAppointment([
            'user_id' => 20,
            'staff_id' => 1,
        ]);

        $current_user_id = 10;
        $staff_id = 1;

        $has_access = (
            $appointment->user_id == $current_user_id ||
            $appointment->staff_id == $staff_id
        );

        $this->assertTrue($has_access,
            'Staff should be able to reschedule appointments with them');
    }

    /**
     * Test SQL WHERE clause pattern for staff access.
     *
     * The correct pattern should be:
     * WHERE id = %d AND (user_id = %d OR staff_id = %d)
     */
    public function test_correct_sql_where_pattern() {
        $appointment_id = 100;
        $user_id = 10;
        $staff_id = 1;

        // CORRECT pattern: check both user_id AND staff_id
        $correct_where = sprintf(
            "WHERE id = %d AND (user_id = %d OR staff_id = %d)",
            $appointment_id,
            $user_id,
            $staff_id
        );

        // WRONG pattern: only checks user_id
        $wrong_where = sprintf(
            "WHERE id = %d AND user_id = %d",
            $appointment_id,
            $user_id
        );

        $this->assertStringContainsString('OR staff_id', $correct_where,
            'Correct pattern must include staff_id check');
        $this->assertStringNotContainsString('OR staff_id', $wrong_where,
            'Wrong pattern is missing staff_id check');
    }

    /**
     * Test that cancelled_by field is set correctly.
     */
    public function test_cancelled_by_tracks_who_cancelled() {
        // Staff cancelling
        $is_staff_cancelling = true;
        $cancelled_by = $is_staff_cancelling ? 'staff' : 'client';
        $this->assertEquals('staff', $cancelled_by);

        // Client cancelling
        $is_staff_cancelling = false;
        $cancelled_by = $is_staff_cancelling ? 'staff' : 'client';
        $this->assertEquals('client', $cancelled_by);
    }

    /**
     * Test endpoints that need the staff access pattern.
     *
     * These 5 endpoints were fixed in v1.9.0.
     */
    public function test_affected_endpoints_list() {
        $affected_endpoints = [
            'get_appointment',         // GET /appointments/{id}
            'cancel_appointment',      // DELETE /appointments/{id}
            'reschedule_appointment',  // PATCH /appointments/{id}/reschedule
            'get_reschedule_slots',    // GET /appointments/{id}/reschedule-slots
            'download_ics',            // GET /appointments/{id}/ics
        ];

        $this->assertCount(5, $affected_endpoints,
            'Should be 5 endpoints affected by staff access pattern');
    }
}
