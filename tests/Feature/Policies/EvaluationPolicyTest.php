<?php

namespace Tests\Feature\Policies;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class EvaluationPolicyTest extends PolicyTestCase
{
    private function evaluation(string $status): Evaluation
    {
        return Evaluation::factory()->create([
            'student_id' => $this->student->id,
            'supervisor_id' => $this->supervisor->id,
            'status' => $status,
        ]);
    }

    public function test_view_matrix_per_status(): void
    {
        // A student only sees their evaluation once it is submitted or locked.
        $this->assertAbilityMatrix('view', $this->evaluation('draft'), ['supervisor', 'coordinator', 'admin']);
        $this->assertAbilityMatrix('view', $this->evaluation('submitted'), ['studentUser', 'supervisor', 'coordinator', 'admin']);
        $this->assertAbilityMatrix('view', $this->evaluation('locked'), ['studentUser', 'supervisor', 'coordinator', 'admin']);
    }

    public function test_update_and_submit_are_own_supervisor_or_admin_on_drafts_only(): void
    {
        $draft = $this->evaluation('draft');

        $this->assertAbilityMatrix('update', $draft, ['supervisor', 'admin']);
        $this->assertAbilityMatrix('submit', $draft, ['supervisor', 'admin']);

        foreach (['submitted', 'locked'] as $status) {
            $evaluation = $this->evaluation($status);

            $this->assertAbilityMatrix('update', $evaluation, []);
            $this->assertAbilityMatrix('submit', $evaluation, []);
        }
    }

    public function test_lock_is_admin_only_on_submitted(): void
    {
        $this->assertAbilityMatrix('lock', $this->evaluation('submitted'), ['admin']);
        $this->assertAbilityMatrix('lock', $this->evaluation('draft'), []);
        $this->assertAbilityMatrix('lock', $this->evaluation('locked'), []);
    }

    public function test_reopen_is_admin_only_on_submitted_or_locked(): void
    {
        $this->assertAbilityMatrix('reopen', $this->evaluation('submitted'), ['admin']);
        $this->assertAbilityMatrix('reopen', $this->evaluation('locked'), ['admin']);
        $this->assertAbilityMatrix('reopen', $this->evaluation('draft'), []);
    }

    public function test_supervisor_scope_follows_the_current_assignment_not_the_snapshot(): void
    {
        $draft = $this->evaluation('draft');

        $this->student->update(['supervisor_id' => $this->otherSupervisor->id]);

        $this->assertAbilityMatrix('update', $draft->fresh(), ['otherSupervisor', 'admin']);
    }

    public function test_state_denials_carry_the_same_messages_as_before(): void
    {
        $submitted = $this->evaluation('submitted');
        $locked = $this->evaluation('locked');
        $draft = $this->evaluation('draft');

        $supervisorGate = Gate::forUser($this->supervisor);
        $adminGate = Gate::forUser($this->admin);

        $this->assertSame('Only draft evaluations can be edited.', $supervisorGate->inspect('update', $submitted)->message());
        $this->assertSame('Only draft evaluations can be submitted.', $supervisorGate->inspect('submit', $submitted)->message());
        $this->assertSame('Only submitted evaluations can be locked.', $adminGate->inspect('lock', $locked)->message());
        $this->assertSame('Only submitted or locked evaluations can be reopened.', $adminGate->inspect('reopen', $draft)->message());

        // Scope denials carry no message, like the abort(403) they replaced.
        // (Uses the app gate, as $this->authorize() does — Gate::forUser()
        // does not copy the default denial response.)
        $this->actingAs($this->otherSupervisor);
        $denied = Gate::inspect('update', $draft);
        $this->assertTrue($denied->denied());
        $this->assertSame('', $denied->message());
    }

    public function test_a_scope_denial_renders_like_the_old_bare_abort_403(): void
    {
        $draft = $this->evaluation('draft');

        $this->actingAs($this->otherSupervisor)
            ->patchJson(route('supervisor-evaluations.submit', $draft))
            ->assertForbidden()
            ->assertJsonPath('message', '');

        $this->actingAs($this->supervisor)
            ->patchJson(route('supervisor-evaluations.submit', $this->evaluation('submitted')))
            ->assertForbidden()
            ->assertJsonPath('message', 'Only draft evaluations can be submitted.');
    }

    public function test_visible_to_scope(): void
    {
        $own = $this->evaluation('draft');
        $other = Evaluation::factory()->create(['student_id' => $this->otherStudent->id]);
        $ids = fn (User $user) => Evaluation::query()->visibleTo($user)->pluck('id')->sort()->values()->all();
        $all = collect([$own->id, $other->id])->sort()->values()->all();

        $this->assertSame([$own->id], $ids($this->supervisor));
        $this->assertSame([$other->id], $ids($this->otherSupervisor));
        $this->assertSame($all, $ids($this->coordinator));
        $this->assertSame($all, $ids($this->admin));
    }
}
