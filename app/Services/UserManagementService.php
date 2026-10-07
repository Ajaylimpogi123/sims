<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Rules\NotBoolean;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * User Management (Internship Coordinator, Administrator), shared by the
 * website and the mobile API.
 *
 * - Administrator accounts are invisible to, and unmanageable by, anyone
 *   who isn't an Administrator.
 * - Only Administrators can give out the Administrator role.
 * - Student is never assignable here: students self-register (which also
 *   creates their Student profile). A Student account can be edited but
 *   always keeps the Student role.
 * - Nobody can change their own account status.
 * - A role change, a new password or a deactivation revokes the user's
 *   mobile API tokens. A role or password change also ends their website
 *   sessions; a deactivation leaves those to EnsureAccountIsActive, which
 *   logs them out with the "deactivated" message on their next request.
 */
class UserManagementService
{
    public const LAST_ADMIN_MESSAGE = 'At least one active Administrator is required.';

    public const STUDENT_ROLE_MESSAGE = "A student account's role can't be changed.";

    /** Retries for a deadlock between racing Administrator changes. */
    private const ATTEMPTS = 3;

    /**
     * Users the viewer may see, with the list filters (role_id, status,
     * search) applied, newest first.
     *
     * @param  array{role_id?: mixed, status?: mixed, search?: mixed}  $filters
     */
    public function query(User $viewer, array $filters = []): Builder
    {
        $roleId = $filters['role_id'] ?? null;
        $status = $filters['status'] ?? null;
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return User::query()
            ->with('role:id,role_name')
            ->tap(fn (Builder $query) => $this->scopeVisible($query, $viewer))
            ->when(is_scalar($roleId) && (string) $roleId !== '', fn (Builder $query) => $query->where('role_id', $roleId))
            ->when(in_array($status, ['active', 'inactive'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderByDesc('created_at');
    }

    public function scopeVisible(Builder $query, User $viewer): Builder
    {
        return $query->when(
            ! $viewer->hasRole(User::ROLE_ADMIN),
            fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('role_id')->orWhere('role_id', '!=', User::ROLE_ADMIN)),
        );
    }

    public function canView(User $viewer, User $target): bool
    {
        return $viewer->hasRole(User::ROLE_ADMIN) || ! $target->hasRole(User::ROLE_ADMIN);
    }

    /**
     * Edit (name, email, password, role). Same rule as viewing.
     */
    public function canEdit(User $actor, User $target): bool
    {
        return $this->canView($actor, $target);
    }

    public function canToggleStatus(User $actor, User $target): bool
    {
        return (int) $actor->id !== (int) $target->id && $this->canEdit($actor, $target);
    }

    /**
     * The website's role filter options, by name.
     */
    public function filterRoles(User $viewer): Collection
    {
        return Role::query()
            ->orderBy('role_name')
            ->when(! $viewer->hasRole(User::ROLE_ADMIN), fn ($q) => $q->where('id', '!=', User::ROLE_ADMIN))
            ->get(['id', 'role_name']);
    }

    /**
     * Role ids the actor may never give: Student when creating (and when
     * editing someone who isn't a Student already), Administrator unless
     * the actor is one.
     *
     * @return list<int>
     */
    public function forbiddenRoleIds(User $actor, ?User $target = null): array
    {
        // A Student account keeps its role: Student can't be given back, and
        // converting one would orphan its Student profile and internship.
        if ($target !== null && $target->hasRole(User::ROLE_STUDENT)) {
            return Role::query()->whereKeyNot(User::ROLE_STUDENT)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $forbidden = $actor->hasRole(User::ROLE_ADMIN) ? [] : [User::ROLE_ADMIN];

        if ($target === null || ! $target->hasRole(User::ROLE_STUDENT)) {
            $forbidden[] = User::ROLE_STUDENT;
        }

        return $forbidden;
    }

    /**
     * Roles the actor may pick when creating ($target null) or editing.
     */
    public function assignableRoles(User $actor, ?User $target = null): Collection
    {
        if ($target !== null && ! $this->canEdit($actor, $target)) {
            return new Collection;
        }

        return Role::query()
            ->orderBy('role_name')
            ->whereNotIn('id', $this->forbiddenRoleIds($actor, $target))
            ->get(['id', 'role_name']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function createRules(User $actor): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role_id' => $this->roleRule($actor),
        ];
    }

    /**
     * @param  bool  $withPassword  whether a new password was sent (the
     *                              website's `filled('password')`)
     * @return array<string, array<int, mixed>>
     */
    public function updateRules(User $actor, User $target, bool $withPassword): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->id)],
            'role_id' => $this->roleRule($actor, $target),
        ];

        if ($withPassword) {
            $rules['password'] = ['required', 'confirmed', Rules\Password::defaults()];
        }

        return $rules;
    }

    /**
     * @param  array  $data  validated createRules() data
     */
    public function create(array $data): User
    {
        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role_id' => (int) $data['role_id'],
                'status' => 'active',
            ]);
        } catch (UniqueConstraintViolationException) {
            throw self::emailTaken();
        }

        event(new Registered($user));

        return $user;
    }

    /**
     * Apply a validated edit. The row is locked and the actor's rights
     * re-checked against it, so an edit racing a role change can't slip
     * past the rules it was validated against.
     *
     * @param  array  $data  validated updateRules() data
     *
     * @throws AuthorizationException when the user became unmanageable meanwhile
     * @throws ModelNotFoundException when the user was deleted meanwhile
     */
    public function update(User $actor, User $target, array $data, ?string $keepSessionId = null): User
    {
        try {
            DB::transaction(function () use ($actor, $target, $data, $keepSessionId) {
                $current = $this->lock($target);

                if (! $this->canEdit($actor, $current)) {
                    throw new AuthorizationException('You cannot manage an Administrator account.');
                }

                Validator::make(
                    ['role_id' => $data['role_id']],
                    ['role_id' => $this->roleRule($actor, $current)],
                    $this->roleMessages($current),
                )->validate();

                $attributes = [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role_id' => (int) $data['role_id'],
                ];

                if (! empty($data['password'])) {
                    $attributes['password'] = Hash::make($data['password']);
                }

                $roleChanged = (int) $attributes['role_id'] !== (int) $current->role_id;

                if ($roleChanged && $this->isLastActiveAdministrator($current)) {
                    throw ValidationException::withMessages(['role_id' => self::LAST_ADMIN_MESSAGE]);
                }

                $current->update($attributes);

                // A role change re-scopes everything the user can reach, and
                // a new password is how staff lock out a lost phone, so sign
                // the mobile app out now rather than letting an old token
                // keep working.
                if ($roleChanged || isset($attributes['password'])) {
                    $this->signOut($current, $keepSessionId);
                }

                $target->setRawAttributes($current->getAttributes(), true);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw self::emailTaken();
        }

        return $target;
    }

    /**
     * Set the account status (idempotent); deactivating revokes the user's
     * API tokens (their website sessions are ended by EnsureAccountIsActive).
     *
     * @throws AuthorizationException on yourself or an unmanageable user
     * @throws ModelNotFoundException when the user was deleted meanwhile
     */
    public function setStatus(User $actor, User $target, bool $active): User
    {
        DB::transaction(function () use ($actor, $target, $active) {
            $current = $this->lock($target);

            if ((int) $actor->id === (int) $current->id) {
                throw new AuthorizationException('You cannot change your own account status.');
            }

            if (! $this->canEdit($actor, $current)) {
                throw new AuthorizationException('You cannot manage an Administrator account.');
            }

            if (! $active && $this->isLastActiveAdministrator($current)) {
                throw ValidationException::withMessages(['status' => self::LAST_ADMIN_MESSAGE]);
            }

            $current->update(['status' => $active ? 'active' : 'inactive']);

            // Only the mobile app is signed out here. The website sessions
            // (and the remember-me cookie) are left alone on purpose:
            // EnsureAccountIsActive refuses every web request of an inactive
            // account, ending that session with the "deactivated" message
            // instead of a silent bounce to the login page.
            if (! $active) {
                $current->revokeApiTokens();
            }

            $target->setRawAttributes($current->getAttributes(), true);
        }, self::ATTEMPTS);

        return $target;
    }

    /**
     * True when $user is an active Administrator and no other active
     * Administrator exists: demoting or deactivating them would leave
     * nobody able to grant the role again. The other Administrators are
     * locked too, so two admins demoting each other at once can't both
     * succeed (the loser of a deadlock is retried and then refused).
     */
    private function isLastActiveAdministrator(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_ADMIN) || ! $user->isActive()) {
            return false;
        }

        return ! User::query()
            ->where('role_id', User::ROLE_ADMIN)
            ->where('status', 'active')
            ->whereKeyNot($user->id)
            ->lockForUpdate()
            ->exists();
    }

    /**
     * End the user's mobile app (API tokens) and website sessions. With the
     * database session driver their session rows are deleted, so their
     * next web request is logged out; $keepSessionId spares the caller's
     * own session when they edit themselves.
     */
    private function signOut(User $user, ?string $keepSessionId = null): void
    {
        $user->revokeApiTokens();

        // A "remember me" cookie would otherwise log them straight back in.
        User::query()->whereKey($user->id)->update(['remember_token' => Str::random(60)]);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId !== null, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function roleRule(User $actor, ?User $target = null): array
    {
        return [
            'required',
            new NotBoolean,
            'integer',
            'exists:roles,id',
            Rule::notIn($this->forbiddenRoleIds($actor, $target)),
        ];
    }

    /**
     * Messages for the role rule, so a refused Student role change says why.
     *
     * @return array<string, string>
     */
    public function roleMessages(?User $target = null): array
    {
        return $target !== null && $target->hasRole(User::ROLE_STUDENT)
            ? ['role_id.not_in' => self::STUDENT_ROLE_MESSAGE]
            : [];
    }

    private function lock(User $target): User
    {
        return User::query()->lockForUpdate()->find($target->id)
            ?? throw (new ModelNotFoundException)->setModel(User::class, [$target->id]);
    }

    private static function emailTaken(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => __('validation.unique', ['attribute' => 'email']),
        ]);
    }
}
