<?php

namespace App\Services;

use App\Exceptions\AssignmentRuleException;
use App\Models\Company;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\RequiredHours;
use App\Support\TermContext;
use App\Support\TermGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Internship Assignment (Internship Coordinator, Administrator), shared by
 * the website and the mobile API: one save covering a student's profile
 * fields and their assignment (company, internship status, schedule,
 * required hours), plus the account status toggle.
 *
 * - The student's supervisor is always their company's Company Supervisor
 *   (companies.supervisor_id; none without a company). It isn't picked
 *   here: a `supervisor_id` sent by older clients is ignored.
 * - Ongoing needs required hours (set in this same save or already
 *   stored; an already-ongoing student without any, from older rows, can
 *   still be re-saved), and an ongoing student's hours can't be cleared.
 * - A company other than the student's current one needs a free slot and
 *   must be active (students already there keep it).
 * - All of the above are checked under a lock on the target company (the
 *   same row CompanyManagementService locks for edits and deletes).
 * - Changing company, supervisor or internship status notifies the student
 *   and their supervisor; a newly assigned supervisor is also told about the
 *   student's attendance and reports still awaiting review.
 * - Deactivating the account revokes the student's API tokens.
 */
class InternshipAssignmentService
{
    public const COMPANY_FULL = 'company_full';

    public const COMPANY_INACTIVE = 'company_inactive';

    public const REQUIRED_HOURS_MISSING = 'required_hours_missing';

    public const REQUIRED_HOURS_MISSING_MESSAGE = 'Set the required hours before marking the internship Ongoing.';

    public const REQUIRED_HOURS_LOCKED = 'required_hours_locked';

    /** Ongoing needs a working supervisor (the company's). */
    public const SUPERVISOR_MISSING = 'supervisor_missing';

    public const TERM_MOVE_MESSAGE = 'Only students who haven\'t started can move to another term.';

    public const TERM_PAST_MESSAGE = 'Only an Administrator can move a student into a past term.';

    /** `supervisor_issue` values, in precedence order (see supervisorIssue()). */
    public const ISSUE_NO_SUPERVISOR = CompanyManagementService::ISSUE_NO_SUPERVISOR;

    public const ISSUE_NOT_A_SUPERVISOR = CompanyManagementService::ISSUE_NOT_A_SUPERVISOR;

    public const ISSUE_INACTIVE = CompanyManagementService::ISSUE_INACTIVE;

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
     * needs_supervisor: truthy keeps only Student::needsSupervisor() rows.
     *
     * @param  array{search?: mixed, internship_status?: mixed, status?: mixed, company_id?: mixed, supervisor_id?: mixed, needs_supervisor?: mixed}  $filters
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
                'term',
            ])
            ->inTerm(TermContext::id())
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

        if (filter_var($filters['needs_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->needsSupervisor();
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Every company for the assignment form, by name: slots,
     * students_count (current-term students: slots are per term), status,
     * its Company Supervisor (whom a student placed there gets) and
     * `supervisor_issue` (null | no_supervisor | not_a_supervisor |
     * inactive, see CompanyManagementService::supervisorIssue()).
     */
    public function companyOptions(): Collection
    {
        $companies = Company::query()
            ->select(['id', 'company_name', 'slots', 'status', 'supervisor_id'])
            ->withCount(['students' => fn (Builder $q) => $q->inCurrentTerm()])
            ->with('supervisor:id,name,email,status,role_id')
            ->orderBy('company_name')
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $company->setAttribute('supervisor_issue', CompanyManagementService::supervisorIssue($company->supervisor, $company->supervisor_id));
        }

        return $companies;
    }

    /**
     * Sets `needs_supervisor` on each student: placed at a company with no
     * supervisor, one whose account is no longer a Supervisor, or one whose
     * account is inactive (the same rows as Student::needsSupervisor()),
     * and `supervisor_issue`: which of those it is (null when not flagged).
     *
     * @param  iterable<int, Student>  $students
     */
    public function loadSupervisorFlags(iterable $students): void
    {
        $students = Collection::make($students);

        // Role and status read fresh (callers may have loaded `supervisor`
        // without them).
        $supervisors = User::query()
            ->whereIn('id', $students->pluck('supervisor_id')->filter()->unique()->values())
            ->get(['id', 'role_id', 'status'])
            ->keyBy('id');

        foreach ($students as $student) {
            $issue = $student->company_id === null
                ? null
                : CompanyManagementService::supervisorIssue($supervisors->get($student->supervisor_id), $student->supervisor_id);

            $student->setAttribute('needs_supervisor', $issue !== null);
            $student->setAttribute('supervisor_issue', $issue);
        }
    }

    /**
     * A blank company ("" or null; the website sends "") means "none".
     * Anything else is validated as an id, so `true`, `0` or an array is a
     * 422 rather than being read as an id or as "none".
     */
    public function prepare(Request $request): void
    {
        if ($request->input('company_id') === '') {
            $request->merge(['company_id' => null]);
        }

        // Blank required hours = not set; an omitted key keeps the current
        // value (older app builds don't send it).
        if ($request->has('required_hours')) {
            $request->merge(['required_hours' => RequiredHours::prepare($request->input('required_hours'))]);
        }
    }

    /**
     * The edit form's rules. There is no supervisor field: the student's
     * supervisor follows the company.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(Student $student): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student->user_id)],
            'student_number' => ['bail', 'required', 'string', 'max:50', Rule::unique('students', 'student_number')->ignore($student->id)],
            'course' => ['required', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'company_id' => ['bail', 'nullable', self::idRule(), 'integer', 'min:1', 'exists:companies,id'],
            'internship_status' => ['required', 'in:'.implode(',', array_keys(self::STATUSES))],
            'internship_schedule' => ['nullable', 'string', 'max:255'],
            // Moving to another term: only a Not Started student (checked
            // in update()). Omitted keeps the current term.
            'term_id' => ['bail', 'sometimes', 'required', self::idRule(), 'integer', 'min:1', 'exists:terms,id'],
            // The same rules as Attendance Monitoring's required hours.
            'required_hours' => ['sometimes', ...RequiredHours::rules()],
        ];
    }

    /**
     * Applies a validated edit under locks (target company, then the
     * student) and notifies on assignment changes. The student gets the
     * company's supervisor (none without a company).
     *
     * @param  array  $data  validated rules() data
     *
     * @throws AssignmentRuleException a slot / inactive / required hours rule refused it
     * @throws ValidationException the company vanished meanwhile
     * @throws ModelNotFoundException the student was deleted meanwhile
     */
    public function update(Student $student, array $data): Student
    {
        $companyId = $data['company_id'] ? (int) $data['company_id'] : null;

        try {
            [$notify, $newSupervisor] = DB::transaction(function () use ($student, $data, $companyId) {
                // Lock the target company first, so this save is serialised
                // with other assignments to it (slot count), with its status
                // and supervisor changes and with a company delete
                // (CompanyManagementService locks the same row first).
                $company = $companyId !== null ? Company::query()->lockForUpdate()->find($companyId) : null;

                if ($companyId !== null && $company === null) {
                    throw ValidationException::withMessages([
                        'company_id' => __('validation.exists', ['attribute' => 'company id']),
                    ]);
                }

                $current = Student::query()->lockForUpdate()->find($student->id)
                    ?? throw (new ModelNotFoundException)->setModel(Student::class, [$student->id]);

                // Past terms are read-only but for the Administrator.
                TermGuard::ensureWritable($current);

                $termId = array_key_exists('term_id', $data) ? (int) $data['term_id'] : (int) $current->term_id;

                if ($termId !== (int) $current->term_id) {
                    if ($current->internship_status !== 'not_started' || $data['internship_status'] !== 'not_started') {
                        throw ValidationException::withMessages(['term_id' => self::TERM_MOVE_MESSAGE]);
                    }

                    // Only the Administrator writes into a past term.
                    $target = Term::query()->find($termId);

                    if ($target?->isPast() && ! Auth::user()?->hasRole(User::ROLE_ADMIN)) {
                        throw ValidationException::withMessages(['term_id' => self::TERM_PAST_MESSAGE]);
                    }
                }

                $supervisorId = $company?->supervisor_id === null ? null : (int) $company->supervisor_id;

                // Omitted required hours keep the stored value.
                $requiredHours = array_key_exists('required_hours', $data)
                    ? RequiredHours::normalize($data['required_hours'])
                    : RequiredHours::normalize($current->required_hours);

                $this->checkRules($current, $company, $data['internship_status'], $requiredHours, $termId);

                // The "assignment" itself, as opposed to the student's own
                // profile fields: only changes to these are worth notifying
                // the student / supervisor about.
                $hasAssignmentChanges = collect([
                    'company_id' => $companyId,
                    'supervisor_id' => $supervisorId,
                    'internship_status' => $data['internship_status'],
                ])->contains(fn ($value, $key) => (string) $current->{$key} !== (string) $value);

                // A different, non-null supervisor takes over this student.
                $newSupervisor = $supervisorId !== null && (string) $current->supervisor_id !== (string) $supervisorId;

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
                    'required_hours' => $requiredHours,
                    'term_id' => $termId,
                ]);

                return [$hasAssignmentChanges, $newSupervisor];
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

        // Items submitted while the student had no (or another) supervisor
        // only notified whoever was responsible then: bring the new
        // supervisor up to date on what still awaits review.
        if ($newSupervisor) {
            $this->notifications->supervisorCatchUp($student);
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
        TermGuard::ensureWritable($student);

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
     * The required-hours-before-ongoing, inactive company and slot rules,
     * against the locked student and company.
     *
     * @throws AssignmentRuleException
     */
    private function checkRules(Student $current, ?Company $company, string $internshipStatus, ?int $requiredHours, int $termId): void
    {
        // Ongoing opens attendance and report submissions, which need an
        // hours target: checked against the hours this same save leaves
        // (so hours + Ongoing can be set at once). An already-ongoing
        // student can't lose their hours; one that never had any (older
        // rows) can still be re-saved unchanged.
        if ($internshipStatus === Student::SUBMITTING_STATUS && $requiredHours === null) {
            if ($current->internship_status !== Student::SUBMITTING_STATUS) {
                throw AssignmentRuleException::refuse(self::REQUIRED_HOURS_MISSING, 'internship_status', self::REQUIRED_HOURS_MISSING_MESSAGE);
            }

            if (RequiredHours::normalize($current->required_hours) !== null) {
                throw AssignmentRuleException::refuse(self::REQUIRED_HOURS_LOCKED, 'required_hours', RequiredHours::LOCKED_MESSAGE);
            }
        }

        // Company problems (inactive, full) come first: they are on the
        // company the coordinator just picked.
        $this->checkCompany($current, $company, $termId);

        // An ongoing student needs a working supervisor (their company's):
        // checked when the student becomes Ongoing or an ongoing student
        // moves company. An unchanged re-save of an ongoing student whose
        // supervisor went missing later stays allowed (flagged instead).
        $becomesOngoing = $current->internship_status !== Student::SUBMITTING_STATUS;
        $movesCompany = (string) $current->company_id !== (string) $company?->id;

        if ($internshipStatus === Student::SUBMITTING_STATUS && ($becomesOngoing || $movesCompany)) {
            $this->ensureWorkingSupervisor($company);
        }
    }

    /**
     * A new company must be active and have a free slot in the target term.
     *
     * @throws AssignmentRuleException
     */
    private function checkCompany(Student $current, ?Company $company, int $termId): void
    {
        if ($company === null) {
            return;
        }

        $sameCompany = (int) $company->id === (int) $current->company_id;

        // Staying at the same company in the same term takes no new slot.
        if ($sameCompany && $termId === (int) $current->term_id) {
            return;
        }

        if (! $sameCompany && $company->status === 'inactive') {
            throw AssignmentRuleException::refuse(self::COMPANY_INACTIVE, 'company_id',
                "{$company->company_name} is inactive. Activate it in Company Management before assigning students to it.");
        }

        // Slots are per term: only the target term's students count.
        $assignedCount = Student::query()
            ->where('company_id', $company->id)
            ->where('term_id', $termId)
            ->whereKeyNot($current->id)
            ->lockForUpdate()
            ->count();

        if ($assignedCount >= $company->slots) {
            throw AssignmentRuleException::refuse(self::COMPANY_FULL, 'company_id',
                "{$company->company_name} has no available slots ({$assignedCount}/{$company->slots} filled).");
        }
    }

    /**
     * Refuses Ongoing unless the (locked) target company has an active
     * Supervisor account as its Company Supervisor.
     *
     * @throws AssignmentRuleException
     */
    private function ensureWorkingSupervisor(?Company $company): void
    {
        if ($company === null) {
            throw AssignmentRuleException::refuse(self::SUPERVISOR_MISSING, 'internship_status',
                'Assign a company with a supervisor before marking the internship Ongoing.');
        }

        $supervisor = $company->supervisor_id === null ? null : User::query()->find($company->supervisor_id);

        $problem = match (CompanyManagementService::supervisorIssue($supervisor, $company->supervisor_id)) {
            self::ISSUE_NO_SUPERVISOR => 'has no supervisor yet',
            self::ISSUE_NOT_A_SUPERVISOR => '\'s supervisor account is no longer a Supervisor',
            self::ISSUE_INACTIVE => '\'s supervisor account is inactive',
            default => null,
        };

        if ($problem !== null) {
            $separator = str_starts_with($problem, '\'') ? '' : ' ';

            throw AssignmentRuleException::refuse(self::SUPERVISOR_MISSING, 'internship_status',
                "{$company->company_name}{$separator}{$problem}. Set an active supervisor in Company Management before marking the internship Ongoing.");
        }
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
}
