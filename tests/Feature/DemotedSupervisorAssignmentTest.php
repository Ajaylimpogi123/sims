<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A supervisor whose role was changed in User Management keeps their
 * company_supervisors rows and students.supervisor_id. Internship
 * Assignment (website + API) never offers them as a pick and refuses them
 * as a new assignment, but lets a student's unchanged assignment to them be
 * re-saved (like an inactive or off-roster supervisor) so other fields stay
 * editable.
 */
class DemotedSupervisorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $demoted;

    private User $supervisor;

    private User $inactiveSupervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create(['slots' => 10, 'status' => 'active']);
        $this->supervisor = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Active Supervisor']);
        $this->inactiveSupervisor = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Inactive Supervisor', 'status' => 'inactive']);
        $this->demoted = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Demoted Person']);
        $this->company->supervisors()->attach([$this->supervisor->id, $this->inactiveSupervisor->id, $this->demoted->id]);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function demote(): void
    {
        $this->demoted->update(['role_id' => User::ROLE_COORDINATOR]);
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

    private function web(Student $student, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/internship-assignment/{$student->id}", $this->payload($student, $overrides));
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->user(User::ROLE_ADMIN)->createToken('test')->plainTextToken,
        ])->json($method, $uri, $data);
    }

    /** A student assigned to the (then) supervisor, who is now demoted. */
    private function studentOfDemoted(): Student
    {
        $student = Student::factory()->create([
            'company_id' => $this->company->id,
            'supervisor_id' => $this->demoted->id,
            'internship_status' => 'ongoing',
        ]);
        $this->demote();

        return $student;
    }

    // ------------------------------------------------------------ options

    public function test_web_rosters_only_offer_supervisor_accounts(): void
    {
        $this->demote();

        $response = $this->actingAs($this->user(User::ROLE_COORDINATOR))->get('/internship-assignment')->assertOk();

        $roster = collect($response->viewData('page')['props']['companies'][0]['supervisors']);
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $this->inactiveSupervisor->id], $roster->pluck('id')->all());
        $this->assertSame(['id', 'name', 'email', 'status'], array_keys(array_diff_key($roster->first(), ['pivot' => true])));
    }

    public function test_api_options_rosters_only_offer_supervisor_accounts(): void
    {
        $this->demote();

        $roster = $this->api('GET', '/api/v1/assignments/options')->assertOk()->json('companies.0.supervisors');

        $this->assertSame(['Active Supervisor', 'Inactive Supervisor'], array_column($roster, 'name'));
    }

    public function test_the_current_demoted_supervisor_still_shows_on_the_students_own_fields(): void
    {
        $student = $this->studentOfDemoted();

        $response = $this->actingAs($this->user(User::ROLE_COORDINATOR))->get('/internship-assignment')->assertOk();
        $row = collect($response->viewData('page')['props']['students'])->firstWhere('id', $student->id);
        $this->assertSame($this->demoted->id, $row['supervisor_id']);
        $this->assertSame('Demoted Person', $row['supervisor']['name']);

        $this->api('GET', "/api/v1/assignments/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->demoted->id)
            ->assertJsonPath('data.supervisor.name', 'Demoted Person');
    }

    // ------------------------------------------------------------ saves

    public function test_an_unchanged_demoted_supervisor_can_be_kept_while_editing_other_fields(): void
    {
        $student = $this->studentOfDemoted();

        $this->web($student, ['course' => 'BSCS', 'internship_status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'course' => 'BSCS', 'internship_status' => 'completed', 'supervisor_id' => $this->demoted->id]);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->payload($student, ['section' => 'Z']))
            ->assertOk()
            ->assertJsonPath('student.section', 'Z')
            ->assertJsonPath('student.supervisor.id', $this->demoted->id)
            ->assertJsonPath('student.needs_supervisor', true);
    }

    public function test_a_demoted_account_is_refused_as_a_new_pick(): void
    {
        $this->demote();
        $student = Student::factory()->create(['company_id' => $this->company->id]);

        $this->web($student, ['supervisor_id' => $this->demoted->id])
            ->assertSessionHasErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->payload($student, ['supervisor_id' => $this->demoted->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);

        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => null]);
    }

    public function test_switching_from_one_supervisor_to_a_demoted_account_is_refused(): void
    {
        $student = Student::factory()->create(['company_id' => $this->company->id, 'supervisor_id' => $this->supervisor->id]);
        $this->demote();

        $this->web($student, ['supervisor_id' => $this->demoted->id])->assertSessionHasErrors('supervisor_id');
        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => $this->supervisor->id]);
    }

    public function test_a_demoted_supervisor_cannot_be_taken_along_to_a_new_company(): void
    {
        $student = $this->studentOfDemoted();
        $other = Company::factory()->create(['slots' => 10, 'status' => 'active']);
        $other->supervisors()->attach($this->demoted->id);

        $this->web($student, ['company_id' => $other->id])
            ->assertSessionHasErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->payload($student, ['company_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertDatabaseHas('students', ['id' => $student->id, 'company_id' => $this->company->id, 'supervisor_id' => $this->demoted->id]);
    }

    public function test_a_demoted_supervisor_can_be_replaced_or_cleared(): void
    {
        $student = $this->studentOfDemoted();

        $this->web($student, ['supervisor_id' => $this->supervisor->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => $this->supervisor->id]);

        $second = $this->studentOfDemoted();
        $this->web($second, ['supervisor_id' => null])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $second->id, 'supervisor_id' => null]);
    }

    public function test_a_non_supervisor_id_with_array_company_is_still_a_validation_error(): void
    {
        $student = $this->studentOfDemoted();

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->payload($student, ['company_id' => [$this->company->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }
}
