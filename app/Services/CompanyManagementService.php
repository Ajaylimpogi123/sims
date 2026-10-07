<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\Student;
use App\Models\User;
use App\Rules\NotBoolean;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company Management and the supervisor roster (Internship Coordinator,
 * Administrator), shared by the website and the mobile API.
 *
 * - A company is created active; status only changes through
 *   activate / deactivate.
 * - Slots can't be set below the number of students already assigned.
 * - A company can't be deleted while students are assigned to it or an
 *   evaluation records it (deleting would silently null those links).
 * - The roster (company_supervisors) is eligibility only: removing a
 *   supervisor from it leaves students.supervisor_id untouched.
 */
class CompanyManagementService
{
    public const HAS_STUDENTS = 'company_has_students';

    public const HAS_EVALUATIONS = 'company_has_evaluations';

    public const DELETE_MESSAGES = [
        self::HAS_STUDENTS => 'This company cannot be deleted while students are assigned to it.',
        self::HAS_EVALUATIONS => 'This company cannot be deleted because evaluations are recorded for it.',
    ];

    /** The companies.slots column is an unsigned INT. */
    public const MAX_SLOTS = 4294967295;

    /**
     * Companies with the list filters (search, status) applied, by name.
     * Includes students_count and the roster.
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
            ->withCount('students')
            ->orderBy('company_name');
    }

    /**
     * Every Supervisor account, by name (the roster picker's options).
     */
    public function supervisors(): Builder
    {
        return User::query()->where('role_id', User::ROLE_SUPERVISOR)->orderBy('name')->orderBy('id');
    }

    /**
     * Supervisors not on the company's roster yet.
     */
    public function availableSupervisors(Company $company): Collection
    {
        return $this->supervisors()
            ->whereDoesntHave('supervisedCompanies', fn (Builder $q) => $q->whereKey($company->id))
            ->get(['id', 'name', 'email', 'status']);
    }

    /**
     * Create / edit form rules. On edit, slots can't drop below the
     * students already assigned (re-checked under a lock in update()).
     */
    public function rules(?Company $company = null): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'slots' => ['required', 'numeric', 'integer', 'min:0', 'max:'.self::MAX_SLOTS],
        ];
    }

    public function create(array $data): Company
    {
        return Company::create(self::fields($data) + ['status' => 'active']);
    }

    public function update(Company $company, array $data): Company
    {
        $fields = self::fields($data);

        DB::transaction(function () use ($company, $fields) {
            $locked = $this->lock($company);

            $assigned = $this->assignedStudents($locked);

            if ((int) $fields['slots'] < $assigned) {
                throw ValidationException::withMessages([
                    'slots' => self::slotsMessage($assigned),
                ]);
            }

            $locked->update($fields);
        });

        return $company->refresh();
    }

    public static function slotsMessage(int $assigned): string
    {
        return "Slots cannot be lower than the {$assigned} ".($assigned === 1 ? 'student' : 'students').' already assigned to this company.';
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
     * Deletes the company (and its roster rows), or returns the blocker
     * code without deleting. Locks the company first, so an assignment
     * racing the delete either lands before (and blocks it) or fails.
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
     * user_id must be a Supervisor account.
     */
    public function attachRules(): array
    {
        return [
            'user_id' => [
                'bail',
                'required',
                new NotBoolean,
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where('role_id', User::ROLE_SUPERVISOR),
            ],
        ];
    }

    /**
     * Adds a Supervisor to the roster. Already on it is a no-op, so
     * retries and double taps are safe.
     */
    public function attachSupervisor(Company $company, int $userId): void
    {
        DB::transaction(function () use ($company, $userId) {
            $locked = $this->lock($company);

            $supervisor = User::query()
                ->whereKey($userId)
                ->where('role_id', User::ROLE_SUPERVISOR)
                ->lockForUpdate()
                ->first();

            if ($supervisor === null) {
                throw ValidationException::withMessages([
                    'user_id' => __('validation.exists', ['attribute' => 'user id']),
                ]);
            }

            try {
                $locked->supervisors()->syncWithoutDetaching([$supervisor->id]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request added the same supervisor first.
            }
        });
    }

    /**
     * Removes a user from the roster (no-op when not on it). Students they
     * already supervise keep them: students.supervisor_id is the source of
     * truth for supervision, the roster is eligibility only.
     */
    public function detachSupervisor(Company $company, User $user): void
    {
        $company->supervisors()->detach($user->id);
    }

    /**
     * Loads what the Company object shows on each company, in a fixed
     * number of queries: students_count, the roster (with each
     * supervisor's students at that company) and the delete blocker.
     *
     * @param  iterable<int, Company>  $companies
     */
    public function loadDetails(iterable $companies): void
    {
        $companies = Collection::make($companies);

        if ($companies->isEmpty()) {
            return;
        }

        $companies->loadCount(['students', 'supervisors']);
        $companies->loadExists('evaluations');
        $companies->load(['supervisors' => fn ($q) => $q
            ->select('users.id', 'users.name', 'users.email', 'users.status')
            ->orderBy('users.name')
            ->orderBy('users.id'),
        ]);

        $supervised = Student::query()
            ->whereIn('company_id', $companies->modelKeys())
            ->whereNotNull('supervisor_id')
            ->selectRaw('company_id, supervisor_id, count(*) as aggregate')
            ->groupBy('company_id', 'supervisor_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->company_id.':'.$row->supervisor_id => (int) $row->aggregate]);

        foreach ($companies as $company) {
            foreach ($company->supervisors as $supervisor) {
                $supervisor->setAttribute('company_students_count', $supervised[$company->id.':'.$supervisor->id] ?? 0);
            }

            $company->setAttribute('delete_blocker', match (true) {
                (int) $company->students_count > 0 => self::HAS_STUDENTS,
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

    private function assignedStudents(Company $company): int
    {
        return Student::query()->where('company_id', $company->id)->lockForUpdate()->count();
    }

    private function lock(Company $company): Company
    {
        return Company::query()->lockForUpdate()->findOrFail($company->id);
    }

    private static function fields(array $data): array
    {
        return collect($data)->only([
            'company_name', 'address', 'contact_person', 'contact_number', 'email', 'industry', 'slots',
        ])->all();
    }
}
