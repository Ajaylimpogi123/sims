<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceRuleException;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approve / reject only act on a leg that is pending review, and rendered
 * hours are credited exactly when both legs end up approved (in either
 * order) and cleared when a leg is rejected.
 */
class AttendanceReviewStateTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private Student $student;

    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->supervisor = User::factory()->create(['role_id' => 3]);
        $this->student = Student::factory()->create(['supervisor_id' => $this->supervisor->id]);
        $this->service = app(AttendanceService::class);
    }

    private function attendance(array $attributes): Attendance
    {
        return Attendance::factory()->create(array_merge([
            'student_id' => $this->student->id,
            'date' => '2026-09-30',
            'time_in' => '08:00:00',
            'time_in_status' => 'pending',
            'time_out' => '17:30:00',
            'time_out_status' => 'pending',
            'rendered_hours' => null,
        ], $attributes));
    }

    private function assertRefused(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected AttendanceRuleException.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    // --------------------------------------------------------------- hours

    public function test_time_out_approved_before_time_in_credits_hours_when_time_in_is_approved(): void
    {
        // Emergency time-out submitted while the time-in was still pending.
        $attendance = $this->attendance([]);

        $this->service->approveTimeOut($attendance);
        $this->assertNull($attendance->fresh()->rendered_hours);

        $this->service->approveTimeIn($attendance->fresh());
        $this->assertEquals(9.5, $attendance->fresh()->rendered_hours);
    }

    public function test_time_in_then_time_out_credits_hours(): void
    {
        $attendance = $this->attendance([]);

        $this->service->approveTimeIn($attendance);
        $this->assertNull($attendance->fresh()->rendered_hours);

        $this->service->approveTimeOut($attendance->fresh());
        $this->assertEquals(9.5, $attendance->fresh()->rendered_hours);
    }

    public function test_rejecting_a_time_in_after_the_time_out_was_approved_credits_nothing(): void
    {
        $attendance = $this->attendance(['time_out_status' => 'approved']);

        $this->service->rejectTimeIn($attendance, 'Not on site');

        $this->assertSame('rejected', $attendance->fresh()->time_in_status);
        $this->assertNull($attendance->fresh()->rendered_hours);
    }

    public function test_rejecting_a_time_out_clears_hours(): void
    {
        $attendance = $this->attendance(['time_in_status' => 'approved', 'rendered_hours' => 3]);

        $this->service->rejectTimeOut($attendance, null);

        $this->assertNull($attendance->fresh()->rendered_hours);
    }

    // ------------------------------------------------------- state checks

    public function test_only_pending_legs_can_be_reviewed(): void
    {
        foreach (['approved', 'rejected', null] as $status) {
            $attendance = $this->attendance([
                'date' => '2026-09-'.random_int(1, 28),
                'time_in_status' => $status,
                'time_out_status' => $status,
                'rendered_hours' => $status === 'approved' ? 9.5 : null,
            ]);

            $this->assertRefused(fn () => $this->service->approveTimeIn($attendance), 'This time-in is not pending review.');
            $this->assertRefused(fn () => $this->service->rejectTimeIn($attendance, null), 'This time-in is not pending review.');
            $this->assertRefused(fn () => $this->service->approveTimeOut($attendance), 'This time-out is not pending review.');
            $this->assertRefused(fn () => $this->service->rejectTimeOut($attendance, null), 'This time-out is not pending review.');

            $fresh = $attendance->fresh();
            $this->assertSame($status, $fresh->time_in_status);
            $this->assertSame($status, $fresh->time_out_status);
            $attendance->delete();
        }
    }

    public function test_a_pending_status_without_a_time_is_not_reviewable(): void
    {
        $attendance = $this->attendance(['time_in' => null, 'time_out' => null]);

        $this->assertRefused(fn () => $this->service->approveTimeIn($attendance), 'This time-in is not pending review.');
        $this->assertRefused(fn () => $this->service->approveTimeOut($attendance), 'This time-out is not pending review.');
    }

    // ----------------------------------------------------------------- web

    public function test_web_approving_a_time_out_without_a_time_out_is_a_flash_error(): void
    {
        $attendance = $this->attendance(['time_in_status' => 'approved', 'time_out' => null, 'time_out_status' => null]);

        $this->actingAs($this->supervisor)
            ->patch("/attendance-approvals/{$attendance->id}/approve-time-out")
            ->assertRedirect(route('attendance-approvals.index', absolute: false))
            ->assertSessionHas('error', 'This time-out is not pending review.');

        $this->assertNull($attendance->fresh()->time_out_status);
    }

    public function test_web_rejecting_an_already_approved_time_in_is_a_flash_error_and_keeps_hours(): void
    {
        $attendance = $this->attendance(['time_in_status' => 'approved', 'time_out_status' => 'approved', 'rendered_hours' => 9.5]);

        foreach (['reject-time-in', 'approve-time-in', 'reject-time-out', 'approve-time-out'] as $action) {
            $this->actingAs($this->supervisor)
                ->patch("/attendance-approvals/{$attendance->id}/{$action}")
                ->assertSessionHas('error');
        }

        $fresh = $attendance->fresh();
        $this->assertSame('approved', $fresh->time_in_status);
        $this->assertSame('approved', $fresh->time_out_status);
        $this->assertEquals(9.5, $fresh->rendered_hours);
    }

    public function test_web_approve_out_then_in_credits_hours_to_progress(): void
    {
        $attendance = $this->attendance([]);

        $this->actingAs($this->supervisor)->patch("/attendance-approvals/{$attendance->id}/approve-time-out")
            ->assertSessionHas('success');
        $this->actingAs($this->supervisor)->patch("/attendance-approvals/{$attendance->id}/approve-time-in")
            ->assertSessionHas('success');

        $this->assertEquals(9.5, $attendance->fresh()->rendered_hours);
        $this->assertEquals(9.5, (float) Student::query()
            ->withSum('attendances as total', 'rendered_hours')
            ->find($this->student->id)->total);
    }
}
