<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\InternshipAssignmentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 15: Internship Assignment through /api/v1 (Coordinator,
 * Administrator), sharing InternshipAssignmentService with the website.
 */
class InternshipAssignmentApiTest extends TestCase
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

    private function student(array $attributes = [], array $userAttributes = []): Student
    {
        $user = $this->user(User::ROLE_STUDENT, $userAttributes);

        return Student::factory()->create($attributes + ['user_id' => $user->id]);
    }

    private function payload(Student $student, array $overrides = []): array
    {
        $student->refresh()->load('user');

        return array_merge([
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $student->company_id,
            'supervisor_id' => $student->supervisor_id,
            'internship_status' => $student->internship_status,
            'internship_schedule' => $student->internship_schedule,
        ], $overrides);
    }

    private function supervisorAt(Company $company, array $attributes = []): User
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, $attributes);
        $company->supervisors()->attach($supervisor->id);

        return $supervisor;
    }

    // ---- Access -------------------------------------------------------

    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/api/v1/assignments'],
            'options' => ['GET', '/api/v1/assignments/options'],
            'show' => ['GET', '/api/v1/assignments/{id}'],
            'update' => ['PATCH', '/api/v1/assignments/{id}'],
            'activate' => ['POST', '/api/v1/assignments/{id}/activate'],
            'deactivate' => ['POST', '/api/v1/assignments/{id}/deactivate'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_students_and_supervisors_are_forbidden(string $method, string $uri): void
    {
        $student = $this->student(['internship_status' => 'not_started']);
        $uri = str_replace('{id}', (string) $student->id, $uri);

        foreach ([User::ROLE_STUDENT, User::ROLE_SUPERVISOR] as $roleId) {
            $this->api($method, $uri, $this->user($roleId), $this->payload($student, ['internship_status' => 'ongoing']))
                ->assertForbidden()
                ->assertExactJson(['message' => 'Unauthorized access']);
        }

        // The student themself can't touch their own record either.
        $this->api($method, $uri, $student->user, $this->payload($student, ['internship_status' => 'ongoing']))
            ->assertForbidden();

        $this->assertSame('not_started', $student->fresh()->internship_status);
        $this->assertSame('active', $student->user->fresh()->status);
    }

    #[DataProvider('endpoints')]
    public function test_no_token_is_401(string $method, string $uri): void
    {
        $student = $this->student();

        $this->api($method, str_replace('{id}', (string) $student->id, $uri), null)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_unknown_ids_are_404(string $method, string $uri): void
    {
        if (! str_contains($uri, '{id}')) {
            $this->assertTrue(true);

            return;
        }

        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $student = $this->student();

        foreach (['999999', '0', 'abc', '99999999999999999999'] as $id) {
            $this->api($method, str_replace('{id}', $id, $uri), $coordinator, $this->payload($student))
                ->assertNotFound();
        }
    }

    public function test_coordinator_and_admin_can_list(): void
    {
        $this->student();

        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $roleId) {
            $this->api('GET', '/api/v1/assignments', $this->user($roleId))
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    // ---- List / show / options ---------------------------------------

    public function test_list_shape_and_pagination(): void
    {
        $company = Company::factory()->create(['company_name' => 'Acme Corp', 'slots' => 5]);
        $supervisor = $this->supervisorAt($company, ['name' => 'Maria Santos']);
        $student = $this->student([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'internship_status' => 'ongoing',
            'internship_schedule' => 'Mon-Fri',
            'student_number' => '2024-0001',
        ], ['name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);

        $this->student();
        $this->student();

        $response = $this->api('GET', '/api/v1/assignments?per_page=2', $this->user(User::ROLE_ADMIN))
            ->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'has_more' => true]);

        $row = $this->api('GET', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_ADMIN))
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'id' => $student->id,
            'user_id' => $student->user_id,
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'status' => 'active',
            'student_number' => '2024-0001',
            'course' => $student->course,
            'section' => $student->section,
            'company' => ['id' => $company->id, 'company_name' => 'Acme Corp', 'status' => 'active'],
            'supervisor' => ['id' => $supervisor->id, 'name' => 'Maria Santos', 'email' => $supervisor->email, 'status' => 'active'],
            'supervisor_on_roster' => true,
            'internship_status' => 'ongoing',
            'internship_schedule' => 'Mon-Fri',
            'created_at' => $student->fresh()->created_at->toIso8601String(),
            'updated_at' => $student->fresh()->updated_at->toIso8601String(),
            'can_edit' => true,
            'can_toggle_status' => true,
        ], $row);
        $this->assertStringEndsWith('+08:00', $row['created_at']);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_drifted_supervisor_is_flagged_off_roster(): void
    {
        $company = Company::factory()->create();
        $supervisor = $this->supervisorAt($company);
        $student = $this->student(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        $company->supervisors()->detach($supervisor->id);
        $unassigned = $this->student();

        $admin = $this->user(User::ROLE_ADMIN);

        $this->api('GET', "/api/v1/assignments/{$student->id}", $admin)->assertJsonPath('data.supervisor_on_roster', false);
        $this->api('GET', "/api/v1/assignments/{$unassigned->id}", $admin)
            ->assertJsonPath('data.supervisor_on_roster', null)
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.supervisor', null);
    }

    public function test_list_filters(): void
    {
        $company = Company::factory()->create();
        $supervisor = $this->supervisorAt($company);
        $a = $this->student(['company_id' => $company->id, 'supervisor_id' => $supervisor->id, 'internship_status' => 'ongoing', 'student_number' => 'SN-100'], ['name' => 'Alice Reyes', 'email' => 'alice@example.com']);
        $b = $this->student(['internship_status' => 'completed', 'student_number' => 'SN-200'], ['name' => 'Bob 100% Tan', 'email' => 'bob@example.com', 'status' => 'inactive']);
        $c = $this->student(['company_id' => $company->id, 'student_number' => 'SN-300'], ['name' => 'Carla', 'email' => 'carla@example.com']);

        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $ids = fn (string $query) => collect($this->api('GET', '/api/v1/assignments?'.$query, $coordinator)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$a->id], $ids('search=alice'));
        $this->assertSame([$b->id], $ids('search=bob@example'));
        $this->assertSame([$c->id], $ids('search=SN-3'));
        $this->assertSame([$b->id], $ids('search='.urlencode('100%')));
        $this->assertSame([$a->id], $ids('internship_status=ongoing'));
        $this->assertSame([$b->id], $ids('status=inactive'));
        $this->assertSame([$a->id, $c->id], $ids('company_id='.$company->id));
        $this->assertSame([$b->id], $ids('company_id=none'));
        $this->assertSame([$a->id], $ids('supervisor_id='.$supervisor->id));
        $this->assertSame([$b->id, $c->id], $ids('supervisor_id=none'));
        $this->assertSame([$a->id, $b->id, $c->id], $ids('search=&status=&company_id='));
    }

    public function test_invalid_list_queries_are_422(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([
            'internship_status=done' => 'internship_status',
            'status=gone' => 'status',
            'company_id=abc' => 'company_id',
            'supervisor_id=-1' => 'supervisor_id',
            'company_id[]=1' => 'company_id',
            'per_page=51' => 'per_page',
            'page=0' => 'page',
        ] as $query => $field) {
            $this->api('GET', '/api/v1/assignments?'.$query, $coordinator)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_options_list_every_company_with_roster_and_statuses(): void
    {
        $acme = Company::factory()->create(['company_name' => 'Acme', 'slots' => 2]);
        $zeta = Company::factory()->inactive()->create(['company_name' => 'Zeta', 'slots' => 1]);
        $maria = $this->supervisorAt($acme, ['name' => 'Maria']);
        $ana = $this->supervisorAt($acme, ['name' => 'Ana', 'status' => 'inactive']);
        $this->student(['company_id' => $acme->id]);
        $this->student(['company_id' => $zeta->id]);

        $this->api('GET', '/api/v1/assignments/options', $this->user(User::ROLE_COORDINATOR))
            ->assertOk()
            ->assertExactJson([
                'companies' => [
                    [
                        'id' => $acme->id, 'company_name' => 'Acme', 'status' => 'active',
                        'slots' => 2, 'students_count' => 1, 'slots_available' => 1,
                        'supervisors' => [
                            ['id' => $ana->id, 'name' => 'Ana', 'email' => $ana->email, 'status' => 'inactive'],
                            ['id' => $maria->id, 'name' => 'Maria', 'email' => $maria->email, 'status' => 'active'],
                        ],
                    ],
                    [
                        'id' => $zeta->id, 'company_name' => 'Zeta', 'status' => 'inactive',
                        'slots' => 1, 'students_count' => 1, 'slots_available' => 0,
                        'supervisors' => [],
                    ],
                ],
                'internship_statuses' => [
                    ['value' => 'not_started', 'label' => 'Not Started'],
                    ['value' => 'ongoing', 'label' => 'Ongoing'],
                    ['value' => 'completed', 'label' => 'Completed'],
                ],
            ]);
    }

    // ---- Update ------------------------------------------------------

    public function test_coordinator_assigns_company_and_supervisor_and_both_are_notified(): void
    {
        $company = Company::factory()->create(['slots' => 2]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student();

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, [
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'internship_status' => 'ongoing',
            'internship_schedule' => 'Mon-Fri 8-5',
            'name' => 'New Name',
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Student updated successfully.')
            ->assertJsonPath('student.company.id', $company->id)
            ->assertJsonPath('student.supervisor.id', $supervisor->id)
            ->assertJsonPath('student.internship_status', 'ongoing')
            ->assertJsonPath('student.internship_schedule', 'Mon-Fri 8-5')
            ->assertJsonPath('student.name', 'New Name');

        $this->assertSame(1, Notification::where('type', 'assignment_updated')->where('user_id', $student->user_id)->count());
        $this->assertSame(1, Notification::where('type', 'assignment_updated')->where('user_id', $supervisor->id)->count());
    }

    public function test_profile_only_edit_notifies_nobody_and_blank_ids_mean_none(): void
    {
        $student = $this->student();

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_ADMIN), $this->payload($student, [
            'section' => 'Q',
            'company_id' => '',
            'supervisor_id' => null,
        ]))->assertOk()->assertJsonPath('student.section', 'Q')->assertJsonPath('student.company', null);

        $this->assertSame(0, Notification::count());
    }

    public function test_validation_errors_are_422_with_field_keys(): void
    {
        $other = $this->student();
        $student = $this->student();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, [
            'email' => $other->user->email,
            'student_number' => $other->student_number,
            'internship_status' => 'done',
            'company_id' => 999999,
            'supervisor_id' => $coordinator->id,
            'internship_schedule' => str_repeat('x', 256),
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'email', 'student_number', 'course', 'section', 'internship_status', 'company_id', 'supervisor_id', 'internship_schedule',
        ]);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, [
            'company_id' => [1], 'supervisor_id' => ['x'], 'email' => ['a@b.test'], 'student_number' => ['1'], 'internship_status' => ['ongoing'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'supervisor_id', 'email', 'student_number', 'internship_status']);

        // Keeping your own email / student number is fine.
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student))->assertOk();
    }

    private function assertRefused(TestResponse $response, string $code, string $field, ?string $message = null): void
    {
        $response->assertUnprocessable()
            ->assertJsonPath('code', $code)
            ->assertJsonValidationErrors($field);

        $this->assertSame($response->json("errors.{$field}.0"), $response->json('message'));

        if ($message !== null) {
            $response->assertJsonPath('message', $message);
        }
    }

    public function test_supervisor_not_on_roster_is_refused(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $outsider = $this->user(User::ROLE_SUPERVISOR);
        $student = $this->student(['company_id' => $company->id]);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['supervisor_id' => $outsider->id])),
            'supervisor_not_on_roster', 'supervisor_id', "This supervisor is not on the selected company's roster.",
        );

        $this->assertNull($student->fresh()->supervisor_id);
        $this->assertSame(0, Notification::count());
    }

    public function test_supervisor_from_another_companys_roster_is_refused_when_moving_company(): void
    {
        $old = Company::factory()->create(['slots' => 5]);
        $new = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->supervisorAt($old);
        $student = $this->student(['company_id' => $old->id, 'supervisor_id' => $supervisor->id]);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['company_id' => $new->id])),
            'supervisor_not_on_roster', 'supervisor_id',
        );

        $this->assertSame($old->id, $student->fresh()->company_id);
    }

    public function test_supervisor_without_company_is_refused(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['company_id' => null])),
            'supervisor_needs_company', 'supervisor_id', 'Assign a company before assigning a supervisor.',
        );

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['company_id' => null, 'supervisor_id' => null]))
            ->assertOk()
            ->assertJsonPath('student.company', null)
            ->assertJsonPath('student.supervisor', null);
    }

    public function test_drifted_unchanged_supervisor_does_not_block_other_edits(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        $company->supervisors()->detach($supervisor->id);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['internship_status' => 'completed']))
            ->assertOk()
            ->assertJsonPath('student.supervisor_on_roster', false);
    }

    public function test_full_company_is_refused_but_staying_there_is_fine(): void
    {
        $company = Company::factory()->create(['company_name' => 'Acme Corp', 'slots' => 1]);
        $stayer = $this->student(['company_id' => $company->id]);
        $student = $this->student();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, ['company_id' => $company->id])),
            'company_full', 'company_id', 'Acme Corp has no available slots (1/1 filled).',
        );
        $this->assertNull($student->fresh()->company_id);

        $this->api('PATCH', "/api/v1/assignments/{$stayer->id}", $coordinator, $this->payload($stayer, ['internship_status' => 'ongoing']))
            ->assertOk();
    }

    public function test_inactive_company_is_refused_for_new_assignments_only(): void
    {
        $company = Company::factory()->inactive()->create(['company_name' => 'Dormant Inc', 'slots' => 5]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student();
        $resident = $this->student(['company_id' => $company->id]);
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, ['company_id' => $company->id])),
            'company_inactive', 'company_id', 'Dormant Inc is inactive. Activate it in Company Management before assigning students to it.',
        );

        // A new supervisor at an inactive company is a new assignment too.
        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$resident->id}", $coordinator, $this->payload($resident, ['supervisor_id' => $supervisor->id])),
            'company_inactive', 'company_id',
        );

        // Unchanged re-save works.
        $this->api('PATCH', "/api/v1/assignments/{$resident->id}", $coordinator, $this->payload($resident, ['internship_status' => 'completed']))
            ->assertOk();
    }

    public function test_ids_that_are_not_numbers_are_422_not_an_id_or_none(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([true, false, [], [$company->id], 0, '0', -1, 1.5, 'abc'] as $bad) {
            foreach (['company_id', 'supervisor_id'] as $field) {
                $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, [$field => $bad]))
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors($field);
            }
        }

        $this->assertDatabaseHas('students', ['id' => $student->id, 'company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        $this->assertSame(0, Notification::count());

        // Numeric strings are ids, as the website sends them.
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, [
            'company_id' => (string) $company->id,
            'supervisor_id' => (string) $supervisor->id,
        ]))->assertOk();
    }

    public function test_an_inactive_supervisor_cannot_be_taken_along_to_a_new_company(): void
    {
        $old = Company::factory()->create(['slots' => 5]);
        $new = Company::factory()->create(['slots' => 5]);
        $inactive = $this->supervisorAt($old, ['status' => 'inactive']);
        $new->supervisors()->attach($inactive->id);
        $student = $this->student(['company_id' => $old->id, 'supervisor_id' => $inactive->id]);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['company_id' => $new->id])),
            'supervisor_inactive', 'supervisor_id',
        );

        $this->assertSame($old->id, $student->fresh()->company_id);

        // Moving without them works.
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->user(User::ROLE_COORDINATOR), $this->payload($student, ['company_id' => $new->id, 'supervisor_id' => null]))
            ->assertOk();
    }

    public function test_inactive_supervisor_is_refused_for_new_assignments_only(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $inactive = $this->supervisorAt($company, ['status' => 'inactive']);
        $student = $this->student(['company_id' => $company->id]);
        $kept = $this->student(['company_id' => $company->id, 'supervisor_id' => $inactive->id]);
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->assertRefused(
            $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $this->payload($student, ['supervisor_id' => $inactive->id])),
            'supervisor_inactive', 'supervisor_id', "This supervisor's account is inactive. Activate it in User Management before assigning students to them.",
        );

        $this->api('PATCH', "/api/v1/assignments/{$kept->id}", $coordinator, $this->payload($kept, ['section' => 'Z']))
            ->assertOk();
    }

    public function test_company_deleted_meanwhile_is_a_validation_error(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $student = $this->student();
        $data = $this->payload($student, ['company_id' => $company->id]);
        $company->delete();

        try {
            app(InternshipAssignmentService::class)->update($student, $data);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('company_id', $e->errors());
        }

        $this->assertNull($student->fresh()->company_id);
    }

    public function test_student_deleted_meanwhile_is_not_found(): void
    {
        $student = $this->student();
        $data = $this->payload($student);
        Student::query()->whereKey($student->id)->delete();

        $this->expectException(ModelNotFoundException::class);

        app(InternshipAssignmentService::class)->update($student, $data);
    }

    public function test_racing_duplicate_email_is_a_422_not_a_500(): void
    {
        $other = $this->student();
        $student = $this->student();

        // As if another request took the email after validation passed.
        try {
            app(InternshipAssignmentService::class)->update($student, $this->payload($student, ['email' => $other->user->email]));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        try {
            app(InternshipAssignmentService::class)->update($student, $this->payload($student, ['student_number' => $other->student_number]));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_number', $e->errors());
        }
    }

    public function test_supervisor_demoted_meanwhile_is_refused_under_the_lock(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->supervisorAt($company);
        $student = $this->student(['company_id' => $company->id]);
        $data = $this->payload($student, ['supervisor_id' => $supervisor->id]);
        $supervisor->update(['role_id' => User::ROLE_COORDINATOR]);

        try {
            app(InternshipAssignmentService::class)->update($student, $data);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supervisor_id', $e->errors());
        }

        $this->assertNull($student->fresh()->supervisor_id);
    }

    // ---- Activate / deactivate --------------------------------------

    public function test_deactivate_revokes_tokens_and_both_are_idempotent(): void
    {
        $student = $this->student();
        $phone = $student->user->createToken('phone')->plainTextToken;
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        foreach ([1, 2] as $attempt) {
            $this->api('POST', "/api/v1/assignments/{$student->id}/deactivate", $coordinator)
                ->assertOk()
                ->assertJsonPath('message', 'Student status updated.')
                ->assertJsonPath('student.status', 'inactive');
        }

        $this->assertSame(0, $student->user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$phone])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        foreach ([1, 2] as $attempt) {
            $this->api('POST', "/api/v1/assignments/{$student->id}/activate", $this->user(User::ROLE_ADMIN))
                ->assertOk()
                ->assertJsonPath('student.status', 'active');
        }

        $student->user->createToken('new-phone');
        $this->api('POST', "/api/v1/assignments/{$student->id}/activate", $coordinator)->assertOk();
        $this->assertSame(1, $student->user->tokens()->count());
        $this->assertSame(0, Notification::count());
    }

    public function test_web_toggle_still_flips_status(): void
    {
        $student = $this->student();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->actingAs($coordinator)->patch(route('internship-assignment.toggle-status', $student))->assertRedirect();
        $this->assertSame('inactive', $student->user->fresh()->status);

        $this->actingAs($coordinator)->patch(route('internship-assignment.toggle-status', $student))->assertRedirect();
        $this->assertSame('active', $student->user->fresh()->status);
    }
}
