<?php

namespace App\Services;

use App\Exceptions\AssignmentRuleException;
use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Internship Assignment (Internship Coordinator, Administrator), shared by
 * the website and the mobile API: one save covering a student's profile
 * fields and their assignment (company, supervisor, internship status,
 * schedule), plus the account status toggle.
 *
 * - A company other than the student's current one needs a free slot.
 * - A supervisor must be on the chosen company's roster (company_supervisors)
 *   when the company or supervisor changes. The roster and
 *   students.supervisor_id can drift apart; an unchanged, drifted
 *   assignment doesn't block editing the other fields.
 * - New assignments to an inactive company or an inactive supervisor are
 *   refused; students already there keep them.
 * - All of the above are checked under a lock on the target company (the
 *   same row CompanyManagementService locks for edits and deletes).
 * - Changing company, supervisor or internship status notifies the student
 *   and their supervisor.
 * - Deactivating the account revokes the student's API tokens.
 */
class InternshipAssignmentService
{
    public const COMPANY_FULL = 'company_full';

    public const COMPANY_INACTIVE = 'company_inactive';

    public const SUPERVISOR_NEEDS_COMPANY = 'supervisor_needs_company';

    public const SUPERVISOR_NOT_ON_ROSTER = 'supervisor_not_on_roster';

    public const SUPERVISOR_INACTIVE = 'supervisor_inactive';

    public const NEEDS_COMPANY_MESSAGE = 'Assign a company before assigning a supervisor.';

    public const NOT_ON_ROSTER_MESSAGE = 'This supervisor is not on the selected company\'s roster.';

    public const SUPERVISOR_INACTIVE_MESSAGE = 'This supervisor\'s account is inactive. Activate it in User Management before assigning students to them.';

    /** internship_status values with the website's labels. */
    public const STATUSES = [
        'not_started' => 'Not Started',
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
    ];

    public function __construct(private NotificationService $notifications) {}

    /**
     * Students with the list filters applied, newest profile first, with
     * their user, company and supervisor.
     *
     * company_id / supervisor_id: an id, or 'none' for unassigned.
     *
     * @param  array{search?: mixed, internship_status?: mixed, status?: mixed, company_id?: mixed, supervisor_id?: mixed}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        $internshipStatus = $filters['internship_status'] ?? null;
        $status = $filters['status'] ?? null;

        $query = Student::query()
            ->with([
                'user:id,name,email,status',
                'company:id,company_name,status',
                'supervisor:id,name,email,status',
            ])
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('student_number', 'like', $like)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->when(is_string($internshipStatus) && array_key_exists($internshipStatus, self::STATUSES),
                fn (Builder $query) => $query->where('internship_status', $internshipStatus))
            ->when(in_array($status, ['active', 'inactive'], true),
                fn (Builder $query) => $query->whereHas('user', fn (Builder $u) => $u->where('status', $status)));

        foreach (['company_id', 'supervisor_id'] as $column) {
            $value = $filters[$column] ?? null;

            if ($value === 'none') {
                $query->whereNull($column);
            } elseif (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $query->where($column, (int) $value);
            }
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Every company for the assignment form, by name: slots,
     * students_count, status and the roster (by name, with status).
     */
    public function companyOptions(): Collection
    {
        return Company::query()
            ->withCount('students')
            ->with(['supervisors' => fn ($q) => $q
                ->select('users.id', 'users.name', 'users.email', 'users.status')
                ->orderBy('users.name')
                ->orderBy('users.id'),
            ])
            ->orderBy('company_name')
            ->orderBy('id')
            ->get(['id', 'company_name', 'slots', 'status']);
    }

    /**
     * Sets `supervisor_on_roster` on each student: whether their supervisor
     * is on their company's roster (null without a supervisor).
     *
     * @param  iterable<int, Student>  $students
     */
    public function loadRosterFlags(iterable $students): void
    {
        $students = Collection::make($students);

        $pairs = DB::table('company_supervisors')
            ->whereIn('company_id', $students->pluck('company_id')->filter()->unique()->values())
            ->get(['company_id', 'user_id'])
            ->mapWithKeys(fn ($row) => [$row->company_id.':'.$row->user_id => true]);

        foreach ($students as $student) {
            $student->setAttribute('supervisor_on_roster', $student->supervisor_id === null
                ? null
                : isset($pairs[$student->company_id.':'.$student->supervisor_id]));
        }
    }

    /**
     * A blank company / supervisor ("" or null; the website sends "")
     * means "none". Anything else is validated as an id, so `true`, `0`
     * or an array is a 422 rather than being read as an id or as "none".
     */
    public function prepare(Request $request): void
    {
        foreach (['company_id', 'supervisor_id'] as $key) {
            if ($request->input($key) === '') {
                $request->merge([$key => null]);
            }
        }
    }

    /**
     * The edit form's rules. With $rosterCheck the roster is also checked
     * while validating (the website, so it shows next to the other field
     * errors); update() re-checks it under the lock either way.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(Student $student, Request $request, bool $rosterCheck = true): array
    {
        // The company this student will end up with once this request is
        // applied: supervisor_id is validated against *this*, not the
        // student's current company, so changing both at once works.
        $resultingCompanyId = $request->input('company_id');

        $supervisorRules = ['bail', 'nullable', self::idRule(), 'integer', 'min:1', Rule::exists('users', 'id')->where('role_id', User::ROLE_SUPERVISOR)];

        if ($rosterCheck) {
            $supervisorRules[] = function (string $attribute, mixed $value, \Closure $fail) use ($resultingCompanyId, $student) {
                if (! $value || ($resultingCompanyId !== null && ! is_scalar($resultingCompanyId))) {
                    return;
                }

                if (! $this->assignmentChanges($student, $resultingCompanyId, $value)) {
                    return;
                }

                if (! $resultingCompanyId) {
                    $fail(self::NEEDS_COMPANY_MESSAGE);

                    return;
                }

                if (! $this->onRoster($resultingCompanyId, $value)) {
                    $fail(self::NOT_ON_ROSTER_MESSAGE);
                }
            };
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->user_id)],
            'student_number' => ['bail', 'required', 'string', 'max:50', Rule::unique('students', 'student_number')->ignore($student->id)],
            'course' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'company_id' => ['bail', 'nullable', self::idRule(), 'integer', 'min:1', 'exists:companies,id'],
            'supervisor_id' => $supervisorRules,
            'internship_status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))],
            'internship_schedule' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Applies a validated edit under locks (target company, then the
     * student) and notifies on assignment changes.
     *
     * @param  array  $data  validated rules() data
     *
     * @throws AssignmentRuleException a slot / roster / inactive rule refused it
     * @throws ValidationException the company or supervisor vanished meanwhile
     * @throws ModelNotFoundException the student was deleted meanwhile
     */
    public function update(Student $student, array $data): Student
    {
        $companyId = $data['company_id'] ? (int) $data['company_id'] : null;
        $supervisorId = $data['supervisor_id'] ? (int) $data['supervisor_id'] : null;

        try {
            $notify = DB::transaction(function () use ($student, $data, $companyId, $supervisorId) {
                // Lock the target company first, so this save is serialised
                // with other assignments to it (slot count), with its status
                // changes and with a company delete
                // (CompanyManagementService::delete locks the same row first).
                $company = $companyId !== null ? Company::query()->lockForUpdate()->find($companyId) : null;

                if ($companyId !== null && $company === null) {
                    throw ValidationException::withMessages([
                        'company_id' => __('validation.exists', ['attribute' => 'company id']),
                    ]);
                }

                $current = Student::query()->lockForUpdate()->find($student->id)
                    ?? throw (new ModelNotFoundException)->setModel(Student::class, [$student->id]);

                $this->checkRules($current, $company, $supervisorId);

                // The "assignment" itself, as opposed to the student's own
                // profile fields: only changes to these are worth notifying
                // the student / supervisor about.
                $hasAssignmentChanges = collect([
                    'company_id' => $companyId,
                    'supervisor_id' => $supervisorId,
                    'internship_status' => $data['internship_status'],
                ])->contains(fn ($value, $key) => (string) $current->{$key} !== (string) $value);

                $current->user->update([
                    'name' => $data['name'],
                    'email' => $data['email'],
                ]);

                $current->update([
                    'student_number' => $data['student_number'],
                    'course' => $data['course'],
                    'section' => $data['section'],
                    'company_id' => $companyId,
                    'supervisor_id' => $supervisorId,
                    'internship_status' => $data['internship_status'],
                    'internship_schedule' => $data['internship_schedule'] ?? null,
                ]);

                return $hasAssignmentChanges;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another request took the email / student number meanwhile.
            $field = str_contains($e->getMessage(), 'student_number') ? 'student_number' : 'email';

            throw ValidationException::withMessages([
                $field => __('validation.unique', ['attribute' => str_replace('_', ' ', $field)]),
            ]);
        }

        $student->refresh()->load(['user', 'company', 'supervisor']);

        if ($notify) {
            $this->notifications->assignmentUpdated($student);
        }

        return $student;
    }

    /**
     * Sets the student's account status; null toggles it (the website's
     * button). Deactivating revokes their API tokens, as in User Management.
     *
     * @throws ModelNotFoundException the student's account was deleted meanwhile
     */
    public function setStatus(Student $student, ?bool $active = null): Student
    {
        DB::transaction(function () use ($student, $active) {
            $user = User::query()->lockForUpdate()->find($student->user_id)
                ?? throw (new ModelNotFoundException)->setModel(User::class, [$student->user_id]);

            $newStatus = match ($active) {
                null => $user->status === 'active' ? 'inactive' : 'active',
                true => 'active',
                false => 'inactive',
            };

            $user->update(['status' => $newStatus]);

            if ($newStatus === 'inactive') {
                $user->revokeApiTokens();
            }
        });

        return $student->refresh();
    }

    /**
     * The slot, roster and inactive rules, against the locked student and
     * company.
     *
     * @throws AssignmentRuleException
     */
    private function checkRules(Student $current, ?Company $company, ?int $supervisorId): void
    {
        $companyChanged = (int) $company?->id !== (int) $current->company_id;
        $supervisorChanged = $supervisorId !== ($current->supervisor_id === null ? null : (int) $current->supervisor_id);

        if ($company !== null && ($companyChanged || ($supervisorChanged && $supervisorId !== null)) && $company->status === 'inactive') {
            throw AssignmentRuleException::refuse(self::COMPANY_INACTIVE, 'company_id',
                "{$company->company_name} is inactive. Activate it in Company Management before assigning students to it.");
        }

        if ($company !== null && $companyChanged) {
            $assignedCount = Student::query()->where('company_id', $company->id)->lockForUpdate()->count();

            if ($assignedCount >= $company->slots) {
                throw AssignmentRuleException::refuse(self::COMPANY_FULL, 'company_id',
                    "{$company->company_name} has no available slots ({$assignedCount}/{$company->slots} filled).");
            }
        }

        if ($supervisorId === null || (! $companyChanged && ! $supervisorChanged)) {
            return;
        }

        if ($company === null) {
            throw AssignmentRuleException::refuse(self::SUPERVISOR_NEEDS_COMPANY, 'supervisor_id', self::NEEDS_COMPANY_MESSAGE);
        }

        $supervisor = User::query()
            ->whereKey($supervisorId)
            ->where('role_id', User::ROLE_SUPERVISOR)
            ->lockForUpdate()
            ->first();

        if ($supervisor === null) {
            throw ValidationException::withMessages([
                'supervisor_id' => __('validation.exists', ['attribute' => 'supervisor id']),
            ]);
        }

        if (! $this->onRoster($company->id, $supervisor->id, true)) {
            throw AssignmentRuleException::refuse(self::SUPERVISOR_NOT_ON_ROSTER, 'supervisor_id', self::NOT_ON_ROSTER_MESSAGE);
        }

        // A new supervisor, or the current one taken along to a new company,
        // is a new assignment: an inactive supervisor can't take it.
        if ($supervisor->status === 'inactive') {
            throw AssignmentRuleException::refuse(self::SUPERVISOR_INACTIVE, 'supervisor_id', self::SUPERVISOR_INACTIVE_MESSAGE);
        }
    }

    /**
     * Whether the request changes the student's company or supervisor (the
     * roster is only re-checked then).
     */
    private function assignmentChanges(Student $student, mixed $companyId, mixed $supervisorId): bool
    {
        return (string) $supervisorId !== (string) $student->supervisor_id
            || (string) $companyId !== (string) $student->company_id;
    }

    /**
     * The `integer` rule lets `true` through (as 1); an id must be a number
     * or a numeric string.
     */
    private static function idRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (is_bool($value) || is_array($value)) {
                $fail(__('validation.integer', ['attribute' => str_replace('_', ' ', $attribute)]));
            }
        };
    }

    private function onRoster(mixed $companyId, mixed $supervisorId, bool $lock = false): bool
    {
        return DB::table('company_supervisors')
            ->where('company_id', $companyId)
            ->where('user_id', $supervisorId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->exists();
    }
}
