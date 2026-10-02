<?php

namespace Tests\Feature\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicyTest extends PolicyTestCase
{
    private Attendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $this->student->id]);
    }

    public function test_view_matrix_covers_evidence_photos(): void
    {
        $this->assertAbilityMatrix('view', $this->attendance, ['studentUser', 'supervisor', 'coordinator', 'admin']);
    }

    public function test_review_is_own_supervisor_or_admin_and_never_coordinator(): void
    {
        $this->assertAbilityMatrix('review', $this->attendance, ['supervisor', 'admin']);
    }

    public function test_monitoring_update_and_delete_are_own_supervisor_or_admin(): void
    {
        $this->assertAbilityMatrix('update', $this->attendance, ['supervisor', 'admin']);
        $this->assertAbilityMatrix('delete', $this->attendance, ['supervisor', 'admin']);
    }

    public function test_reassigning_the_student_moves_access_to_the_new_supervisor(): void
    {
        $this->student->update(['supervisor_id' => $this->otherSupervisor->id]);

        $this->assertAbilityMatrix('review', $this->attendance->fresh(), ['otherSupervisor', 'admin']);
    }

    public function test_visible_to_scope(): void
    {
        $other = Attendance::factory()->create(['student_id' => $this->otherStudent->id]);
        $ids = fn (User $user) => Attendance::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
        $all = collect([$this->attendance->id, $other->id])->sort()->values()->all();

        $this->assertSame([$this->attendance->id], $ids($this->supervisor));
        $this->assertSame([$other->id], $ids($this->otherSupervisor));
        $this->assertSame([$this->attendance->id], $ids($this->studentUser));
        $this->assertSame([], $ids($this->profilelessStudent));
        $this->assertSame($all, $ids($this->coordinator));
        $this->assertSame($all, $ids($this->admin));
    }
}
