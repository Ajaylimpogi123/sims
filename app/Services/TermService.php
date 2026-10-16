<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Rules\NotBoolean;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Term management (Internship Coordinator, Administrator), shared by the
 * website and the mobile API.
 *
 * - A term is a School Year (its start year) + semester; the pair is unique.
 * - Exactly one term is current: setCurrent() unmarks the others in the
 *   same transaction, and notifies active staff and supervisors.
 * - A term can't be deleted while students are enrolled in it, nor while
 *   it is the current term.
 */
class TermService
{
    public const HAS_STUDENTS = 'term_has_students';

    public const IS_CURRENT = 'term_is_current';

    public const DELETE_MESSAGES = [
        self::HAS_STUDENTS => 'This term cannot be deleted while students are enrolled in it.',
        self::IS_CURRENT => 'The current term cannot be deleted.',
    ];

    public const DUPLICATE_MESSAGE = 'That school year and semester already exists.';

    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    public function __construct(private NotificationService $notifications) {}

    /**
     * Every term, newest first, with students_count.
     *
     * @return Collection<int, Term>
     */
    public function list(): Collection
    {
        return Term::query()->withCount('students')->newestFirst()->get();
    }

    /**
     * The semester options for the forms.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function semesterOptions(): array
    {
        return collect(Term::SEMESTERS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Create / edit rules. The (school year, semester) pair is unique; a
     * duplicate is reported on school_year_start.
     *
     * @param  array<string, mixed>  $input  the raw input (for the pair check)
     * @return array<string, array<int, mixed>>
     */
    public function rules(array $input, ?Term $term = null): array
    {
        $semester = is_string($input['semester'] ?? null) ? $input['semester'] : null;

        return [
            'school_year_start' => [
                'bail',
                'required',
                new NotBoolean,
                'integer',
                'min:'.self::MIN_YEAR,
                'max:'.self::MAX_YEAR,
                function (string $attribute, mixed $value, \Closure $fail) use ($semester, $term) {
                    if ($semester === null) {
                        return;
                    }

                    $taken = Term::query()
                        ->where('school_year_start', (int) $value)
                        ->where('semester', $semester)
                        ->when($term !== null, fn ($q) => $q->whereKeyNot($term->id))
                        ->exists();

                    if ($taken) {
                        $fail(self::DUPLICATE_MESSAGE);
                    }
                },
            ],
            'semester' => ['required', 'string', Rule::in(array_keys(Term::SEMESTERS))],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', Rule::when(
                fn ($data) => is_string($data->starts_on ?? null) && $data->starts_on !== '',
                ['after_or_equal:starts_on'],
            )],
        ];
    }

    /**
     * @param  array  $data  validated rules() data
     */
    public function create(array $data): Term
    {
        return Term::create(self::attributes($data) + ['is_current' => false]);
    }

    /**
     * @param  array  $data  validated rules() data
     */
    public function update(Term $term, array $data): Term
    {
        $term->update(self::attributes($data));

        return $term->refresh();
    }

    /**
     * Deletes the term, or returns why it can't be deleted (without
     * deleting). The term is locked, and its students read under a lock, so
     * a registration racing the delete either lands first (and blocks it)
     * or fails on the foreign key.
     */
    public function delete(Term $term): ?string
    {
        return DB::transaction(function () use ($term) {
            $locked = Term::query()->lockForUpdate()->find($term->id);

            if ($locked === null) {
                return null;
            }

            $blocker = match (true) {
                $locked->is_current => self::IS_CURRENT,
                Student::query()->where('term_id', $locked->id)->lockForUpdate()->exists() => self::HAS_STUDENTS,
                default => null,
            };

            if ($blocker === null) {
                $locked->delete();
            }

            return $blocker;
        });
    }

    /**
     * Makes the term the (only) current one. Active Coordinators,
     * Administrators and Supervisors are notified after commit, unless it
     * already was current.
     */
    public function setCurrent(Term $term): Term
    {
        $changed = DB::transaction(function () use ($term) {
            // Every term row locked, so two concurrent switches serialise
            // and exactly one term ends up current.
            $terms = Term::query()->lockForUpdate()->get(['id', 'is_current']);
            $target = $terms->firstWhere('id', $term->id)
                ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Term::class, [$term->id]);

            if ($target->is_current && $terms->where('is_current', true)->count() === 1) {
                return false;
            }

            Term::query()->whereKeyNot($term->id)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
            Term::query()->whereKey($term->id)->update(['is_current' => true, 'updated_at' => now()]);

            return true;
        });

        Term::flushCurrent();
        $term->refresh();

        if ($changed) {
            $this->notifications->termChanged($term);
        }

        return $term;
    }

    /**
     * The Term object both UIs read. Staff (Coordinator, Administrator)
     * also get students_count, can_edit and can_delete (students_count
     * must be loaded).
     *
     * @return array<string, mixed>
     */
    public static function present(Term $term, ?User $viewer = null): array
    {
        $data = [
            'id' => $term->id,
            'label' => $term->label,
            'school_year_start' => (int) $term->school_year_start,
            'semester' => $term->semester,
            'semester_label' => $term->semester_label,
            'starts_on' => $term->starts_on?->format('Y-m-d'),
            'ends_on' => $term->ends_on?->format('Y-m-d'),
            'is_current' => $term->isCurrent(),
            // Before the current term: read-only but for the Administrator.
            'is_past' => $term->isPast(),
        ];

        if ($viewer?->hasRole(User::ROLE_COORDINATOR, User::ROLE_ADMIN)) {
            $count = (int) ($term->students_count ?? $term->students()->count());

            $data += [
                'students_count' => $count,
                'can_edit' => true,
                'can_delete' => $count === 0 && ! $term->isCurrent(),
            ];
        }

        return $data;
    }

    private static function attributes(array $data): array
    {
        return [
            'school_year_start' => (int) $data['school_year_start'],
            'semester' => $data['semester'],
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ];
    }
}
