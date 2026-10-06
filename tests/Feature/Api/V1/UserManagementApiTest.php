<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\UserManagementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 13: User Management through /api/v1 (Coordinator, Administrator),
 * sharing UserManagementService with the website.
 */
class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function api(string $method, string $uri, ?User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    private function web(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->actingAs($user)->call($method, $uri, $data);
    }

    private function tokenCount(User $user): int
    {
        return PersonalAccessToken::where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count();
    }

    private function newUser(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'role_id' => User::ROLE_SUPERVISOR,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    private function edit(User $target, array $overrides = []): array
    {
        return $overrides + [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => (int) $target->role_id,
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function endpoints(int $id): array
    {
        return [
            ['GET', '/api/v1/users'],
            ['GET', '/api/v1/users/roles'],
            ['POST', '/api/v1/users'],
            ['GET', "/api/v1/users/{$id}"],
            ['PATCH', "/api/v1/users/{$id}"],
            ['POST', "/api/v1/users/{$id}/activate"],
            ['POST', "/api/v1/users/{$id}/deactivate"],
        ];
    }

    // ---- role matrix -------------------------------------------------

    public function test_only_coordinators_and_administrators_can_use_user_management(): void
    {
        $target = $this->user(User::ROLE_SUPERVISOR);

        foreach ($this->endpoints($target->id) as [$method, $uri]) {
            $this->api($method, $uri, null)->assertUnauthorized();

            foreach ([User::ROLE_STUDENT, User::ROLE_SUPERVISOR] as $role) {
                $this->api($method, $uri, $this->user($role))
                    ->assertForbidden()
                    ->assertExactJson(['message' => 'Unauthorized access']);
            }
        }

        $this->assertSame('active', $target->fresh()->status);

        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $role) {
            $this->api('GET', '/api/v1/users', $this->user($role))->assertOk();
            $this->api('GET', "/api/v1/users/{$target->id}", $this->user($role))->assertOk();
        }
    }

    public function test_staff_permissions_advertise_user_management_to_both_staff_roles(): void
    {
        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $role) {
            $this->api('GET', '/api/v1/me', $this->user($role))->assertJsonPath('staff.can_manage_users', true);
        }
    }

    // ---- list ----------------------------------------------------------

    public function test_list_shape_and_password_never_returned(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Maria']);

        $response = $this->api('GET', '/api/v1/users', $admin)->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'role_id', 'role', 'status', 'created_at', 'is_self', 'can_edit', 'can_toggle_status', 'assignable_roles']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'has_more'],
            'can_create', 'roles', 'create_roles',
        ]);
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('remember_token', $response->getContent());

        $row = collect($response->json('data'))->firstWhere('id', $supervisor->id);
        $this->assertSame('Supervisor', $row['role']);
        $this->assertSame(3, $row['role_id']);
        $this->assertSame('active', $row['status']);
        $this->assertFalse($row['is_self']);
        $this->assertTrue($row['can_edit']);
        $this->assertTrue($row['can_toggle_status']);
        $this->assertSame(20, $response->json('meta.per_page'));
        $this->assertTrue($response->json('can_create'));
    }

    public function test_coordinators_never_see_administrators_and_admins_do(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $otherAdmin = $this->user(User::ROLE_ADMIN);
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $this->user(User::ROLE_SUPERVISOR);

        $coordinatorIds = collect($this->api('GET', '/api/v1/users?per_page=50', $coordinator)->json('data'))->pluck('id');
        $this->assertNotContains($admin->id, $coordinatorIds);
        $this->assertNotContains($otherAdmin->id, $coordinatorIds);
        $this->assertCount(2, $coordinatorIds);

        // Filtering on the Administrator role doesn't reveal them either.
        $this->api('GET', '/api/v1/users?role_id=4', $coordinator)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $adminIds = collect($this->api('GET', '/api/v1/users?per_page=50', $admin)->json('data'))->pluck('id');
        $this->assertContains($otherAdmin->id, $adminIds);
        $this->assertCount(4, $adminIds);
    }

    public function test_role_status_and_search_filters(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $maria = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Maria Santos', 'email' => 'maria@example.com']);
        $inactive = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Pedro', 'email' => 'pedro@example.com', 'status' => 'inactive']);
        $coordinator = $this->user(User::ROLE_COORDINATOR, ['name' => 'Ana', 'email' => 'ana_x@example.com']);

        $ids = fn (string $query) => collect($this->api('GET', '/api/v1/users?'.$query, $admin)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$maria->id, $inactive->id], $ids('role_id=3'));
        $this->assertSame([$inactive->id], $ids('status=inactive'));
        $this->assertSame([$maria->id], $ids('role_id=3&status=active'));
        $this->assertSame([$maria->id], $ids('search=santos'));
        $this->assertSame([$inactive->id], $ids('search=PEDRO%40'));
        // % and _ are literal.
        $this->assertSame([], $ids('search=%25'));
        $this->assertSame([$coordinator->id], $ids('search=a_x'));
        $this->assertCount(4, $ids('search=&role_id=&status='));
    }

    public function test_list_is_page_paginated_newest_first(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, ['created_at' => now()->subDays(10)]);
        $old = $this->user(User::ROLE_SUPERVISOR, ['created_at' => now()->subDays(5)]);
        $new = $this->user(User::ROLE_SUPERVISOR, ['created_at' => now()->subDay()]);

        $this->api('GET', '/api/v1/users?per_page=2', $admin)
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'has_more' => true]);

        $this->api('GET', '/api/v1/users?per_page=2&page=2', $admin)
            ->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('meta.has_more', false);

        $this->api('GET', '/api/v1/users?page=9', $admin)->assertOk()->assertJsonCount(0, 'data');
    }

    public static function invalidListQueries(): array
    {
        return [
            'status' => ['status=banned', 'status'],
            'role_id text' => ['role_id=abc', 'role_id'],
            'role_id array' => ['role_id[]=2', 'role_id'],
            'search array' => ['search[]=a', 'search'],
            'search too long' => ['search='.str_repeat('a', 256), 'search'],
            'per_page' => ['per_page=51', 'per_page'],
            'page' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidListQueries')]
    public function test_invalid_list_queries_are_422(string $query, string $field): void
    {
        $this->api('GET', '/api/v1/users?'.$query, $this->user(User::ROLE_ADMIN))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    // ---- roles & per-row permissions ----------------------------------

    public function test_role_options_per_caller(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);

        $ids = fn (array $roles) => collect($roles)->pluck('id')->sort()->values()->all();

        $c = $this->api('GET', '/api/v1/users/roles', $coordinator)->assertOk();
        $this->assertSame([1, 2, 3], $ids($c->json('roles')));
        $this->assertSame([2, 3], $ids($c->json('create_roles')));
        $this->assertSame(['id' => 2, 'role_name' => 'Internship Coordinator'], $c->json('create_roles.0'));

        $a = $this->api('GET', '/api/v1/users/roles', $admin)->assertOk();
        $this->assertSame([1, 2, 3, 4], $ids($a->json('roles')));
        $this->assertSame([2, 3, 4], $ids($a->json('create_roles')));

        // Same options in the list response.
        $list = $this->api('GET', '/api/v1/users', $coordinator);
        $this->assertSame($c->json('roles'), $list->json('roles'));
        $this->assertSame($c->json('create_roles'), $list->json('create_roles'));
    }

    public function test_per_row_flags_and_assignable_roles(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $student = $this->user(User::ROLE_STUDENT);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $row = fn (User $as, User $target) => $this->api('GET', "/api/v1/users/{$target->id}", $as)->assertOk()->json('data');
        $roleIds = fn (array $row) => collect($row['assignable_roles'])->pluck('id')->sort()->values()->all();

        $this->assertSame([2, 3], $roleIds($row($coordinator, $supervisor)));
        // A Student account keeps its role.
        $this->assertSame([1], $roleIds($row($coordinator, $student)));
        $this->assertSame([2, 3, 4], $roleIds($row($admin, $supervisor)));
        $this->assertSame([1], $roleIds($row($admin, $student)));

        $self = $row($coordinator, $coordinator);
        $this->assertTrue($self['is_self']);
        $this->assertTrue($self['can_edit']);
        $this->assertFalse($self['can_toggle_status']);
        $this->assertSame([2, 3], $roleIds($self));

        $otherAdmin = $row($admin, $this->user(User::ROLE_ADMIN));
        $this->assertTrue($otherAdmin['can_edit']);
        $this->assertTrue($otherAdmin['can_toggle_status']);
    }

    // ---- hidden administrators (IDOR) ----------------------------------

    public function test_administrator_ids_are_404_for_coordinators_on_every_endpoint(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $before = $admin->fresh()->toArray();

        foreach (array_slice($this->endpoints($admin->id), 3) as [$method, $uri]) {
            $this->api($method, $uri, $coordinator, $this->edit($admin, ['name' => 'Hijacked', 'role_id' => 2]))
                ->assertNotFound()
                ->assertExactJson(['message' => 'Not found.']);
        }

        $this->assertSame($before, $admin->fresh()->toArray());

        // Same answer as an id that doesn't exist.
        $this->api('GET', '/api/v1/users/999999', $coordinator)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->api('PATCH', '/api/v1/users/999999', $this->user(User::ROLE_ADMIN), $this->newUser())->assertNotFound();
        $this->api('GET', '/api/v1/users/abc', $coordinator)->assertNotFound();
    }

    // ---- create ---------------------------------------------------------

    public function test_admin_creates_an_active_user(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $response = $this->api('POST', '/api/v1/users', $admin, $this->newUser(['role_id' => User::ROLE_COORDINATOR]))
            ->assertCreated()
            ->assertJsonPath('message', 'User registered successfully.')
            ->assertJsonPath('user.email', 'new.person@example.com')
            ->assertJsonPath('user.role_id', 2)
            ->assertJsonPath('user.role', 'Internship Coordinator')
            ->assertJsonPath('user.status', 'active');

        $this->assertStringNotContainsString('password', $response->getContent());

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame('active', $user->status);
        $this->assertNull($user->student);
    }

    public function test_coordinator_creates_coordinators_and_supervisors_only(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->api('POST', '/api/v1/users', $coordinator, $this->newUser(['role_id' => 3]))->assertCreated();
        $this->api('POST', '/api/v1/users', $coordinator, $this->newUser(['role_id' => 2, 'email' => 'c2@example.com']))->assertCreated();

        foreach ([User::ROLE_ADMIN, User::ROLE_STUDENT] as $role) {
            $this->api('POST', '/api/v1/users', $coordinator, $this->newUser(['role_id' => $role, 'email' => "r{$role}@example.com"]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('role_id');
        }

        $this->assertSame(0, User::where('role_id', User::ROLE_ADMIN)->count());
    }

    public function test_nobody_creates_a_student(): void
    {
        $this->api('POST', '/api/v1/users', $this->user(User::ROLE_ADMIN), $this->newUser(['role_id' => User::ROLE_STUDENT]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->assertFalse(User::where('email', 'new.person@example.com')->exists());
    }

    public function test_create_ignores_mass_assigned_fields(): void
    {
        $this->api('POST', '/api/v1/users', $this->user(User::ROLE_COORDINATOR), $this->newUser([
            'status' => 'inactive',
            'is_admin' => true,
            'id' => 999999,
            'email_verified_at' => '2020-01-01 00:00:00',
            'remember_token' => 'x',
        ]))->assertCreated();

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertSame(User::ROLE_SUPERVISOR, (int) $user->role_id);
        $this->assertNotSame(999999, $user->id);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->remember_token);
    }

    public static function invalidCreates(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name array' => [['name' => ['a']], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'missing email' => [['email' => ''], 'email'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'uppercase email' => [['email' => 'New.Person@example.com'], 'email'],
            'email array' => [['email' => ['a@example.com']], 'email'],
            'missing password' => [['password' => '', 'password_confirmation' => ''], 'password'],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'password mismatch' => [['password_confirmation' => 'Different-123'], 'password'],
            'password array' => [['password' => ['x'], 'password_confirmation' => ['x']], 'password'],
            'missing role' => [['role_id' => null], 'role_id'],
            'unknown role' => [['role_id' => 9], 'role_id'],
            'role text' => [['role_id' => 'admin'], 'role_id'],
            'role array' => [['role_id' => [2, 3]], 'role_id'],
            'role decimal' => [['role_id' => 2.5], 'role_id'],
        ];
    }

    #[DataProvider('invalidCreates')]
    public function test_invalid_creates_are_422(array $overrides, string $field): void
    {
        $this->api('POST', '/api/v1/users', $this->user(User::ROLE_ADMIN), $this->newUser($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(1, User::count());
    }

    public function test_duplicate_email_is_422(): void
    {
        $this->user(User::ROLE_SUPERVISOR, ['email' => 'new.person@example.com']);

        $this->api('POST', '/api/v1/users', $this->user(User::ROLE_ADMIN), $this->newUser())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_an_email_taken_between_validation_and_insert_is_422_not_500(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        // Another request inserts the same email right after validation passed.
        User::creating(function (User $user) {
            DB::table('users')->insert([
                'name' => 'Racer', 'email' => $user->email, 'password' => 'x', 'role_id' => 3,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->api('POST', '/api/v1/users', $admin, $this->newUser())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);

        $this->assertSame(1, User::where('email', 'new.person@example.com')->count());
    }

    public function test_invalid_utf8_and_garbled_json_are_422(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['{"name":"Bad '."\xC3\x28".'","email":"a@example.com"}', '{"name": "x", "email": ', '[1,2'] as $body) {
            $this->app['auth']->forgetGuards();

            $this->call('POST', '/api/v1/users', [], [], [], $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
            ]), $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors('input');
        }

        $this->assertSame(1, User::count());
    }

    // ---- update ---------------------------------------------------------

    public function test_admin_edits_name_email_role_and_password(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);

        $response = $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, [
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
            'role_id' => User::ROLE_COORDINATOR,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('message', 'User updated successfully.')
            ->assertJsonPath('user.name', 'Renamed')
            ->assertJsonPath('user.email', 'renamed@example.com')
            ->assertJsonPath('user.role_id', 2);

        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertTrue(Hash::check(self::PASSWORD, $target->fresh()->password));
    }

    public function test_a_blank_password_leaves_it_unchanged(): void
    {
        $target = $this->user(User::ROLE_SUPERVISOR);
        $hash = $target->password;

        foreach ([['password' => ''], ['password' => null], []] as $extra) {
            $this->api('PATCH', "/api/v1/users/{$target->id}", $this->user(User::ROLE_ADMIN), $this->edit($target, $extra))->assertOk();
        }

        $this->assertSame($hash, $target->fresh()->password);
    }

    public function test_name_email_and_role_are_always_required_on_patch(): void
    {
        $target = $this->user(User::ROLE_SUPERVISOR);

        $this->api('PATCH', "/api/v1/users/{$target->id}", $this->user(User::ROLE_ADMIN), ['name' => 'Only'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'role_id']);
    }

    public function test_coordinator_cannot_promote_to_administrator_including_themselves(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $target = $this->user(User::ROLE_SUPERVISOR);

        foreach ([$target, $coordinator] as $user) {
            $this->api('PATCH', "/api/v1/users/{$user->id}", $coordinator, $this->edit($user, ['role_id' => User::ROLE_ADMIN]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('role_id');

            $this->assertNotSame(User::ROLE_ADMIN, (int) $user->fresh()->role_id);
        }

        $this->assertSame(0, User::where('role_id', User::ROLE_ADMIN)->count());
    }

    public function test_role_status_and_extra_fields_cannot_be_mass_assigned_on_patch(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $target = $this->user(User::ROLE_SUPERVISOR);

        $this->api('PATCH', "/api/v1/users/{$target->id}", $coordinator, $this->edit($target, [
            'status' => 'inactive',
            'is_admin' => true,
            'id' => 999999,
            'email_verified_at' => '2020-01-01 00:00:00',
        ]))->assertOk();

        $fresh = $target->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame(User::ROLE_SUPERVISOR, (int) $fresh->role_id);
        $this->assertTrue(User::whereKey($target->id)->exists());
        $this->assertEquals($target->email_verified_at, $fresh->email_verified_at);
    }

    public function test_non_students_cannot_become_students_but_students_keep_their_role(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = $this->user(User::ROLE_STUDENT);

        $this->api('PATCH', "/api/v1/users/{$supervisor->id}", $admin, $this->edit($supervisor, ['role_id' => User::ROLE_STUDENT]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');

        $this->api('PATCH', "/api/v1/users/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->edit($student, ['name' => 'Still Student']))
            ->assertOk()
            ->assertJsonPath('user.role_id', User::ROLE_STUDENT);
    }

    public function test_admin_can_edit_and_promote_administrators(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $otherAdmin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $this->api('PATCH', "/api/v1/users/{$otherAdmin->id}", $admin, $this->edit($otherAdmin, ['name' => 'Edited Admin']))->assertOk();
        $this->api('PATCH', "/api/v1/users/{$supervisor->id}", $admin, $this->edit($supervisor, ['role_id' => User::ROLE_ADMIN]))
            ->assertOk()
            ->assertJsonPath('user.role_id', User::ROLE_ADMIN);
    }

    public function test_email_uniqueness_on_patch_ignores_the_user_themselves(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);
        $other = $this->user(User::ROLE_SUPERVISOR);

        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target))->assertOk();
        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['email' => $other->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_an_email_taken_between_validation_and_update_is_422_not_500(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);

        User::updating(function () {
            DB::table('users')->insert([
                'name' => 'Racer', 'email' => 'taken@example.com', 'password' => 'x', 'role_id' => 3,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertNotSame('taken@example.com', $target->fresh()->email);
    }

    public function test_a_user_who_became_an_administrator_meanwhile_cannot_be_edited_by_a_coordinator(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $target = $this->user(User::ROLE_SUPERVISOR);
        $stale = User::find($target->id);

        // Promoted by an Administrator after the coordinator's request loaded it.
        DB::table('users')->where('id', $target->id)->update(['role_id' => User::ROLE_ADMIN]);

        try {
            app(UserManagementService::class)->update($coordinator, $stale, $this->edit($stale, ['role_id' => User::ROLE_SUPERVISOR]));
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
        }

        try {
            app(UserManagementService::class)->setStatus($coordinator, $stale, false);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(User::ROLE_ADMIN, (int) $target->fresh()->role_id);
        $this->assertSame('active', $target->fresh()->status);
    }

    // ---- activate / deactivate -----------------------------------------

    public function test_deactivate_and_activate_are_idempotent(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $target = $this->user(User::ROLE_SUPERVISOR);

        foreach ([1, 2] as $_) {
            $this->api('POST', "/api/v1/users/{$target->id}/deactivate", $coordinator)
                ->assertOk()
                ->assertJsonPath('message', 'User account deactivated successfully.')
                ->assertJsonPath('user.status', 'inactive');
            $this->assertSame('inactive', $target->fresh()->status);
        }

        foreach ([1, 2] as $_) {
            $this->api('POST', "/api/v1/users/{$target->id}/activate", $coordinator)
                ->assertOk()
                ->assertJsonPath('message', 'User account activated successfully.')
                ->assertJsonPath('user.status', 'active');
            $this->assertSame('active', $target->fresh()->status);
        }
    }

    public function test_nobody_changes_their_own_status(): void
    {
        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $role) {
            $self = $this->user($role);

            foreach (['activate', 'deactivate'] as $action) {
                $this->api('POST', "/api/v1/users/{$self->id}/{$action}", $self)
                    ->assertForbidden()
                    ->assertExactJson(['message' => 'You cannot change your own account status.']);
            }

            $this->assertSame('active', $self->fresh()->status);
        }
    }

    public function test_admin_can_deactivate_another_administrator(): void
    {
        $otherAdmin = $this->user(User::ROLE_ADMIN);

        $this->api('POST', "/api/v1/users/{$otherAdmin->id}/deactivate", $this->user(User::ROLE_ADMIN))->assertOk();
        $this->assertSame('inactive', $otherAdmin->fresh()->status);
    }

    // ---- token revocation -----------------------------------------------

    public function test_deactivating_revokes_the_users_tokens_and_only_theirs(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $target = $this->user(User::ROLE_SUPERVISOR);
        $bystander = $this->user(User::ROLE_SUPERVISOR);
        $phone = $target->createToken('phone')->plainTextToken;
        $target->createToken('tablet');
        $bystander->createToken('phone');

        $this->api('POST', "/api/v1/users/{$target->id}/deactivate", $coordinator)->assertOk();

        $this->assertSame(0, $this->tokenCount($target));
        $this->assertSame(1, $this->tokenCount($bystander));

        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_activating_keeps_tokens(): void
    {
        $target = $this->user(User::ROLE_SUPERVISOR);
        $target->createToken('phone');

        $this->api('POST', "/api/v1/users/{$target->id}/activate", $this->user(User::ROLE_ADMIN))->assertOk();
        $this->assertSame(1, $this->tokenCount($target));
    }

    public function test_role_change_and_new_password_revoke_tokens_other_edits_dont(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);

        $target->createToken('phone');
        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['name' => 'Just A Rename']))->assertOk();
        $this->assertSame(1, $this->tokenCount($target));

        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['role_id' => User::ROLE_COORDINATOR]))->assertOk();
        $this->assertSame(0, $this->tokenCount($target));

        $target->refresh()->createToken('phone');
        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]))->assertOk();
        $this->assertSame(0, $this->tokenCount($target));

        // A refused change keeps them.
        $target->refresh()->createToken('phone');
        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['role_id' => User::ROLE_STUDENT]))->assertStatus(422);
        $this->assertSame(1, $this->tokenCount($target));
    }

    public function test_changing_your_own_role_signs_your_app_out(): void
    {
        // The website allows staff to change their own role (never to a
        // role they couldn't give anyone else); the token is revoked.
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $coordinator->createToken('phone')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/v1/users/{$coordinator->id}", $this->edit($coordinator, ['role_id' => User::ROLE_SUPERVISOR]))
            ->assertOk()
            ->assertJsonPath('user.role_id', User::ROLE_SUPERVISOR)
            ->assertJsonPath('user.is_self', true);

        $this->assertSame(0, $this->tokenCount($coordinator));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/users')->assertUnauthorized();
    }

    // ---- web / API parity ----------------------------------------------

    public function test_web_and_api_accept_and_refuse_the_same_creates(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $cases = [
            [$this->newUser(['email' => 'ok1@example.com']), null],
            [$this->newUser(['email' => 'admin1@example.com', 'role_id' => 4]), 'role_id'],
            [$this->newUser(['email' => 'student1@example.com', 'role_id' => 1]), 'role_id'],
            [$this->newUser(['email' => 'array1@example.com', 'role_id' => [2, 3]]), 'role_id'],
            [$this->newUser(['email' => 'Upper1@example.com']), 'email'],
            [$this->newUser(['email' => 'short1@example.com', 'password' => 'a', 'password_confirmation' => 'a']), 'password'],
        ];

        foreach ($cases as [$data, $errorField]) {
            $web = $this->web('POST', '/user-management/create', $coordinator, $data);
            $data['email'] = str_replace('1@', '2@', $data['email']);
            $api = $this->api('POST', '/api/v1/users', $coordinator, $data);

            if ($errorField === null) {
                $web->assertRedirect(route('user-management.index', absolute: false))->assertSessionHasNoErrors();
                $api->assertCreated();
            } else {
                $web->assertSessionHasErrors($errorField);
                $api->assertStatus(422)->assertJsonValidationErrors($errorField);
            }
        }

        $this->assertSame(0, User::where('role_id', User::ROLE_ADMIN)->count());
    }

    public function test_web_and_api_apply_the_same_edit_rules(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $admin = $this->user(User::ROLE_ADMIN);

        foreach ([User::ROLE_ADMIN, User::ROLE_STUDENT] as $role) {
            $this->web('PATCH', "/user-management/{$supervisor->id}", $coordinator, $this->edit($supervisor, ['role_id' => $role]))
                ->assertSessionHasErrors('role_id');
            $this->api('PATCH', "/api/v1/users/{$supervisor->id}", $coordinator, $this->edit($supervisor, ['role_id' => $role]))
                ->assertStatus(422)->assertJsonValidationErrors('role_id');
        }

        // Administrator accounts are hidden (404) on both the website and the API.
        $this->web('PATCH', "/user-management/{$admin->id}", $coordinator, $this->edit($admin))->assertNotFound();
        $this->api('PATCH', "/api/v1/users/{$admin->id}", $coordinator, $this->edit($admin))->assertNotFound();
        $this->web('PATCH', "/user-management/{$admin->id}/toggle-status", $coordinator)->assertNotFound();
        $this->api('POST', "/api/v1/users/{$admin->id}/deactivate", $coordinator)->assertNotFound();
        $this->web('PATCH', "/user-management/{$coordinator->id}/toggle-status", $coordinator)->assertForbidden();
        $this->api('POST', "/api/v1/users/{$coordinator->id}/deactivate", $coordinator)->assertForbidden();

        // A role_id array used to reach the database on the website (500).
        $this->web('PATCH', "/user-management/{$supervisor->id}", $coordinator, $this->edit($supervisor, ['role_id' => [2, 3]]))
            ->assertSessionHasErrors('role_id');

        $this->assertSame(User::ROLE_SUPERVISOR, (int) $supervisor->fresh()->role_id);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_web_and_api_lists_hold_the_same_users(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $this->user(User::ROLE_SUPERVISOR);
        $this->user(User::ROLE_STUDENT);

        foreach (['', 'role_id=3', 'status=inactive', 'role_id=3&status=active', 'role_id=4'] as $query) {
            $web = $this->web('GET', '/user-management?'.$query, $coordinator)->assertOk();
            $webIds = collect($web->viewData('page')['props']['users']['data'])->pluck('id')->sort()->values()->all();
            $apiIds = collect($this->api('GET', '/api/v1/users?'.$query, $coordinator)->json('data'))->pluck('id')->sort()->values()->all();

            $this->assertSame($webIds, $apiIds, "query: {$query}");
        }

        $webRoles = $this->web('GET', '/user-management', $coordinator)->viewData('page')['props']['roles'];
        $this->assertSame(
            collect($webRoles)->map(fn ($r) => ['id' => (int) $r['id'], 'role_name' => $r['role_name']])->all(),
            $this->api('GET', '/api/v1/users/roles', $coordinator)->json('roles'),
        );
    }

    // ---- last Administrator ----------------------------------------------

    public function test_the_last_active_administrator_cannot_be_demoted_on_web_or_api(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_ADMIN, ['status' => 'inactive']);

        foreach ([User::ROLE_COORDINATOR, User::ROLE_SUPERVISOR] as $role) {
            $this->api('PATCH', "/api/v1/users/{$admin->id}", $admin, $this->edit($admin, ['role_id' => $role]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['role_id' => UserManagementService::LAST_ADMIN_MESSAGE]);

            $this->web('PATCH', "/user-management/{$admin->id}", $admin, $this->edit($admin, ['role_id' => $role]))
                ->assertSessionHasErrors(['role_id' => UserManagementService::LAST_ADMIN_MESSAGE]);
        }

        $this->assertSame(User::ROLE_ADMIN, (int) $admin->fresh()->role_id);

        // Other edits of the last admin still work.
        $this->api('PATCH', "/api/v1/users/{$admin->id}", $admin, $this->edit($admin, ['name' => 'Still Admin']))->assertOk();
    }

    public function test_an_administrator_can_be_demoted_while_another_active_one_remains(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $other = $this->user(User::ROLE_ADMIN);

        $this->api('PATCH', "/api/v1/users/{$other->id}", $admin, $this->edit($other, ['role_id' => User::ROLE_COORDINATOR]))->assertOk();

        // Now $admin is the last one, so they can't demote themselves.
        $this->api('PATCH', "/api/v1/users/{$admin->id}", $admin, $this->edit($admin, ['role_id' => User::ROLE_COORDINATOR]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    public function test_the_last_active_administrator_cannot_be_deactivated(): void
    {
        // Only reachable when the acting admin is no longer active themselves
        // (self-deactivation is refused separately).
        $actor = $this->user(User::ROLE_ADMIN, ['status' => 'inactive']);
        $last = $this->user(User::ROLE_ADMIN);

        try {
            app(UserManagementService::class)->setStatus($actor, $last, false);
            $this->fail('Expected a ValidationException.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame([UserManagementService::LAST_ADMIN_MESSAGE], $e->errors()['status']);
        }

        $this->assertSame('active', $last->fresh()->status);

        // With another active admin it's fine.
        $this->api('POST', "/api/v1/users/{$last->id}/deactivate", $this->user(User::ROLE_ADMIN))->assertOk();
    }

    // ---- web sessions --------------------------------------------------

    private function webSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
    }

    public function test_deactivating_ends_the_users_web_sessions_and_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $target = $this->user(User::ROLE_SUPERVISOR, ['remember_token' => 'old-remember-token']);
        $bystander = $this->user(User::ROLE_SUPERVISOR);
        $this->webSession($target, 'target-laptop');
        $this->webSession($target, 'target-phone');
        $this->webSession($bystander, 'bystander');

        $this->api('POST', "/api/v1/users/{$target->id}/deactivate", $this->user(User::ROLE_ADMIN))->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $bystander->id)->count());
        $this->assertNotSame('old-remember-token', $target->fresh()->remember_token);
    }

    public function test_activating_or_renaming_keeps_web_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);
        $this->webSession($target, 'target-laptop');

        $this->api('POST', "/api/v1/users/{$target->id}/activate", $admin)->assertOk();
        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['name' => 'Renamed']))->assertOk();

        $this->assertSame(1, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_role_or_password_change_ends_web_sessions_except_the_editors_own(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);
        $this->webSession($target, 'target-laptop');

        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, $this->edit($target, ['role_id' => User::ROLE_COORDINATOR]))->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());

        // Editing yourself on the website keeps the session you're using.
        $this->webSession($admin, 'admin-current');
        $this->webSession($admin, 'admin-other-device');

        app(UserManagementService::class)->update($admin, $admin, $this->edit($admin, [
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]), 'admin-current');

        $this->assertSame(['admin-current'], DB::table('sessions')->where('user_id', $admin->id)->pluck('id')->all());
    }

    public function test_users_without_a_role_are_listed_for_coordinators_like_they_are_viewable(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $noRole = User::factory()->create(['role_id' => null, 'status' => 'active']);

        $this->api('GET', "/api/v1/users/{$noRole->id}", $coordinator)->assertOk();
        $this->assertContains($noRole->id, collect($this->api('GET', '/api/v1/users', $coordinator)->json('data'))->pluck('id'));
        $this->assertNotContains($noRole->id, collect($this->api('GET', '/api/v1/users?role_id=2', $coordinator)->json('data'))->pluck('id'));
    }
}
