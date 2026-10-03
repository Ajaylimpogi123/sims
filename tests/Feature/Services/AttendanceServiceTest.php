<?php

namespace Tests\Feature\Services;

use App\Exceptions\AttendanceRuleException;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AttendanceService called directly, the way the /api/v1 controllers will:
 * same state rules and evidence handling as the website.
 */
class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    private User $supervisor;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake(AttendanceService::PHOTO_DISK);

        $this->service = app(AttendanceService::class);
        $this->supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR]);
        $this->student = Student::factory()->create(['supervisor_id' => $this->supervisor->id]);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('capture.jpg', 64, 64);
    }

    private function capture(array $overrides = []): array
    {
        return array_merge(['latitude' => '10.5', 'longitude' => '122.9', 'accuracy' => '5'], $overrides);
    }

    public function test_time_in_stores_evidence_and_notifies_the_supervisor(): void
    {
        $record = $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());

        $this->assertSame('pending', $record->time_in_status);
        $this->assertSame($this->student->user->id, $record->recorded_by);
        Storage::disk(AttendanceService::PHOTO_DISK)->assertExists($record->time_in_photo_path);
        $this->assertSame(1, Notification::where('user_id', $this->supervisor->id)->count());
    }

    public function test_second_time_in_is_refused_without_storing_a_photo(): void
    {
        $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());

        try {
            $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());
            $this->fail('A second time-in for today must be refused.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame('You already have a time-in request for today.', $e->getMessage());
        }

        $this->assertCount(1, Storage::disk(AttendanceService::PHOTO_DISK)->allFiles());
    }

    public function test_time_out_requires_an_approved_time_in(): void
    {
        $this->expectException(AttendanceRuleException::class);
        $this->expectExceptionMessage('Your time-in must be approved before you can time out.');

        $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());
        $this->service->timeOut($this->student, $this->student->user, $this->photo(), $this->capture());
    }

    public function test_emergency_time_out_requires_a_time_in(): void
    {
        $this->expectException(AttendanceRuleException::class);
        $this->expectExceptionMessage('You must time in before using emergency time-out.');

        $this->service->emergencyTimeOut($this->student, $this->student->user, $this->photo(), $this->capture(['note' => 'Sick']));
    }

    public function test_emergency_time_out_after_pending_time_in_is_accepted(): void
    {
        $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());

        $record = $this->service->emergencyTimeOut($this->student, $this->student->user, $this->photo(), $this->capture(['note' => 'Sick']));

        $this->assertTrue((bool) $record->is_emergency);
        $this->assertSame('Sick', $record->note);
        $this->assertSame('pending', $record->time_out_status);
    }

    public function test_accuracy_is_clamped_to_the_column_max(): void
    {
        $this->assertSame(999999.99, AttendanceService::clampAccuracy('999999.995'));
        $this->assertSame(12.35, AttendanceService::clampAccuracy('12.345'));
        $this->assertNull(AttendanceService::clampAccuracy(null));
    }

    public function test_reject_updates_status_notifies_the_student_and_is_final(): void
    {
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $this->student->id]);

        $this->service->rejectTimeIn($attendance, 'Blurry photo');
        $this->assertSame('rejected', $attendance->fresh()->time_in_status);
        $this->assertSame('Blurry photo', $attendance->fresh()->time_in_rejection_reason);
        $this->assertSame(1, Notification::where('user_id', $this->student->user_id)->count());

        // Only a pending leg can be decided; the student re-submits instead.
        try {
            $this->service->approveTimeIn($attendance);
            $this->fail('Approving a rejected time-in should be refused.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame('This time-in is not pending review.', $e->getMessage());
        }

        $this->assertSame('rejected', $attendance->fresh()->time_in_status);
        $this->assertSame(1, Notification::where('user_id', $this->student->user_id)->count());
    }

    public function test_approve_clears_the_reason_and_notifies_the_student(): void
    {
        $attendance = Attendance::factory()->pendingTimeIn()->create([
            'student_id' => $this->student->id,
            'time_in_rejection_reason' => 'Earlier attempt was blurry',
        ]);

        $this->service->approveTimeIn($attendance);

        $this->assertSame('approved', $attendance->fresh()->time_in_status);
        $this->assertNull($attendance->fresh()->time_in_rejection_reason);
        $this->assertSame(1, Notification::where('user_id', $this->student->user_id)->count());
    }

    public function test_delete_entry_removes_both_photos(): void
    {
        $disk = Storage::disk(AttendanceService::PHOTO_DISK);
        $disk->put('attendance-photos/x/in.jpg', 'in');
        $disk->put('attendance-photos/x/out.jpg', 'out');

        $attendance = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'time_in_photo_path' => 'attendance-photos/x/in.jpg',
            'time_out_photo_path' => 'attendance-photos/x/out.jpg',
        ]);

        $this->service->deleteEntry($attendance);

        $this->assertModelMissing($attendance);
        $disk->assertMissing(['attendance-photos/x/in.jpg', 'attendance-photos/x/out.jpg']);
    }

    /**
     * Regression (QA Module 6 BUG 2): time-in 08:00, emergency time-out
     * 10:00 approved, time-in rejected, then a 13:00 re-submitted time-in
     * approved used to credit -3.00 hours.
     */
    public function test_time_in_cannot_be_resubmitted_after_the_time_out_was_submitted(): void
    {
        $user = $this->student->user;

        $this->travelTo(today()->setTime(8, 0));
        $record = $this->service->timeIn($this->student, $user, $this->photo(), $this->capture());

        $this->travelTo(today()->setTime(10, 0));
        $this->service->emergencyTimeOut($this->student, $user, $this->photo(), $this->capture(['note' => 'Sick']));
        $this->service->approveTimeOut($record->fresh());
        $this->service->rejectTimeIn($record->fresh(), 'Blurry');

        $this->travelTo(today()->setTime(13, 0));

        try {
            $this->service->timeIn($this->student, $user, $this->photo(), $this->capture());
            $this->fail('A time-in after the day\'s time-out was accepted.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame(
                'Your time-out for today has already been submitted, so your time-in can no longer be re-submitted.',
                $e->getMessage(),
            );
        }

        $record->refresh();
        $this->assertSame('08:00:00', $record->time_in);
        $this->assertSame('rejected', $record->time_in_status);
        $this->assertNull($record->rendered_hours);
    }

    public function test_rendered_hours_are_never_negative(): void
    {
        // Rows saved before the rule above existed can hold an earlier time-out.
        $this->assertSame(0.0, AttendanceService::renderedHours('2026-10-03', '13:00:00', '10:00:00'));
        $this->assertSame(8.5, AttendanceService::renderedHours('2026-10-03', '08:00:00', '16:30:00'));
    }

    /**
     * Regression (QA Module 6 BUG 1): the state check is repeated under a
     * row lock, so a request that read the row before a concurrent one
     * saved it is refused instead of overwriting it (orphaned photo,
     * duplicate notifications).
     */
    public function test_state_is_rechecked_under_the_row_lock(): void
    {
        $record = Attendance::factory()->create([
            'student_id' => $this->student->id,
            'date' => today()->toDateString(),
            'time_in_status' => 'approved',
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ]);

        // A concurrent time-out lands between this request's first read and
        // its locked read.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $record) {
            if ($landed || ! str_contains($query->sql, 'from `attendances`')) {
                return;
            }

            $landed = true;
            DB::table('attendances')->where('id', $record->id)->update([
                'time_out' => '09:00:00',
                'time_out_status' => 'pending',
                'time_out_photo_path' => 'attendance-photos/x/winner.jpg',
            ]);
        });

        try {
            $this->service->timeOut($this->student, $this->student->user, $this->photo(), $this->capture());
            $this->fail('The second time-out was accepted.');
        } catch (AttendanceRuleException $e) {
            $this->assertSame('You already have a time-out request for today.', $e->getMessage());
        }

        $this->assertTrue($landed);
        $this->assertSame('attendance-photos/x/winner.jpg', $record->fresh()->time_out_photo_path);
        $this->assertSame([], Storage::disk(AttendanceService::PHOTO_DISK)->allFiles());
        $this->assertSame(0, Notification::count());
    }

    public function test_concurrent_first_time_in_is_refused_not_an_error(): void
    {
        // The other request inserts today's row after both passed the
        // locked read; this one then hits the (student_id, date) index.
        $raced = false;
        Attendance::creating(function () use (&$raced) {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('attendances')->insert([
                'student_id' => $this->student->id,
                'date' => today()->toDateString(),
                'time_in' => '08:14:00',
                'time_in_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->expectException(AttendanceRuleException::class);
        $this->expectExceptionMessage('You already have a time-in request for today.');

        try {
            $this->service->timeIn($this->student, $this->student->user, $this->photo(), $this->capture());
        } finally {
            $this->assertSame([], Storage::disk(AttendanceService::PHOTO_DISK)->allFiles());
            $this->assertSame(0, Notification::count());
        }
    }
}
