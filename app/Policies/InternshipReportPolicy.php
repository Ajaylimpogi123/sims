<?php

namespace App\Policies;

use App\Models\InternshipReport;
use App\Models\User;

/**
 * Per-record rules for internship reports. Route-level `role:` middleware is
 * still the first gate.
 */
class InternshipReportPolicy
{
    /**
     * Viewing a report and downloading its attachment: the owning student,
     * the student's own supervisor, or a Coordinator/Administrator.
     */
    public function view(User $user, InternshipReport $report): bool
    {
        return match ((int) $user->role_id) {
            User::ROLE_STUDENT => $this->ownedBy($user, $report),
            User::ROLE_SUPERVISOR => $user->supervises($report->student),
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => true,
            default => false,
        };
    }

    /**
     * Editing a report: only its owning student, and only while it is still
     * pending review.
     */
    public function update(User $user, InternshipReport $report): bool
    {
        return $user->hasRole(User::ROLE_STUDENT)
            && $this->ownedBy($user, $report)
            && $report->status === 'pending';
    }

    /**
     * Deleting a report: same rule as editing.
     */
    public function delete(User $user, InternshipReport $report): bool
    {
        return $this->update($user, $report);
    }

    /**
     * Submitting a review: Coordinator/Administrator only. Supervisors are
     * view-only on report reviews.
     */
    public function review(User $user, InternshipReport $report): bool
    {
        return $user->hasRole(User::ROLE_COORDINATOR, User::ROLE_ADMIN);
    }

    private function ownedBy(User $user, InternshipReport $report): bool
    {
        return $user->student !== null
            && (int) $report->student_id === (int) $user->student->id;
    }
}
