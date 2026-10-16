<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\Student;
use App\Models\User;
use App\Rules\NotBoolean;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company Management (Internship Coordinator, Administrator), shared by the
 * website and the mobile API.
 *
 * - A company is created active; status only changes through
 *   activate / deactivate.
 * - Slots can't be set below the number of students already assigned in
 *   the current term (students_count is the current term's too).
 * - A company can't be deleted while students are assigned to it or an
 *   evaluation records it (deleting would silently null those links).
 * - Each company has at most one Company Supervisor (companies.supervisor_id;
 *   a supervisor may cover several companies). Every student placed at the
 *   company is supervised by them: changing or clearing it moves all of
 *   the company's students in the same transaction.
 */
class CompanyManagementService
{
    public const HAS_STUDENTS = 'company_has_students';

    public const HAS_EVALUATIONS = 'company_has_evaluations';

    public const DELETE_MESSAGES = [
        self::HAS_STUDENTS => 'This company cannot be deleted while students are assigned to it.',
        self::HAS_EVALUATIONS => 'This company cannot be deleted because evaluations are recorded for it.',
    ];

    public const SUPERVISOR_INACTIVE_MESSAGE = 'This supervisor\'s account is inactive. Activate it in User Management before assigning it to a company.';

    /** The companies.slots column is an unsigned INT. */
    public const MAX_SLOTS = 4294967295;

    /** `supervisor_issue` values, in precedence order (see supervisorIssue()). */
    public const ISSUE_NO_SUPERVISOR = 'no_supervisor';

    public const ISSUE_NOT_A_SUPERVISOR = 'not_a_supervisor';

    public const ISSUE_INACTIVE = 'inactive';

    public function __construct(private NotificationService $notifications) {}

    /**
     * Companies with the list filters (search, status) applied, by name,
     * with students_count and the supervisor.
     *
     * @param  array{search?: mixed, status?: mixed}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $status = $filters['status'] ?? null;
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return Company::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where('company_name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn (Builder $query) => $query->where('status', $status))
            ->with('supervisor:id,name,email,status,role_id')
            ->withCount(['students' => fn (Builder $q) => $q->inCurrentTerm()])
            ->orderBy('company_name');
    }

    /**
     * The supervisor picker's options: active Supervisor accounts, by name.
     * A company's current inactive / demoted supervisor isn't offered; the
     * UIs show it from the company itself.
     */
    public function supervisorOptions(): Collection
    {
        return User::query()
            ->where('role_id', User::ROLE_SUPERVISOR)
            ->where('status', 'active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Blank supervisor ("" from the website's select) means none.
     */
    public function prepare(Request $request): void
    {
        if ($request->input('supervisor_id') === '') {
            $request->merge(['supervisor_id' => null]);
        }
    }

    /**
     * Create / edit form rules. On edit, slots can't drop below the
     * students already assigned, and a new supervisor must be an active
     * Supervisor (both re-checked under locks in update()). The company's
     * unchanged current supervisor may be re-saved even if their account
     * was since deactivated or lost the Supervisor role. An omitted
     * supervisor_id keeps the current one.
     */
    public function rules(?Company $company = null): array
    {
        $supervisor = Rule::exists('users', 'id');
        $keeping = fn (mixed $value) => $company !== null
            && $company->supervisor_id !== null
            && is_scalar($value)
            && (string) $value === (string) $company->supervisor_id;

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'slots' => ['required', 'numeric', 'integer', 'min:0', 'max:'.self::MAX_SLOTS],
            'supervisor_id' => [
                'bail',
                'sometimes',
                'nullable',
                new NotBoolean,
                'integer',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($keeping) {
                    if ($keeping($value)) {
                        return;
                    }

                    $user = User::query()->find($value);

                    if ($user === null || (int) $user->role_id !== User::ROLE_SUPERVISOR) {
                        $fail(__('validation.exists', ['attribute' => 'supervisor id']));
                    } elseif ($user->status !== 'active') {
                        $fail(self::SUPERVISOR_INACTIVE_MESSAGE);
                    }
                },
            ],
        ];
    }

    public function create(array $data): Company
    {
        $fields = self::fields($data);
        $supervisorId = self::supervisorId($data);

        return DB::transaction(function () use ($fields, $supervisorId) {
            if ($supervisorId !== null) {
                $this->lockNewSupervisor($supervisorId);
            }

            return Company::create($fields + ['supervisor_id' => $supervisorId, 'status' => 'active']);
        });
    }

    /**
     * Saves the edit. When the supervisor changes, every student placed at
     * the company gets the new one (or none) in the same transaction, and
     * after commit is told (assignment_updated, also to the new
     * supervisor), and the new supervisor catches up on their pending
     * attendance and reports.
     *
     * @return int how many students' supervisor changed
     *
     * @throws ValidationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException the company was deleted meanwhile
     */
    public function update(Company $company, array $data): int
    {
        $fields = self::fields($data);
        $supervisorProvided = array_key_exists('supervisor_id', $data);
        $supervisorId = self::supervisorId($data);

        $moved = DB::transaction(function () use ($company, $fields, $supervisorProvided, $supervisorId) {
            $locked = $this->lock($company);

            $assigned = $this->assignedStudents($locked);

            if ((int) $fields['slots'] < $assigned) {
                throw ValidationException::withMessages([
                    'slots' => self::slotsMessage($assigned),
                ]);
            }

            $changed = $supervisorProvided && $supervisorId !== ($locked->supervisor_id === null ? null : (int) $locked->supervisor_id);

            if ($changed && $supervisorId !== null) {
                $this->lockNewSupervisor($supervisorId);
            }

            $locked->update($changed ? $fields + ['supervisor_id' => $supervisorId] : $fields);

            return $this->syncStudents($locked);
        });

        $company->refresh();

        if ($moved->isNotEmpty()) {
            $this->notifyMoved($moved);
        }

        return $moved->count();
    }

    public static function slotsMessage(int $assigned): string
    {
        return "Slots cannot be lower than the {$assigned} ".($assigned === 1 ? 'student' : 'students').' already assigned to this company.';
    }

    /**
     * The flash / API message for a save that moved students.
     */
    public static function movedMessage(int $moved, Company $company): string
    {
        if ($moved === 0) {
            return '';
        }

        return $company->supervisor_id === null
            ? " {$moved} student(s) no longer have a supervisor."
            : " {$moved} student(s) moved to {$company->supervisor?->name}.";
    }

    public function setStatus(Company $company, bool $active): Company
    {
        Company::query()->whereKey($company->id)->update([
            'status' => $active ? 'active' : 'inactive',
            'updated_at' => now(),
        ]);

        return $company->refresh();
    }

    /**
     * Why the company can't be deleted right now, or null.
     */
    public function deleteBlocker(Company $company, bool $lock = false): ?string
    {
        $students = Student::query()->where('company_id', $company->id);
        $evaluations = Evaluation::query()->where('company_id', $company->id);

        if ($lock) {
            $students->lockForUpdate();
            $evaluations->lockForUpdate();
        }

        return match (true) {
            $students->exists() => self::HAS_STUDENTS,
            $evaluations->exists() => self::HAS_EVALUATIONS,
            default => null,
        };
    }

    /**
     * Deletes the company, or returns the blocker code without deleting.
     * Locks the company first, so an assignment racing the delete either
     * lands before (and blocks it) or fails.
     */
    public function delete(Company $company): ?string
    {
        return DB::transaction(function () use ($company) {
            $locked = Company::query()->lockForUpdate()->find($company->id);

            if ($locked === null) {
                return null;
            }

            $blocker = $this->deleteBlocker($locked, true);

            if ($blocker === null) {
                $locked->delete();
            }

            return $blocker;
        });
    }

    /**
     * Why the company's supervisor isn't a working one, first match wins:
     * no_supervisor, not_a_supervisor (the account lost the Supervisor
     * role), inactive; null when they are an active Supervisor. Expects
     * `supervisor` loaded with role_id and status.
     */
    public static function supervisorIssue(?User $supervisor, mixed $supervisorId): ?string
    {
        return match (true) {
            $supervisorId === null => self::ISSUE_NO_SUPERVISOR,
            $supervisor === null || (int) $supervisor->role_id !== User::ROLE_SUPERVISOR => self::ISSUE_NOT_A_SUPERVISOR,
            $supervisor->status !== 'active' => self::ISSUE_INACTIVE,
            default => null,
        };
    }

    /**
     * Loads what the Company object shows on each company, in a fixed
     * number of queries: students_count, the supervisor, supervisor_issue
     * and the delete blocker.
     *
     * @param  iterable<int, Company>  $companies
     */
    public function loadDetails(iterable $companies): void
    {
        $companies = Collection::make($companies);

        if ($companies->isEmpty()) {
            return;
        }

        // students_count: the current term's (slots are per term); the
        // delete blocker counts students from every term.
        $companies->loadCount(['students' => fn (Builder $q) => $q->inCurrentTerm()]);
        $companies->loadExists(['students as any_students_exists', 'evaluations']);
        $companies->load('supervisor:id,name,email,status,role_id');

        foreach ($companies as $company) {
            $company->setAttribute('supervisor_issue', self::supervisorIssue($company->supervisor, $company->supervisor_id));
            $company->setAttribute('delete_blocker', match (true) {
                (bool) $company->any_students_exists => self::HAS_STUDENTS,
                (bool) $company->evaluations_exists => self::HAS_EVALUATIONS,
                default => null,
            });
        }
    }

    public function loadDetail(Company $company): Company
    {
        $this->loadDetails([$company]);

        return $company;
    }

    /**
     * Gives every student placed at the (locked) company its supervisor.
     *
     * @return \Illuminate\Support\Collection<int, int> ids of the students whose supervisor changed
     */
    private function syncStudents(Company $company): \Illuminate\Support\Collection
    {
        $supervisorId = $company->supervisor_id === null ? null : (int) $company->supervisor_id;

        // Current-term students only: past terms keep the supervisor they
        // had (they are read-only).
        $moved = Student::query()
            ->where('company_id', $company->id)
            ->inCurrentTerm()
            ->where(fn (Builder $q) => $supervisorId === null
                ? $q->whereNotNull('supervisor_id')
                : $q->whereNull('supervisor_id')->orWhere('supervisor_id', '!=', $supervisorId))
            ->lockForUpdate()
            ->pluck('id');

        if ($moved->isNotEmpty()) {
            Student::query()->whereIn('id', $moved)->update(['supervisor_id' => $supervisorId]);
        }

        return $moved;
    }

    /**
     * After commit: the moved students and their new supervisor are told,
     * and the new supervisor catches up on what still awaits review.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $studentIds
     */
    private function notifyMoved(\Illuminate\Support\Collection $studentIds): void
    {
        Student::query()
            ->whereIn('id', $studentIds)
            ->with(['user', 'company', 'supervisor'])
            ->orderBy('id')
            ->get()
            ->each(function (Student $student) {
                $this->notifications->assignmentUpdated($student);

                if ($student->supervisor_id !== null) {
                    $this->notifications->supervisorCatchUp($student);
                }
            });
    }

    /**
     * A newly picked supervisor, re-checked under a lock: an active
     * Supervisor account.
     *
     * @throws ValidationException
     */
    private function lockNewSupervisor(int $supervisorId): User
    {
        $supervisor = User::query()->whereKey($supervisorId)->lockForUpdate()->first();

        if ($supervisor === null || (int) $supervisor->role_id !== User::ROLE_SUPERVISOR) {
            throw ValidationException::withMessages([
                'supervisor_id' => __('validation.exists', ['attribute' => 'supervisor id']),
            ]);
        }

        if ($supervisor->status !== 'active') {
            throw ValidationException::withMessages(['supervisor_id' => self::SUPERVISOR_INACTIVE_MESSAGE]);
        }

        return $supervisor;
    }

    /**
     * Students placed at the company in the current term (slots are per
     * term).
     */
    private function assignedStudents(Company $company): int
    {
        return Student::query()->where('company_id', $company->id)->inCurrentTerm()->lockForUpdate()->count();
    }

    private function lock(Company $company): Company
    {
        return Company::query()->lockForUpdate()->findOrFail($company->id);
    }

    private static function supervisorId(array $data): ?int
    {
        $value = $data['supervisor_id'] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function fields(array $data): array
    {
        return collect($data)->only([
            'company_name', 'address', 'contact_number', 'email', 'industry', 'slots',
        ])->all();
    }
}
