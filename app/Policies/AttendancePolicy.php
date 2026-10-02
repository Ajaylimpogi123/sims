<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

/**
 * Per-record rules for attendance. Route-level `role:` middleware is still
 * the first gate.
 *
 * Self-service time-in / time-out / emergency time-out has no policy
 * ability: it always acts on the signed-in student's own record for today,
 * so there is no foreign ID to authorize (AttendanceService enforces the
 * state rules).
 */
class AttendancePolicy
{
    public function __construct(private StudentPolicy $students) {}

    /**
     * Viewing a record and its evidence photos: the owning student, the
     * student's own supervisor, or a Coordinator/Administrator.
     */
    public function view(User $user, Attendance $attendance): bool
    {
        return match ((int) $user->role_id) {
            User::ROLE_STUDENT => $user->student !== null
                && (int) $attendance->student_id === (int) $user->student->id,
            User::ROLE_SUPERVISOR => $user->supervises($attendance->student),
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => true,
            default => false,
        };
    }

    /**
     * Approving / rejecting a time-in or time-out: the student's own
     * supervisor or an Administrator. Coordinator has no access to approvals.
     */
    public function review(User $user, Attendance $attendance): bool
    {
        return $user->hasRole(User::ROLE_ADMIN) || $user->supervises($attendance->student);
    }

    /**
     * Editing an entry in Attendance Monitoring (Coordinator is view-only).
     */
    public function update(User $user, Attendance $attendance): bool
    {
        return $attendance->student !== null
            && $this->students->manageAttendance($user, $attendance->student);
    }

    /**
     * Deleting an entry in Attendance Monitoring (Coordinator is view-only).
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->update($user, $attendance);
    }
}
