<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A Company Supervisor whose role was changed in User Management stays
 * companies.supervisor_id and students.supervisor_id. Company Management
 * (website + API) never offers them as a pick and refuses them as a new
 * supervisor, but lets the company's unchanged supervisor be re-saved so
 * other fields stay editable; Internship Assignment keeps them on the
 * company's students.
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

        $this->supervisor = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Active Supervisor']);
        $this->inactiveSupervisor = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Inactive Supervisor', 'status' => 'inactive']);
        $this->demoted = $this->user(User::ROLE_SUPERVISOR, ['name' => 'Demoted Person']);
        $this->company = Company::factory()->create(['slots' => 10, 'status' => 'active', 'supervisor_id' => $this->demoted->id]);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function demote(): void
    {
        $this->demoted->update(['role_id' => User::ROLE_COORDINATOR]);
    }

    private function companyPayload(Company $company, array $overrides = []): array
    {
        $company->refresh();

        return array_merge([
            'company_name' => $company->company_name,
            'address' => $company->address,
            'contact_number' => $company->contact_number,
            'email' => $company->email,
            'industry' => $company->industry,
            'slots' => $company->slots,
            'supervisor_id' => $company->supervisor_id,
        ], $overrides);
    }

    private function studentPayload(Student $student, array $overrides = []): array
    {
        $student->refresh()->load('user');

        return array_merge([
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $student->company_id,
            'internship_status' => $student->internship_status,
            'internship_schedule' => $student->internship_schedule,
        ], $overrides);
    }

    private function webCompany(Company $company, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/company-management/{$company->id}", $this->companyPayload($company, $overrides));
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->user(User::ROLE_ADMIN)->createToken('test')->plainTextToken,
        ])->json($method, $uri, $data);
    }

    /** A student placed at the company under the (then) supervisor, who is now demoted. */
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

    public function test_web_supervisor_options_only_offer_active_supervisor_accounts(): void
    {
        $this->demote();

        $this->actingAs($this->user(User::ROLE_COORDINATOR))->get('/company-management')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('supervisorOptions', fn ($options) => collect($options)->pluck('id')->all() === [$this->supervisor->id])
                ->where('companies.0.supervisor_issue', 'not_a_supervisor'));
    }

    public function test_api_supervisor_options_only_offer_active_supervisor_accounts(): void
    {
        $this->demote();

        $this->api('GET', '/api/v1/companies/supervisor-options')
            ->assertOk()
            ->assertJsonPath('data', [[
                'id' => $this->supervisor->id,
                'name' => 'Active Supervisor',
                'email' => $this->supervisor->email,
            ]]);

        $this->api('GET', "/api/v1/companies/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->demoted->id)
            ->assertJsonPath('data.supervisor.role_id', User::ROLE_COORDINATOR)
            ->assertJsonPath('data.supervisor_issue', 'not_a_supervisor');
    }

    public function test_the_current_demoted_supervisor_still_shows_on_the_students_own_fields(): void
    {
        $student = $this->studentOfDemoted();

        $response = $this->actingAs($this->user(User::ROLE_COORDINATOR))->get('/internship-assignment')->assertOk();
        $row = collect($response->viewData('page')['props']['students'])->firstWhere('id', $student->id);
        $this->assertSame($this->demoted->id, $row['supervisor_id']);
        $this->assertSame('Demoted Person', $row['supervisor']['name']);
        $this->assertSame('not_a_supervisor', $row['supervisor_issue']);

        $this->api('GET', "/api/v1/assignments/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.supervisor.id', $this->demoted->id)
            ->assertJsonPath('data.supervisor.name', 'Demoted Person');
    }

    // ------------------------------------------------------------ saves

    public function test_an_unchanged_demoted_supervisor_can_be_kept_while_editing_other_fields(): void
    {
        $student = $this->studentOfDemoted();

        $this->webCompany($this->company, ['industry' => 'Retail'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'industry' => 'Retail', 'supervisor_id' => $this->demoted->id]);

        $this->api('PATCH', "/api/v1/companies/{$this->company->id}", $this->companyPayload($this->company, ['address' => 'Z']))
            ->assertOk()
            ->assertJsonPath('company.address', 'Z')
            ->assertJsonPath('company.supervisor.id', $this->demoted->id)
            ->assertJsonPath('students_reassigned', 0);

        // The student's own save keeps them too.
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->studentPayload($student, ['section' => 'Z']))
            ->assertOk()
            ->assertJsonPath('student.section', 'Z')
            ->assertJsonPath('student.supervisor.id', $this->demoted->id)
            ->assertJsonPath('student.needs_supervisor', true);
    }

    public function test_a_demoted_account_is_refused_as_a_new_company_supervisor(): void
    {
        $this->demote();
        $other = Company::factory()->create(['slots' => 10, 'supervisor_id' => $this->supervisor->id]);

        $this->webCompany($other, ['supervisor_id' => $this->demoted->id])
            ->assertSessionHasErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);

        $this->api('PATCH', "/api/v1/companies/{$other->id}", $this->companyPayload($other, ['supervisor_id' => $this->demoted->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supervisor_id' => 'The selected supervisor id is invalid.']);

        $this->api('POST', '/api/v1/companies', ['company_name' => 'New Co', 'slots' => 1, 'supervisor_id' => $this->demoted->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id');

        $this->assertDatabaseHas('companies', ['id' => $other->id, 'supervisor_id' => $this->supervisor->id]);
        $this->assertDatabaseMissing('companies', ['company_name' => 'New Co']);
    }

    public function test_an_inactive_supervisor_is_refused_as_a_new_pick_with_its_own_message(): void
    {
        $other = Company::factory()->create(['slots' => 10]);

        $this->webCompany($other, ['supervisor_id' => $this->inactiveSupervisor->id])
            ->assertSessionHasErrors(['supervisor_id' => 'This supervisor\'s account is inactive. Activate it in User Management before assigning it to a company.']);

        $this->assertDatabaseHas('companies', ['id' => $other->id, 'supervisor_id' => null]);
    }

    public function test_moving_a_student_gives_them_the_new_companys_supervisor(): void
    {
        $student = $this->studentOfDemoted();
        $other = Company::factory()->create(['slots' => 10, 'status' => 'active', 'supervisor_id' => $this->supervisor->id]);

        // A sent supervisor_id is ignored: the company decides.
        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch("/internship-assignment/{$student->id}", $this->studentPayload($student, ['company_id' => $other->id, 'supervisor_id' => $this->demoted->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'company_id' => $other->id, 'supervisor_id' => $this->supervisor->id]);
    }

    public function test_a_demoted_supervisor_can_be_replaced_or_cleared_and_the_students_follow(): void
    {
        $student = $this->studentOfDemoted();

        $this->webCompany($this->company, ['supervisor_id' => $this->supervisor->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Company updated successfully. 1 student(s) moved to Active Supervisor.');
        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => $this->supervisor->id]);

        $this->webCompany($this->company, ['supervisor_id' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Company updated successfully. 1 student(s) no longer have a supervisor.');
        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'supervisor_id' => null]);
        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => null]);
    }

    public function test_a_non_supervisor_id_with_array_company_is_still_a_validation_error(): void
    {
        $student = $this->studentOfDemoted();

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $this->studentPayload($student, ['company_id' => [$this->company->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }
}
