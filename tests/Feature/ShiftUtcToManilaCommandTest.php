<?php

namespace Tests\Feature;

use App\Console\Commands\ShiftUtcToManila;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftUtcToManilaCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create(['role_id' => 1]);
        $this->student = Student::factory()->create(['user_id' => $this->user->id]);

        $this->stamp('users', $this->user->id, [
            'email_verified_at' => '2026-09-01 10:00:00',
            'created_at' => '2026-09-01 09:00:00',
            'updated_at' => '2026-09-01 09:30:00',
        ]);
    }

    private function stamp(string $table, int $id, array $values): void
    {
        DB::table($table)->where('id', $id)->update($values);
    }

    private function row(string $table, int $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    private function attendance(array $attributes): Attendance
    {
        return Attendance::factory()->create(array_merge(['student_id' => $this->student->id], $attributes));
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $attendance = $this->attendance(['date' => '2026-10-02', 'time_in' => '04:02:55', 'time_out' => null]);
        $this->stamp('attendances', $attendance->id, ['created_at' => '2026-10-02 04:02:55', 'updated_at' => '2026-10-02 04:02:55']);

        $before = [
            'users' => $this->row('users', $this->user->id),
            'attendances' => $this->row('attendances', $attendance->id),
        ];

        $this->artisan('app:shift-utc-to-manila', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('created_at: 2026-10-02 04:02:55 -> 2026-10-02 12:02:55')
            ->expectsOutputToContain('04:02:55 -> 12:02:55')
            ->assertSuccessful();

        $this->assertEquals($before['users'], $this->row('users', $this->user->id));
        $this->assertEquals($before['attendances'], $this->row('attendances', $attendance->id));
        $this->assertDatabaseMissing('data_fixes', ['name' => ShiftUtcToManila::MARKER]);
    }

    public function test_real_run_shifts_system_timestamps_and_attendance_but_not_user_entered_dates(): void
    {
        // Same shape as the real attendance #1: date stays, time moves +8h.
        $sameDay = $this->attendance([
            'date' => '2026-10-02', 'time_in' => '04:02:55', 'time_in_status' => 'pending',
            'time_out' => null, 'time_out_status' => null, 'rendered_hours' => null,
        ]);
        $this->stamp('attendances', $sameDay->id, ['created_at' => '2026-10-02 04:02:55', 'updated_at' => '2026-10-02 04:02:55']);

        // UTC 16:30 is 00:30 the next day in Manila, so the date rolls over.
        $rollover = $this->attendance(['date' => '2026-09-30', 'time_in' => '16:30:00', 'time_out' => '20:00:00', 'rendered_hours' => 3.5]);

        $report = InternshipReport::factory()->reviewed()->create([
            'student_id' => $this->student->id,
            'period_start' => '2026-09-28',
            'period_end' => '2026-10-02',
        ]);
        $this->stamp('internship_reports', $report->id, ['reviewed_at' => '2026-10-01 18:00:00', 'created_at' => '2026-10-01 01:00:00']);

        $evaluation = Evaluation::factory()->create([
            'student_id' => $this->student->id,
            'evaluation_period_start' => '2026-09-01',
            'evaluation_period_end' => '2026-09-30',
            'status' => 'locked',
        ]);
        $this->stamp('evaluations', $evaluation->id, ['submitted_at' => '2026-09-30 15:00:00', 'locked_at' => '2026-09-30 23:30:00']);

        $notification = Notification::factory()->create(['user_id' => $this->user->id]);
        $this->stamp('notifications', $notification->id, ['read_at' => '2026-10-02 04:25:22', 'created_at' => '2026-10-02 04:02:55']);

        $this->artisan('app:shift-utc-to-manila')->assertSuccessful();

        $sameDayRow = $this->row('attendances', $sameDay->id);
        $this->assertSame('2026-10-02', $sameDayRow->date);
        $this->assertSame('12:02:55', $sameDayRow->time_in);
        $this->assertNull($sameDayRow->time_out);
        $this->assertSame('2026-10-02 12:02:55', $sameDayRow->created_at);
        $this->assertSame('2026-10-02 12:02:55', $sameDayRow->updated_at);

        $rolloverRow = $this->row('attendances', $rollover->id);
        $this->assertSame('2026-10-01', $rolloverRow->date);
        $this->assertSame('00:30:00', $rolloverRow->time_in);
        $this->assertSame('04:00:00', $rolloverRow->time_out);
        $this->assertEquals(3.5, $rolloverRow->rendered_hours);

        $userRow = $this->row('users', $this->user->id);
        $this->assertSame('2026-09-01 18:00:00', $userRow->email_verified_at);
        $this->assertSame('2026-09-01 17:00:00', $userRow->created_at);
        $this->assertSame('2026-09-01 17:30:00', $userRow->updated_at);

        $reportRow = $this->row('internship_reports', $report->id);
        $this->assertSame('2026-10-02 02:00:00', $reportRow->reviewed_at);
        $this->assertSame('2026-10-01 09:00:00', $reportRow->created_at);
        $this->assertSame('2026-09-28', $reportRow->period_start);
        $this->assertSame('2026-10-02', $reportRow->period_end);

        $evaluationRow = $this->row('evaluations', $evaluation->id);
        $this->assertSame('2026-09-30 23:00:00', $evaluationRow->submitted_at);
        $this->assertSame('2026-10-01 07:30:00', $evaluationRow->locked_at);
        $this->assertSame('2026-09-01', $evaluationRow->evaluation_period_start);
        $this->assertSame('2026-09-30', $evaluationRow->evaluation_period_end);

        $notificationRow = $this->row('notifications', $notification->id);
        $this->assertSame('2026-10-02 12:25:22', $notificationRow->read_at);
        $this->assertSame('2026-10-02 12:02:55', $notificationRow->created_at);

        $this->assertDatabaseHas('data_fixes', ['name' => ShiftUtcToManila::MARKER]);
    }

    public function test_consecutive_rollovers_for_one_student_do_not_trip_the_unique_index(): void
    {
        // Both late-evening UTC rows move forward a day; the later one has
        // to move first or the earlier one would briefly duplicate it.
        $first = $this->attendance(['date' => '2026-09-29', 'time_in' => '18:00:00', 'time_out' => '20:00:00']);
        $second = $this->attendance(['date' => '2026-09-30', 'time_in' => '18:00:00', 'time_out' => '20:00:00']);

        $this->artisan('app:shift-utc-to-manila')->assertSuccessful();

        $this->assertSame('2026-09-30', $this->row('attendances', $first->id)->date);
        $this->assertSame('2026-10-01', $this->row('attendances', $second->id)->date);
    }

    public function test_would_be_collision_is_reported_and_nothing_is_written(): void
    {
        // 18:00 UTC on the 29th and 01:00 UTC on the 30th both land on the
        // 30th in Manila.
        $late = $this->attendance(['date' => '2026-09-29', 'time_in' => '18:00:00', 'time_out' => null]);
        $early = $this->attendance(['date' => '2026-09-30', 'time_in' => '01:00:00', 'time_out' => null]);

        $userBefore = $this->row('users', $this->user->id);

        $this->artisan('app:shift-utc-to-manila')
            ->expectsOutputToContain("student {$this->student->id} on 2026-09-30: attendance ids")
            ->assertFailed();

        $this->assertSame('18:00:00', $this->row('attendances', $late->id)->time_in);
        $this->assertSame('2026-09-30', $this->row('attendances', $early->id)->date);
        $this->assertSame('01:00:00', $this->row('attendances', $early->id)->time_in);
        $this->assertEquals($userBefore, $this->row('users', $this->user->id));
        $this->assertDatabaseMissing('data_fixes', ['name' => ShiftUtcToManila::MARKER]);
    }

    public function test_second_run_refuses_and_changes_nothing(): void
    {
        $attendance = $this->attendance(['date' => '2026-10-02', 'time_in' => '04:02:55', 'time_out' => null]);

        $this->artisan('app:shift-utc-to-manila')->assertSuccessful();

        $afterFirst = $this->row('attendances', $attendance->id);
        $userAfterFirst = $this->row('users', $this->user->id);

        $this->artisan('app:shift-utc-to-manila')
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();

        $this->artisan('app:shift-utc-to-manila', ['--dry-run' => true])->assertFailed();

        $this->assertSame('12:02:55', $afterFirst->time_in);
        $this->assertEquals($afterFirst, $this->row('attendances', $attendance->id));
        $this->assertEquals($userAfterFirst, $this->row('users', $this->user->id));
        $this->assertSame(1, DB::table('data_fixes')->where('name', ShiftUtcToManila::MARKER)->count());
    }
}
