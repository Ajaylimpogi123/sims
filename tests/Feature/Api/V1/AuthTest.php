<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const FAILED_MESSAGE = 'These credentials do not match our records.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $roleId,
            'status' => 'active',
        ], $attributes));
    }

    private function login(string $email, string $password = 'password', string $device = 'Pixel 7'): TestResponse
    {
        return $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => $device,
        ]);
    }

    /**
     * Sends a request carrying only a Bearer token, like the mobile app.
     * Guards are forgotten first so a user resolved by an earlier request in
     * the same test can't leak into this one.
     */
    private function api(string $method, string $uri, ?string $token = null, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers));
    }

    private function assertJsonResponse(TestResponse $response): void
    {
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->isRedirection(), 'API must never redirect.');
    }

    // ---------------------------------------------------------------- login

    public function test_student_can_log_in_and_receives_a_token_and_user(): void
    {
        $student = $this->user(1, ['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);

        $response = $this->login('juan@example.com', 'password', 'Juan Pixel');

        $response->assertOk()
            ->assertExactJson([
                'token' => $response->json('token'),
                'user' => [
                    'id' => $student->id,
                    'name' => 'Juan Dela Cruz',
                    'email' => 'juan@example.com',
                    'role_id' => 1,
                    'role' => 'Student',
                ],
            ]);

        $this->assertIsString($response->json('token'));
        $this->assertStringContainsString('|', $response->json('token'));

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($student));
        $this->assertSame('Juan Pixel', $token->name);
    }

    public function test_supervisor_can_log_in(): void
    {
        $supervisor = $this->user(3, ['email' => 'sup@example.com']);

        $this->login('sup@example.com')
            ->assertOk()
            ->assertJsonPath('user.id', $supervisor->id)
            ->assertJsonPath('user.role_id', 3)
            ->assertJsonPath('user.role', 'Supervisor')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');
    }

    public function test_login_email_is_case_insensitive_like_the_website(): void
    {
        $this->user(1, ['email' => 'case@example.com']);

        // MySQL's default collation makes this match on the website too.
        $this->login('CASE@example.com')->assertOk();
    }

    public function test_wrong_password_returns_422_on_email_and_issues_no_token(): void
    {
        $this->user(1, ['email' => 'juan@example.com']);

        $response = $this->login('juan@example.com', 'wrong-password');

        $response->assertStatus(422)
            ->assertExactJson([
                'message' => self::FAILED_MESSAGE,
                'errors' => ['email' => [self::FAILED_MESSAGE]],
            ]);
        $this->assertJsonResponse($response);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_unknown_email_gets_exactly_the_same_response_as_a_wrong_password(): void
    {
        $this->user(1, ['email' => 'juan@example.com']);

        $wrongPassword = $this->login('juan@example.com', 'wrong-password');
        $unknownEmail = $this->login('nobody@example.com', 'wrong-password');

        $unknownEmail->assertStatus(422);
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_login_validates_required_fields(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password', 'device_name'])
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_login_validation_errors_are_json_even_without_an_accept_header(): void
    {
        $response = $this->post('/api/v1/login', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'device_name']);
        $this->assertJsonResponse($response);
    }

    public function test_administrator_and_coordinator_are_told_to_use_the_website(): void
    {
        foreach ([2 => 'coord@example.com', 4 => 'admin@example.com'] as $roleId => $email) {
            $this->user($roleId, ['email' => $email]);

            $response = $this->login($email);

            $response->assertForbidden()->assertExactJson([
                'message' => 'The mobile app is for students and supervisors only. Please use the website.',
                'code' => 'role_not_allowed',
            ]);
            $this->assertJsonResponse($response);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_admin_with_wrong_password_gets_422_not_a_role_hint(): void
    {
        $this->user(4, ['email' => 'admin@example.com']);

        $this->login('admin@example.com', 'wrong')
            ->assertStatus(422)
            ->assertJsonPath('message', self::FAILED_MESSAGE);
    }

    public function test_inactive_student_and_supervisor_cannot_log_in(): void
    {
        foreach ([1 => 'student@example.com', 3 => 'sup@example.com'] as $roleId => $email) {
            $this->user($roleId, ['email' => $email, 'status' => 'inactive']);

            $this->login($email)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'This account has been deactivated. Please contact an administrator.',
                    'code' => 'account_inactive',
                ]);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_is_rate_limited_to_five_attempts_per_minute_per_email_and_ip(): void
    {
        $this->user(1, ['email' => 'juan@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('juan@example.com', 'wrong')->assertStatus(422);
        }

        // Even the right password is refused once the limit is hit.
        $response = $this->login('juan@example.com', 'password');

        $response->assertStatus(429)->assertExactJson(['message' => 'Too Many Attempts.']);
        $response->assertHeader('Retry-After');
        $this->assertJsonResponse($response);

        // Case variations of the same email share the bucket.
        $this->login('JUAN@example.com', 'password')->assertStatus(429);

        // Another account from the same IP is unaffected.
        $this->user(1, ['email' => 'maria@example.com']);
        $this->login('maria@example.com')->assertOk();
    }

    // ------------------------------------------------------------------- me

    public function test_me_returns_the_student_profile_with_company_and_supervisor(): void
    {
        $supervisor = $this->user(3, ['name' => 'Supervisor Santos']);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        $student = Student::factory()->create([
            'student_number' => '2026-00001',
            'course' => 'BSIT',
            'section' => 'A',
            'internship_status' => 'ongoing',
            'required_hours' => 486,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);
        $token = $student->user->createToken('test')->plainTextToken;

        $response = $this->api('GET', '/api/v1/me', $token);

        $response->assertOk()->assertExactJson([
            'user' => [
                'id' => $student->user->id,
                'name' => $student->user->name,
                'email' => $student->user->email,
                'role_id' => 1,
                'role' => 'Student',
            ],
            'student' => [
                'id' => $student->id,
                'student_number' => '2026-00001',
                'course' => 'BSIT',
                'section' => 'A',
                'internship_status' => 'ongoing',
                'required_hours' => 486,
                'company' => ['id' => $company->id, 'name' => 'Acme Corp'],
                'supervisor' => ['id' => $supervisor->id, 'name' => 'Supervisor Santos'],
            ],
            'supervisor' => null,
        ]);
    }

    public function test_me_returns_nulls_for_a_student_without_company_or_supervisor(): void
    {
        $student = Student::factory()->create(['required_hours' => null]);
        $token = $student->user->createToken('test')->plainTextToken;

        $this->api('GET', '/api/v1/me', $token)
            ->assertOk()
            ->assertJsonPath('student.id', $student->id)
            ->assertJsonPath('student.company', null)
            ->assertJsonPath('student.supervisor', null)
            ->assertJsonPath('student.required_hours', null)
            ->assertJsonPath('supervisor', null);
    }

    public function test_me_returns_null_student_for_a_student_role_user_without_a_profile(): void
    {
        $user = $this->user(1);
        $token = $user->createToken('test')->plainTextToken;

        $this->api('GET', '/api/v1/me', $token)
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('student', null)
            ->assertJsonPath('supervisor', null);
    }

    public function test_me_for_a_supervisor_counts_only_their_own_students(): void
    {
        $supervisor = $this->user(3);
        $other = $this->user(3);
        Student::factory()->count(2)->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $other->id]);
        Student::factory()->create();
        $token = $supervisor->createToken('test')->plainTextToken;

        $this->api('GET', '/api/v1/me', $token)
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'email' => $supervisor->email,
                    'role_id' => 3,
                    'role' => 'Supervisor',
                ],
                'student' => null,
                'supervisor' => ['students_count' => 2],
            ]);
    }

    public function test_full_flow_login_then_me_with_the_issued_token(): void
    {
        $student = Student::factory()->create();
        $student->user->update(['email' => 'flow@example.com']);

        $token = $this->login('flow@example.com')->assertOk()->json('token');

        $this->api('GET', '/api/v1/me', $token)
            ->assertOk()
            ->assertJsonPath('student.id', $student->id);
    }

    // ------------------------------------------------------- authentication

    public function test_missing_token_returns_401_json_not_a_redirect(): void
    {
        foreach ([['GET', '/api/v1/me'], ['POST', '/api/v1/logout']] as [$method, $uri]) {
            $response = $this->api($method, $uri);

            $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
            $this->assertJsonResponse($response);
        }
    }

    public function test_garbage_token_returns_401(): void
    {
        $this->api('GET', '/api/v1/me', '999|not-a-real-token')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_web_session_does_not_authenticate_the_api(): void
    {
        $student = Student::factory()->create();

        $this->actingAs($student->user)
            ->get('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->user(1);
        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $response = $this->api('POST', '/api/v1/logout', $phone);
        $response->assertNoContent();
        $this->assertSame('', $response->getContent());

        $this->assertSame(['tablet'], $user->tokens()->pluck('name')->all());

        // The revoked token no longer works...
        $this->api('GET', '/api/v1/me', $phone)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        // ...but the other device stays signed in.
        $this->api('GET', '/api/v1/me', $tablet)->assertOk();
    }

    public function test_user_deactivated_after_login_is_rejected_and_the_token_revoked(): void
    {
        $supervisor = $this->user(3);
        $token = $supervisor->createToken('phone')->plainTextToken;

        $this->api('GET', '/api/v1/me', $token)->assertOk();

        $supervisor->update(['status' => 'inactive']);

        $response = $this->api('GET', '/api/v1/me', $token);
        $response->assertForbidden()->assertExactJson([
            'message' => 'This account has been deactivated. Please contact an administrator.',
            'code' => 'account_inactive',
        ]);
        $this->assertJsonResponse($response);
        $this->assertSame(0, $supervisor->tokens()->count());

        // Reactivating doesn't resurrect the revoked token.
        $supervisor->update(['status' => 'active']);
        $this->api('GET', '/api/v1/me', $token)->assertUnauthorized();
    }

    public function test_user_whose_role_changed_after_login_is_rejected_and_the_token_revoked(): void
    {
        $user = $this->user(1);
        $token = $user->createToken('phone')->plainTextToken;

        $user->update(['role_id' => 2]);

        $this->api('GET', '/api/v1/me', $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_allowed');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admin_holding_a_token_cannot_use_the_api(): void
    {
        $admin = $this->user(4);
        $token = $admin->createToken('script')->plainTextToken;

        $this->api('GET', '/api/v1/me', $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_allowed');
        $this->api('POST', '/api/v1/logout', $token)->assertUnauthorized();
    }

    public function test_successful_authenticated_requests_update_last_used_at(): void
    {
        $user = $this->user(1);
        $token = $user->createToken('phone');

        $this->api('GET', '/api/v1/me', $token->plainTextToken)->assertOk();

        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
    }

    // --------------------------------------------------------------- errors

    public function test_unknown_api_route_returns_json_404(): void
    {
        foreach (['/api/v1/does-not-exist', '/api', '/api/v2/me'] as $uri) {
            $response = $this->api('GET', $uri);

            $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->assertJsonResponse($response);
        }
    }

    public function test_missing_model_returns_json_404_without_leaking_the_model_class(): void
    {
        // Simulates a later-module route with implicit model binding.
        Route::middleware(['api', 'auth:sanctum', 'mobile'])
            ->get('/api/v1/_test/students/{student}', fn (Student $student) => ['id' => $student->id]);

        $token = $this->user(3)->createToken('phone')->plainTextToken;

        $response = $this->api('GET', '/api/v1/_test/students/999999', $token);

        $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_wrong_http_method_returns_json_405(): void
    {
        $response = $this->api('GET', '/api/v1/login');

        $response->assertStatus(405)->assertExactJson(['message' => 'Method not allowed.']);
        $this->assertJsonResponse($response);
    }

    public function test_role_middleware_answers_api_requests_with_json(): void
    {
        // Simulates a later-module route narrowed with role:3.
        Route::middleware(['api', 'auth:sanctum', 'mobile', 'role:3'])
            ->get('/api/v1/_test/supervisor-only', fn () => ['ok' => true]);

        $student = $this->user(1);
        $token = $student->createToken('phone')->plainTextToken;

        $response = $this->api('GET', '/api/v1/_test/supervisor-only', $token);
        $response->assertForbidden()->assertExactJson(['message' => 'Unauthorized access']);
        $this->assertJsonResponse($response);

        // The student's token is not revoked by a plain role mismatch.
        $this->api('GET', '/api/v1/me', $token)->assertOk();
    }

    public function test_web_routes_still_redirect_guests_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
