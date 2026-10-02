<?php

namespace Tests\Feature\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicyTest extends PolicyTestCase
{
    public function test_view_matrix(): void
    {
        $this->assertAbilityMatrix('view', $this->student, ['studentUser', 'supervisor', 'coordinator', 'admin']);
        $this->assertAbilityMatrix('view', $this->otherStudent, ['otherStudentUser', 'otherSupervisor', 'coordinator', 'admin']);
    }

    public function test_manage_attendance_is_own_supervisor_or_admin_only(): void
    {
        $this->assertAbilityMatrix('manageAttendance', $this->student, ['supervisor', 'admin']);
        $this->assertAbilityMatrix('manageAttendance', $this->otherStudent, ['otherSupervisor', 'admin']);
    }

    public function test_evaluate_is_own_supervisor_or_admin_only(): void
    {
        $this->assertAbilityMatrix('evaluate', $this->student, ['supervisor', 'admin']);
        $this->assertAbilityMatrix('evaluate', $this->otherStudent, ['otherSupervisor', 'admin']);
    }

    public function test_unassigned_student_is_only_manageable_by_admin(): void
    {
        $unassigned = Student::factory()->create(['supervisor_id' => null]);

        $this->assertAbilityMatrix('manageAttendance', $unassigned, ['admin']);
        $this->assertAbilityMatrix('evaluate', $unassigned, ['admin']);
        $this->assertAbilityMatrix('view', $unassigned, ['coordinator', 'admin']);
    }

    public function test_supervisor_id_pointing_at_a_non_supervisor_grants_that_user_nothing(): void
    {
        // A coordinator id stored in supervisor_id must not turn into
        // supervisor-style ownership; the coordinator keeps coordinator rights.
        $odd = Student::factory()->create(['supervisor_id' => $this->coordinator->id]);

        $this->assertAbilityMatrix('manageAttendance', $odd, ['admin']);
    }

    public function test_visible_to_scope_matches_the_view_rule(): void
    {
        $ids = fn (User $user) => Student::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
        $all = Student::query()->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->student->id], $ids($this->supervisor));
        $this->assertSame([$this->otherStudent->id], $ids($this->otherSupervisor));
        $this->assertSame([$this->student->id], $ids($this->studentUser));
        $this->assertSame([], $ids($this->profilelessStudent));
        $this->assertSame($all, $ids($this->coordinator));
        $this->assertSame($all, $ids($this->admin));
    }
}
