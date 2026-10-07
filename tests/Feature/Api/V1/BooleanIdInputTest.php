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

    public function test_roster_attach_refuses_booleans_and_arrays_but_takes_whole_floats(): void
    {
        $company = Company::factory()->create();
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([true, false, [$supervisor->id], []] as $bad) {
            $response = $this->api('POST', "/api/v1/companies/{$company->id}/supervisors", $coordinator, ['user_id' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('user_id');

            if (is_bool($bad)) {
                $response->assertJsonPath('errors.user_id.0', 'The user id field must be an integer.');
            }
        }

        $this->assertSame(0, $company->supervisors()->count());

        $this->api('POST', "/api/v1/companies/{$company->id}/supervisors", $coordinator, ['user_id' => (float) $supervisor->id])
            ->assertOk();

        $this->assertTrue($company->supervisors()->whereKey($supervisor->id)->exists());
    }

    public function test_web_roster_attach_refuses_booleans(): void
    {
        $company = Company::factory()->create();
        $this->user(User::ROLE_SUPERVISOR);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->postJson("/company-management/{$company->id}/supervisors", ['user_id' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id' => 'The user id field must be an integer.']);

        $this->assertSame(0, $company->supervisors()->count());
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
