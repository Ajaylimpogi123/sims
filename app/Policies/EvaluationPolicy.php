<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Per-record rules for supervisor evaluations. Route-level `role:`
 * middleware is still the first gate.
 *
 * Supervisor scoping follows the *current* assignment
 * (evaluation->student->supervisor_id), not the evaluations.supervisor_id
 * snapshot taken when the draft was created.
 *
 * Creating an evaluation is authorized on the student
 * (StudentPolicy::evaluate), since there is no evaluation record yet.
 */
class EvaluationPolicy
{
    /** Statuses a student can see on My Feedback. */
    public const STUDENT_VISIBLE_STATUSES = ['submitted', 'locked'];

    /**
     * Viewing: the student's own supervisor or a Coordinator/Administrator;
     * the evaluated student once it is submitted or locked (My Feedback).
     */
    public function view(User $user, Evaluation $evaluation): bool
    {
        return match ((int) $user->role_id) {
            User::ROLE_STUDENT => $user->student !== null
                && (int) $evaluation->student_id === (int) $user->student->id
                && in_array($evaluation->status, self::STUDENT_VISIBLE_STATUSES, true),
            User::ROLE_SUPERVISOR => $user->supervises($evaluation->student),
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => true,
            default => false,
        };
    }

    /**
     * Editing a draft: the student's own supervisor or an Administrator, and
     * only while the evaluation is a draft. Also drives the `canEdit` prop.
     */
    public function update(User $user, Evaluation $evaluation): Response|bool
    {
        if (! $this->canMutate($user, $evaluation)) {
            return false;
        }

        return $evaluation->status === 'draft'
            ? true
            : Response::deny('Only draft evaluations can be edited.');
    }

    /**
     * Submitting a draft: same people as editing.
     */
    public function submit(User $user, Evaluation $evaluation): Response|bool
    {
        if (! $this->canMutate($user, $evaluation)) {
            return false;
        }

        return $evaluation->status === 'draft'
            ? true
            : Response::deny('Only draft evaluations can be submitted.');
    }

    /**
     * Locking a submitted evaluation: Administrator only.
     */
    public function lock(User $user, Evaluation $evaluation): Response|bool
    {
        if (! $user->hasRole(User::ROLE_ADMIN)) {
            return false;
        }

        return $evaluation->status === 'submitted'
            ? true
            : Response::deny('Only submitted evaluations can be locked.');
    }

    /**
     * Reopening a submitted or locked evaluation back to draft:
     * Administrator only.
     */
    public function reopen(User $user, Evaluation $evaluation): Response|bool
    {
        if (! $user->hasRole(User::ROLE_ADMIN)) {
            return false;
        }

        return in_array($evaluation->status, ['submitted', 'locked'], true)
            ? true
            : Response::deny('Only submitted or locked evaluations can be reopened.');
    }

    /**
     * Coordinator is view-only; Supervisor only for their own students.
     */
    private function canMutate(User $user, Evaluation $evaluation): bool
    {
        return $user->hasRole(User::ROLE_ADMIN) || $user->supervises($evaluation->student);
    }
}
