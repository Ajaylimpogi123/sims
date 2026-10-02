<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Who may see / act on a student record. Route-level `role:` middleware is
 * still the first gate; these rules are the per-record second gate.
 *
 * Supervisors are always scoped to their own students via
 * students.supervisor_id. Coordinator/Administrator are program-wide, but a
 * Coordinator is read-only on the supervision modules (attendance
 * monitoring, evaluations).
 */
class StudentPolicy
{
    /**
     * Viewing a student: the student themself, their own supervisor, or a
     * Coordinator/Administrator. Mirrors Student::scopeVisibleTo().
     */
    public function view(User $user, Student $student): bool
    {
        return match ((int) $user->role_id) {
            User::ROLE_STUDENT => (int) $student->user_id === (int) $user->id,
            User::ROLE_SUPERVISOR => $user->supervises($student),
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => true,
            default => false,
        };
    }

    /**
     * Attendance Monitoring writes for a student — set required hours, add /
     * edit / delete attendance entries: the student's own supervisor or an
     * Administrator. Coordinator is view-only.
     */
    public function manageAttendance(User $user, Student $student): bool
    {
        return $this->supervisesOrAdmin($user, $student);
    }

    /**
     * Creating an evaluation for a student: the student's own supervisor or
     * an Administrator. Coordinator is view-only.
     */
    public function evaluate(User $user, Student $student): bool
    {
        return $this->supervisesOrAdmin($user, $student);
    }

    private function supervisesOrAdmin(User $user, Student $student): bool
    {
        return $user->hasRole(User::ROLE_ADMIN) || $user->supervises($student);
    }
}
