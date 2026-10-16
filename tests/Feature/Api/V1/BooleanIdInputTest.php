<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Laravel's `integer` rule reads a JSON `true` as 1. Every id input must
 * refuse it (422 with the integer message) instead of selecting record 1.
 */
class BooleanIdInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function api(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ])->json($method, $uri, $data);
    }

    public function test_company_supervisor_refuses_booleans_and_arrays_but_takes_whole_floats(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([true, false, [$supervisor->id], []] as $bad) {
            $response = $this->api('POST', '/api/v1/companies', $coordinator, ['company_name' => 'Acme', 'slots' => 1, 'supervisor_id' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('supervisor_id');

            if (is_bool($bad)) {
                $response->assertJsonPath('errors.supervisor_id.0', 'The supervisor id field must be an integer.');
            }
        }

        $this->assertSame(0, Company::count());

        $this->api('POST', '/api/v1/companies', $coordinator, ['company_name' => 'Acme', 'slots' => 1, 'supervisor_id' => (float) $supervisor->id])
            ->assertCreated();

        $this->assertDatabaseHas('companies', ['company_name' => 'Acme', 'supervisor_id' => $supervisor->id]);
    }

    public function test_web_company_supervisor_refuses_booleans(): void
    {
        $this->user(User::ROLE_SUPERVISOR);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson('/company-management', ['company_name' => 'Acme', 'slots' => 1, 'supervisor_id' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supervisor_id' => 'The supervisor id field must be an integer.']);

        $this->assertSame(0, Company::count());
    }

    public function test_user_role_id_refuses_booleans(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $target = $this->user(User::ROLE_SUPERVISOR);

        $this->api('POST', '/api/v1/users', $admin, [
            'name' => 'New Person',
            'email' => 'new.person@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role_id' => 'The role id field must be an integer.']);

        $this->api('PATCH', "/api/v1/users/{$target->id}", $admin, [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');

        $this->assertSame(User::ROLE_SUPERVISOR, (int) $target->fresh()->role_id);
        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.com']);
    }

    public static function listFilters(): array
    {
        return [
            'analytics company' => ['/api/v1/analytics', 'company_id'],
            'analytics supervisor' => ['/api/v1/analytics', 'supervisor_id'],
            'analytics student' => ['/api/v1/analytics', 'student_id'],
            'evaluations student' => ['/api/v1/evaluations', 'student_id'],
            'report reviews student' => ['/api/v1/report-reviews', 'student_id'],
            'users role' => ['/api/v1/users', 'role_id'],
        ];
    }

    /**
     * A query string can't carry a boolean, but a JSON body on a GET is
     * read as input too.
     */
    #[DataProvider('listFilters')]
    public function test_list_filters_refuse_booleans(string $uri, string $field): void
    {
        $this->api('GET', $uri, $this->user(User::ROLE_ADMIN), [$field => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }
}
