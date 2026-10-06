<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\Student;
use App\Models\User;
use App\Services\CompanyManagementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 14: Company Management and the supervisor roster through
 * /api/v1 (Coordinator, Administrator), sharing CompanyManagementService
 * with the website.
 */
class CompanyManagementApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'company_name' => 'Acme Corp',
            'address' => 'Lacson St, Bacolod',
            'contact_person' => 'Ana Cruz',
            'contact_number' => '0917-000-0000',
            'email' => 'hr@acme.test',
            'industry' => 'IT',
            'slots' => 5,
        ];
    }

    private function assign(Company $company, int $count = 1, ?User $supervisor = null): void
    {
        Student::factory()->count($count)->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor?->id,
        ]);
    }

    // ---- access ---------------------------------------------------------

    public function test_only_coordinators_and_administrators_can_use_company_management(): void
    {
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $calls = [
            ['GET', '/api/v1/companies'],
            ['GET', "/api/v1/companies/{$company->id}"],
            ['POST', '/api/v1/companies', $this->form()],
            ['PATCH', "/api/v1/companies/{$company->id}", $this->form()],
            ['POST', "/api/v1/companies/{$company->id}/activate"],
            ['POST', "/api/v1/companies/{$company->id}/deactivate"],
            ['DELETE', "/api/v1/companies/{$company->id}"],
            ['GET', "/api/v1/companies/{$company->id}/supervisors/available"],
            ['POST', "/api/v1/companies/{$company->id}/supervisors", ['user_id' => $supervisor->id]],
            ['DELETE', "/api/v1/companies/{$company->id}/supervisors/{$supervisor->id}"],
        ];

        foreach ([User::ROLE_STUDENT, User::ROLE_SUPERVISOR] as $roleId) {
            $user = $this->user($roleId);

            foreach ($calls as $call) {
                $this->api($call[0], $call[1], $user, $call[2] ?? [])->assertForbidden();
            }
        }

        foreach ($calls as $call) {
            $this->api($call[0], $call[1], null, $call[2] ?? [])->assertUnauthorized();
        }

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'active']);
        $this->assertSame(1, Company::count());
        $this->assertSame(0, DB::table('company_supervisors')->count());

        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $roleId) {
            $this->api('GET', '/api/v1/companies', $this->user($roleId))->assertOk();
        }
    }

    public function test_unknown_ids_are_404(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();

        foreach ([
            ['GET', '/api/v1/companies/999999'],
            ['PATCH', '/api/v1/companies/999999', $this->form()],
            ['POST', '/api/v1/companies/999999/activate'],
            ['DELETE', '/api/v1/companies/999999'],
            ['GET', '/api/v1/companies/999999/supervisors/available'],
            ['POST', '/api/v1/companies/999999/supervisors', ['user_id' => 1]],
            ['DELETE', "/api/v1/companies/{$company->id}/supervisors/999999"],
            ['GET', '/api/v1/companies/abc'],
            ['GET', '/api/v1/companies/0'],
        ] as $call) {
            $this->api($call[0], $call[1], $admin, $call[2] ?? [])->assertNotFound();
        }
    }

    // ---- list / detail --------------------------------------------------

    public function test_list_shape_counts_and_roster(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create(['company_name' => 'Acme', 'slots' => 3]);
        $alice = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Alice']);
        $bob = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Bob', 'status' => 'inactive']);
        $company->supervisors()->attach([$bob->id, $alice->id]);
        $this->assign($company, 2, $alice);

        $other = Company::factory()->create(['company_name' => 'Zeta']);
        $this->assign($other, 1, $alice);

        $response = $this->api('GET', '/api/v1/companies', $coordinator)
            ->assertOk()
            ->assertJsonPath('can_create', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.company_name', 'Acme')
            ->assertJsonPath('data.1.company_name', 'Zeta');

        $row = $response->json('data.0');
        $this->assertSame([
            'id', 'company_name', 'address', 'contact_person', 'contact_number', 'email', 'industry',
            'slots', 'students_count', 'slots_available', 'status', 'supervisors', 'supervisors_count',
            'created_at', 'updated_at', 'can_edit', 'can_toggle_status', 'can_manage_roster',
            'can_delete', 'delete_blocked_reason',
        ], array_keys($row));
        $this->assertSame(3, $row['slots']);
        $this->assertSame(2, $row['students_count']);
        $this->assertSame(1, $row['slots_available']);
        $this->assertSame(2, $row['supervisors_count']);
        $this->assertSame([
            ['id' => $alice->id, 'name' => 'Alice', 'email' => $alice->email, 'status' => 'active', 'students_count' => 2],
            ['id' => $bob->id, 'name' => 'Bob', 'email' => $bob->email, 'status' => 'inactive', 'students_count' => 0],
        ], $row['supervisors']);
        $this->assertFalse($row['can_delete']);
        $this->assertSame('company_has_students', $row['delete_blocked_reason']);

        $this->api('GET', "/api/v1/companies/{$company->id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('data', $row);
    }

    public function test_slots_available_never_goes_negative(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create(['slots' => 1]);
        $this->assign($company, 3);

        $this->api('GET', "/api/v1/companies/{$company->id}", $admin)
            ->assertJsonPath('data.students_count', 3)
            ->assertJsonPath('data.slots_available', 0);
    }

    public function test_search_and_status_filters(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        Company::factory()->create(['company_name' => 'Acme Labs']);
        Company::factory()->inactive()->create(['company_name' => 'Acme Foods']);
        Company::factory()->create(['company_name' => 'Zeta 100% Co']);

        $names = fn (string $query) => collect($this->api('GET', '/api/v1/companies'.$query, $admin)->assertOk()->json('data'))
            ->pluck('company_name')->all();

        $this->assertSame(['Acme Foods', 'Acme Labs'], $names('?search=acme'));
        $this->assertSame(['Acme Foods'], $names('?search=acme&status=inactive'));
        $this->assertSame(['Acme Labs', 'Zeta 100% Co'], $names('?status=active'));
        $this->assertSame(['Zeta 100% Co'], $names('?search=100%25'));
        $this->assertSame([], $names('?search=_'));
        $this->assertCount(3, $names('?search=&status='));
    }

    public function test_list_is_paginated_and_query_count_does_not_grow_per_row(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        foreach (range(1, 5) as $i) {
            $company = Company::factory()->create(['company_name' => "Company {$i}"]);
            $company->supervisors()->attach($supervisor->id);
            $this->assign($company, 1, $supervisor);
        }

        $this->api('GET', '/api/v1/companies?per_page=2&page=3', $admin)
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 3, 'last_page' => 3, 'per_page' => 2, 'total' => 5, 'has_more' => false])
            ->assertJsonPath('data.0.company_name', 'Company 5');

        $count = function (int $perPage) use ($admin) {
            $token = $admin->createToken('t')->plainTextToken;
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson("/api/v1/companies?per_page={$perPage}")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(1), $count(5));
    }

    public static function invalidListQueries(): array
    {
        return [
            'status' => ['?status=archived', 'status'],
            'status array' => ['?status[]=active', 'status'],
            'search array' => ['?search[]=a', 'search'],
            'search too long' => ['?search='.str_repeat('a', 256), 'search'],
            'page 0' => ['?page=0', 'page'],
            'page text' => ['?page=abc', 'page'],
            'per_page high' => ['?per_page=51', 'per_page'],
        ];
    }

    #[DataProvider('invalidListQueries')]
    public function test_invalid_list_queries_are_422(string $query, string $field): void
    {
        $this->api('GET', '/api/v1/companies'.$query, $this->user(User::ROLE_COORDINATOR))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    // ---- create / update ------------------------------------------------

    public function test_create_is_active_and_ignores_extra_fields(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $response = $this->api('POST', '/api/v1/companies', $coordinator, $this->form([
            'status' => 'inactive',
            'id' => 999,
            'created_at' => '2000-01-01',
        ]))
            ->assertCreated()
            ->assertJsonPath('message', 'Company added successfully.')
            ->assertJsonPath('company.company_name', 'Acme Corp')
            ->assertJsonPath('company.status', 'active')
            ->assertJsonPath('company.students_count', 0)
            ->assertJsonPath('company.can_delete', true)
            ->assertJsonPath('company.supervisors', []);

        $this->assertNotSame(999, $response->json('company.id'));
        $this->assertDatabaseHas('companies', ['company_name' => 'Acme Corp', 'status' => 'active', 'slots' => 5]);
    }

    public function test_optional_fields_may_be_null_or_missing(): void
    {
        $this->api('POST', '/api/v1/companies', $this->user(User::ROLE_ADMIN), [
            'company_name' => 'Bare', 'slots' => 0, 'email' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('company.email', null)
            ->assertJsonPath('company.address', null)
            ->assertJsonPath('company.slots', 0);
    }

    public static function invalidForms(): array
    {
        return [
            'name missing' => [['company_name' => null], 'company_name'],
            'name array' => [['company_name' => ['a']], 'company_name'],
            'name too long' => [['company_name' => str_repeat('a', 256)], 'company_name'],
            'address array' => [['address' => ['x' => 'y']], 'address'],
            'contact number too long' => [['contact_number' => str_repeat('1', 51)], 'contact_number'],
            'email invalid' => [['email' => 'not-an-email'], 'email'],
            'email array' => [['email' => ['a@b.c']], 'email'],
            'industry number' => [['industry' => 12], 'industry'],
            'slots missing' => [['slots' => null], 'slots'],
            'slots negative' => [['slots' => -1], 'slots'],
            'slots decimal' => [['slots' => 1.5], 'slots'],
            'slots text' => [['slots' => 'five'], 'slots'],
            'slots too big' => [['slots' => 4294967296], 'slots'],
            'slots array' => [['slots' => [1]], 'slots'],
            'slots boolean' => [['slots' => true], 'slots'],
        ];
    }

    #[DataProvider('invalidForms')]
    public function test_invalid_creates_and_updates_are_422(array $overrides, string $field): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create(['company_name' => 'Keep']);

        $this->api('POST', '/api/v1/companies', $admin, $this->form($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->api('PATCH', "/api/v1/companies/{$company->id}", $admin, $this->form($overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(1, Company::count());
        $this->assertSame('Keep', $company->fresh()->company_name);
    }

    public function test_the_largest_slot_count_is_accepted(): void
    {
        $this->api('POST', '/api/v1/companies', $this->user(User::ROLE_ADMIN), $this->form(['slots' => 4294967295]))
            ->assertCreated()
            ->assertJsonPath('company.slots', 4294967295);
    }

    public function test_invalid_utf8_and_garbled_json_are_422(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['{"company_name":"Bad '."\xC3\x28".'","slots":1}', '{"company_name": "x", "slots": ', '[1,2'] as $body) {
            $this->app['auth']->forgetGuards();

            $this->call('POST', '/api/v1/companies', [], [], [], $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
            ]), $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors('input');
        }

        $this->assertSame(0, Company::count());
    }

    public function test_update_edits_fields_but_not_status(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->inactive()->create();

        $this->api('PATCH', "/api/v1/companies/{$company->id}", $coordinator, $this->form([
            'company_name' => 'Renamed',
            'industry' => null,
            'status' => 'active',
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Company updated successfully.')
            ->assertJsonPath('company.company_name', 'Renamed')
            ->assertJsonPath('company.industry', null)
            ->assertJsonPath('company.status', 'inactive');
    }

    public function test_slots_cannot_drop_below_assigned_students_on_web_or_api(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create(['slots' => 5]);
        $this->assign($company, 3);

        $this->api('PATCH', "/api/v1/companies/{$company->id}", $admin, $this->form(['slots' => 2]))
            ->assertStatus(422)
            ->assertJsonPath('errors.slots.0', 'Slots cannot be lower than the 3 students already assigned to this company.');

        $this->web('PATCH', "/company-management/{$company->id}", $admin, $this->form(['slots' => 2]))
            ->assertSessionHasErrors(['slots' => 'Slots cannot be lower than the 3 students already assigned to this company.']);

        $this->assertSame(5, (int) $company->fresh()->slots);

        $this->api('PATCH', "/api/v1/companies/{$company->id}", $admin, $this->form(['slots' => 3]))
            ->assertOk()
            ->assertJsonPath('company.slots', 3)
            ->assertJsonPath('company.slots_available', 0);
    }

    // ---- status ---------------------------------------------------------

    public function test_activate_and_deactivate_are_idempotent(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create();

        foreach (['deactivate' => 'inactive', 'activate' => 'active'] as $action => $status) {
            foreach ([1, 2] as $attempt) {
                $this->api('POST', "/api/v1/companies/{$company->id}/{$action}", $coordinator)
                    ->assertOk()
                    ->assertJsonPath('message', 'Company status updated.')
                    ->assertJsonPath('company.status', $status);

                $this->assertSame($status, $company->fresh()->status);
            }
        }
    }

    // ---- delete ---------------------------------------------------------

    public function test_a_company_without_students_or_evaluations_is_deleted_with_its_roster(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company->supervisors()->attach($supervisor->id);

        $this->api('DELETE', "/api/v1/companies/{$company->id}", $admin)
            ->assertOk()
            ->assertExactJson(['message' => 'Company deleted successfully.']);

        $this->assertModelMissing($company);
        $this->assertSame(0, DB::table('company_supervisors')->count());
        $this->assertModelExists($supervisor);

        $this->api('DELETE', "/api/v1/companies/{$company->id}", $admin)->assertNotFound();
    }

    public function test_a_company_with_students_cannot_be_deleted_on_web_or_api(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create();
        $this->assign($company);

        $this->api('DELETE', "/api/v1/companies/{$company->id}", $coordinator)
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'This company cannot be deleted while students are assigned to it.',
                'code' => 'company_has_students',
            ]);

        $this->web('DELETE', "/company-management/{$company->id}", $coordinator)
            ->assertRedirect(route('company-management.index', absolute: false))
            ->assertSessionHas('error', 'This company cannot be deleted while students are assigned to it.');

        $this->assertModelExists($company);
        $this->assertSame(1, Student::where('company_id', $company->id)->count());
    }

    public function test_a_company_recorded_on_evaluations_cannot_be_deleted_on_web_or_api(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();
        $evaluation = Evaluation::factory()->create(['company_id' => $company->id]);

        $this->api('GET', "/api/v1/companies/{$company->id}", $admin)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.delete_blocked_reason', 'company_has_evaluations');

        $this->api('DELETE', "/api/v1/companies/{$company->id}", $admin)
            ->assertStatus(422)
            ->assertJsonPath('code', 'company_has_evaluations');

        $this->web('DELETE', "/company-management/{$company->id}", $admin)
            ->assertSessionHas('error', CompanyManagementService::DELETE_MESSAGES['company_has_evaluations']);

        $this->assertModelExists($company);
        $this->assertSame($company->id, (int) $evaluation->fresh()->company_id);
    }

    // ---- roster ---------------------------------------------------------

    public function test_available_supervisors_are_role_3_accounts_not_on_the_roster(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create();
        $onRoster = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Carl']);
        $inactive = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Bea', 'status' => 'inactive']);
        $free = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Abe']);
        $this->user(User::ROLE_COORDINATOR, ['name' => 'Aaron']);
        $this->user(User::ROLE_STUDENT, ['name' => 'Aaliyah']);
        $company->supervisors()->attach($onRoster->id);
        Company::factory()->create()->supervisors()->attach($free->id);

        $this->api('GET', "/api/v1/companies/{$company->id}/supervisors/available", $coordinator)
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $free->id, 'name' => 'Abe', 'email' => $free->email, 'status' => 'active'],
                ['id' => $inactive->id, 'name' => 'Bea', 'email' => $inactive->email, 'status' => 'inactive'],
            ]]);
    }

    public function test_attach_is_retry_safe(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        foreach ([1, 2] as $attempt) {
            $this->api('POST', "/api/v1/companies/{$company->id}/supervisors", $coordinator, ['user_id' => $supervisor->id])
                ->assertOk()
                ->assertJsonPath('message', 'Supervisor added to company roster.')
                ->assertJsonPath('company.supervisors_count', 1)
                ->assertJsonPath('company.supervisors.0.id', $supervisor->id);
        }

        $this->assertSame(1, DB::table('company_supervisors')->count());
    }

    public function test_attach_accepts_a_numeric_string_id(): void
    {
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $this->api('POST', "/api/v1/companies/{$company->id}/supervisors", $this->user(User::ROLE_ADMIN), ['user_id' => (string) $supervisor->id])
            ->assertOk();
    }

    public function test_attach_refuses_anyone_who_is_not_a_supervisor(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();

        $ids = [
            $this->user(User::ROLE_STUDENT)->id,
            $this->user(User::ROLE_COORDINATOR)->id,
            $admin->id,
            User::factory()->create(['role_id' => null])->id,
            999999,
            null,
            '',
            'abc',
            0,
            -1,
            1.5,
            [$this->user(User::ROLE_SUPERVISOR)->id],
        ];

        foreach ($ids as $id) {
            $this->api('POST', "/api/v1/companies/{$company->id}/supervisors", $admin, ['user_id' => $id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('user_id');
        }

        $this->assertSame(0, DB::table('company_supervisors')->count());
    }

    public function test_attach_rechecks_the_role_under_the_lock(): void
    {
        $company = Company::factory()->create();
        $user = $this->user(User::ROLE_COORDINATOR);

        try {
            app(CompanyManagementService::class)->attachSupervisor($company, $user->id);
            $this->fail('Expected a validation error.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }

        $this->assertSame(0, DB::table('company_supervisors')->count());
    }

    public function test_detach_is_idempotent_and_keeps_supervised_students(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company->supervisors()->attach($supervisor->id);
        $this->assign($company, 2, $supervisor);

        $this->api('GET', "/api/v1/companies/{$company->id}", $admin)
            ->assertJsonPath('data.supervisors.0.students_count', 2);

        foreach ([1, 2] as $attempt) {
            $this->api('DELETE', "/api/v1/companies/{$company->id}/supervisors/{$supervisor->id}", $admin)
                ->assertOk()
                ->assertJsonPath('message', 'Supervisor removed from company roster.')
                ->assertJsonPath('company.supervisors', [])
                ->assertJsonPath('company.students_count', 2);
        }

        $this->assertSame(0, DB::table('company_supervisors')->count());
        $this->assertSame(2, Student::where('company_id', $company->id)->where('supervisor_id', $supervisor->id)->count());
    }

    public function test_detach_only_touches_the_given_company(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $a->supervisors()->attach($supervisor->id);
        $b->supervisors()->attach($supervisor->id);

        $this->api('DELETE', "/api/v1/companies/{$a->id}/supervisors/{$supervisor->id}", $admin)->assertOk();

        $this->assertDatabaseMissing('company_supervisors', ['company_id' => $a->id, 'user_id' => $supervisor->id]);
        $this->assertDatabaseHas('company_supervisors', ['company_id' => $b->id, 'user_id' => $supervisor->id]);
    }

    // ---- web / API parity -----------------------------------------------

    public function test_web_and_api_accept_and_refuse_the_same_forms(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (self::invalidForms() as $case) {
            [$overrides, $field] = $case;

            if (is_array($overrides[$field] ?? null) || is_int($overrides[$field] ?? null) && $field !== 'slots') {
                // Inertia forms only send strings / numbers; checked above via the API.
                continue;
            }

            $this->web('POST', '/company-management', $admin, $this->form($overrides))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Company::count());

        $this->web('POST', '/company-management', $admin, $this->form(['company_name' => 'Web Co']))
            ->assertSessionHasNoErrors();
        $this->api('POST', '/api/v1/companies', $admin, $this->form(['company_name' => 'Api Co']))
            ->assertCreated();

        $web = Company::where('company_name', 'Web Co')->first();
        $api = Company::where('company_name', 'Api Co')->first();
        $this->assertSame(
            collect($web->getAttributes())->except(['id', 'company_name', 'created_at', 'updated_at'])->all(),
            collect($api->getAttributes())->except(['id', 'company_name', 'created_at', 'updated_at'])->all(),
        );
    }

    public function test_web_and_api_lists_hold_the_same_companies(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        Company::factory()->create(['company_name' => 'Acme Labs']);
        Company::factory()->inactive()->create(['company_name' => 'Acme Foods']);
        Company::factory()->create(['company_name' => 'Beta']);

        foreach (['', '?search=acme', '?status=inactive', '?search=acme&status=active'] as $query) {
            $web = $this->web('GET', '/company-management'.$query, $coordinator)->viewData('page')['props']['companies'];
            $api = $this->api('GET', '/api/v1/companies'.$query, $coordinator)->json('data');

            $this->assertSame(collect($web)->pluck('id')->all(), collect($api)->pluck('id')->all(), $query);
        }
    }

    public function test_web_roster_attach_refuses_non_supervisors_like_the_api(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create();

        foreach ([$this->user(User::ROLE_STUDENT)->id, 'abc', 999999] as $id) {
            $this->web('POST', "/company-management/{$company->id}/supervisors", $coordinator, ['user_id' => $id])
                ->assertSessionHasErrors('user_id');
        }

        $this->assertSame(0, DB::table('company_supervisors')->count());
    }

    public function test_web_delete_and_toggle_still_work(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create();

        $this->web('PATCH', "/company-management/{$company->id}/toggle-status", $admin)
            ->assertSessionHas('success', 'Company status updated.');
        $this->assertSame('inactive', $company->fresh()->status);

        $this->web('PATCH', "/company-management/{$company->id}/toggle-status", $admin);
        $this->assertSame('active', $company->fresh()->status);

        $this->web('DELETE', "/company-management/{$company->id}", $admin)
            ->assertSessionHas('success', 'Company deleted successfully.');
        $this->assertModelMissing($company);
    }

    public function test_supervisors_and_students_cannot_use_the_web_pages_either(): void
    {
        $company = Company::factory()->create();

        foreach ([User::ROLE_STUDENT, User::ROLE_SUPERVISOR] as $roleId) {
            $user = $this->user($roleId);
            $this->web('GET', '/company-management', $user)->assertForbidden();
            $this->web('DELETE', "/company-management/{$company->id}", $user)->assertForbidden();
        }

        $this->assertModelExists($company);
    }
}
