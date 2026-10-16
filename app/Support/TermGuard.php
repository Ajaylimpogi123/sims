<?php

namespace App\Support;

use App\Exceptions\TermArchivedException;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Past terms are read-only (owner decision, 2026-10-16): a record whose
 * student is enrolled in a non-current term may only be changed by an
 * Administrator. Called at the top of every staff write path
 * (assignment, attendance review / monitoring entries, required hours,
 * report review, evaluations); students' own submissions are closed by
 * Student::canSubmitWork() instead.
 */
final class TermGuard
{
    /**
     * Whether the actor may change the student's records. No actor (console
     * / system work) is never refused.
     */
    public static function isWritable(Student $student, ?User $actor = null): bool
    {
        $actor ??= Auth::user();

        if ($actor === null || $actor->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return $student->inCurrentTerm();
    }

    /**
     * @throws TermArchivedException
     */
    public static function ensureWritable(Student $student, ?User $actor = null): void
    {
        if (! self::isWritable($student, $actor)) {
            throw TermArchivedException::for($student);
        }
    }

    /**
     * Whether the actor may write in the given (selected) term at all: the
     * list-level "can create" flags.
     */
    public static function termWritable(?int $termId, ?User $actor = null): bool
    {
        $actor ??= Auth::user();

        if ($actor === null || $actor->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        $currentId = Term::currentId();

        return $termId === null || $currentId === null || $termId === $currentId;
    }
}
