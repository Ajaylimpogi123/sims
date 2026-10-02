<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * List scoping for records that belong to a student (attendances, internship
 * reports, evaluations): visible exactly when the owning student is visible
 * to the user (see Student::scopeVisibleTo). Coordinator/Administrator are
 * unscoped, so no student subquery is added for them.
 *
 * This is ownership only — status filters (e.g. a student only seeing
 * submitted evaluations) stay with the caller.
 */
trait VisibleThroughStudent
{
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ((int) $user->role_id) {
            User::ROLE_COORDINATOR, User::ROLE_ADMIN => $query,
            User::ROLE_SUPERVISOR, User::ROLE_STUDENT => $query->whereHas(
                'student',
                fn (Builder $student) => $student->visibleTo($user),
            ),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
