<?php

namespace Tests\Feature\Policies;

use App\Models\InternshipReport;
use App\Models\User;

class InternshipReportPolicyTest extends PolicyTestCase
{
    public function test_view_matrix_covers_attachment_downloads(): void
    {
        $report = InternshipReport::factory()->create(['student_id' => $this->student->id]);

        $this->assertAbilityMatrix('view', $report, ['studentUser', 'supervisor', 'coordinator', 'admin']);
    }

    public function test_only_the_owning_student_can_edit_or_delete_a_pending_report(): void
    {
        $report = InternshipReport::factory()->create(['student_id' => $this->student->id, 'status' => 'pending']);

        $this->assertAbilityMatrix('update', $report, ['studentUser']);
        $this->assertAbilityMatrix('delete', $report, ['studentUser']);
    }

    public function test_nobody_can_edit_or_delete_a_reviewed_report(): void
    {
        $report = InternshipReport::factory()->reviewed()->create(['student_id' => $this->student->id]);

        $this->assertAbilityMatrix('update', $report, []);
        $this->assertAbilityMatrix('delete', $report, []);
    }

    public function test_review_is_coordinator_or_admin_only_supervisor_is_view_only(): void
    {
        $report = InternshipReport::factory()->create(['student_id' => $this->student->id]);

        $this->assertAbilityMatrix('review', $report, ['coordinator', 'admin']);
    }

    public function test_visible_to_scope(): void
    {
        $own = InternshipReport::factory()->create(['student_id' => $this->student->id]);
        $other = InternshipReport::factory()->create(['student_id' => $this->otherStudent->id]);
        $ids = fn (User $user) => InternshipReport::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
        $all = collect([$own->id, $other->id])->sort()->values()->all();

        $this->assertSame([$own->id], $ids($this->supervisor));
        $this->assertSame([$other->id], $ids($this->otherSupervisor));
        $this->assertSame([$own->id], $ids($this->studentUser));
        $this->assertSame([], $ids($this->profilelessStudent));
        $this->assertSame($all, $ids($this->coordinator));
        $this->assertSame($all, $ids($this->admin));
    }
}
