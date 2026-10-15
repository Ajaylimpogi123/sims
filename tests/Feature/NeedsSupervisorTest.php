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
 * A student placed at a company with no supervisor, or one no longer on
 * that company's roster, "needs a supervisor": flagged on Internship
 * Assignment (website + API), filterable, and counted as a Coordinator /
 * Admin dashboard action item.
 */
class NeedsSupervisorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Student> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    /**
     * no_company: no company, no supervisor (not flagged)
     * no_supervisor: company, no supervisor (flagged)
     * off_roster: company, supervisor dropped from its roster (flagged)
     * on_roster: company, roster supervisor (not flagged)
     * inactive_supervisor: company, inactive roster supervisor (not flagged)
     * demoted: company, roster "supervisor" whose account became a
     *   Coordinator in User Management (flagged)
     */
    private function fixture(): void
    {
        $company = Company::factory()->create(['slots' => 10]);
        $other = Company::factory()->create(['slots' => 10]);

        $onRoster = $this->user(User::ROLE_SUPERVISOR);
        $inactive = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        // Still on the roster and still students.supervisor_id, but no longer
        // a Supervisor: a role change leaves both behind.
        $demoted = $this->user(User::ROLE_SUPERVISOR);
        $company->supervisors()->attach([$onRoster->id, $inactive->id, $demoted->id]);
        // On another company's roster only.
        $dropped = $this->user(User::ROLE_SUPERVISOR);
        $other->supervisors()->attach($dropped->id);

        $this->students = [
            'no_company' => Student::factory()->create(),
            'no_supervisor' => Student::factory()->create(['company_id' => $company->id]),
            'off_roster' => Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $dropped->id]),
            'on_roster' => Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $onRoster->id]),
            'inactive_supervisor' => Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $inactive->id]),
            'demoted' => Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $demoted->id]),
        ];

        $demoted->update(['role_id' => User::ROLE_COORDINATOR]);
    }

    private function flagged(): array
    {
        return [$this->students['no_supervisor']->id, $this->students['off_roster']->id, $this->students['demoted']->id];
    }

    private function api(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ])->json($method, $uri, $data);
    }

    // ------------------------------------------------------------ scope

    public function test_the_scope_matches_company_without_a_roster_supervisor(): void
    {
        $this->fixture();

        $this->assertEqualsCanonicalizing($this->flagged(), Student::query()->needsSupervisor()->pluck('id')->all());
    }

    public function test_a_supervisor_demoted_in_user_management_leaves_their_students_needing_one(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->assertSame(0, Student::query()->needsSupervisor()->count());

        $this->actingAs($admin)->patch("/user-management/{$supervisor->id}", [
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'role_id' => User::ROLE_COORDINATOR,
        ])->assertSessionHasNoErrors();

        // The role change leaves supervisor_id and the roster row behind.
        $this->assertDatabaseHas('students', ['id' => $student->id, 'supervisor_id' => $supervisor->id]);
        $this->assertDatabaseHas('company_supervisors', ['company_id' => $company->id, 'user_id' => $supervisor->id]);

        $this->assertSame([$student->id], Student::query()->needsSupervisor()->pluck('id')->all());

        $this->api('GET', "/api/v1/assignments/{$student->id}", $admin)
            ->assertOk()
            ->assertJsonPath('data.needs_supervisor', true)
            ->assertJsonPath('data.supervisor_on_roster', true);

        $item = collect($this->api('GET', '/api/v1/dashboard', $admin)->json('action_items'))->firstWhere('key', 'students_without_supervisor');
        $this->assertSame(1, $item['count']);
    }

    // ------------------------------------------------------------ website

    public function test_web_index_flags_each_student_and_keeps_the_full_list(): void
    {
        $this->fixture();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $response = $this->actingAs($coordinator)->get('/internship-assignment')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('InternshipAssignment/Index')
            ->has('students', 6)
            ->where('filters', ['needs_supervisor' => false]));

        $students = collect($response->viewData('page')['props']['students'])->keyBy('id');

        $expected = [
            'no_company' => [null, false],
            'no_supervisor' => [null, true],
            'off_roster' => [false, true],
            'on_roster' => [true, false],
            'inactive_supervisor' => [true, false],
            'demoted' => [true, true],
        ];

        foreach ($expected as $name => [$onRoster, $needs]) {
            $row = $students[$this->students[$name]->id];
            $this->assertSame($onRoster, $row['supervisor_on_roster'], "{$name}: supervisor_on_roster");
            $this->assertSame($needs, $row['needs_supervisor'], "{$name}: needs_supervisor");
            // Existing keys are untouched.
            $this->assertArrayHasKey('supervisor_id', $row);
            $this->assertArrayHasKey('company_id', $row);
        }
    }

    public function test_web_filter_prop_echoes_the_query_but_does_not_narrow_the_list(): void
    {
        $this->fixture();
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/internship-assignment?needs_supervisor=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('students', 6)
                ->where('filters.needs_supervisor', true));

        $this->actingAs($admin)->get('/internship-assignment?needs_supervisor=0')
            ->assertInertia(fn (Assert $page) => $page->where('filters.needs_supervisor', false));

        $this->actingAs($admin)->get('/internship-assignment?needs_supervisor[]=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.needs_supervisor', false));
    }

    public function test_saving_and_toggling_keep_the_filter_when_the_page_sends_it(): void
    {
        $this->fixture();
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $student = $this->students['no_company']->load('user');

        $payload = [
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => null,
            'supervisor_id' => null,
            'internship_status' => 'not_started',
        ];

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $payload + ['needs_supervisor' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('internship-assignment.index', ['needs_supervisor' => 1], absolute: false));

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $payload)
            ->assertRedirect(route('internship-assignment.index', absolute: false));

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}/toggle-status", ['needs_supervisor' => true])
            ->assertRedirect(route('internship-assignment.index', ['needs_supervisor' => 1], absolute: false));

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}/toggle-status", ['needs_supervisor' => 'nonsense'])
            ->assertRedirect(route('internship-assignment.index', absolute: false));
    }

    public function test_supervisors_and_students_still_cannot_open_internship_assignment(): void
    {
        $this->actingAs($this->user(User::ROLE_SUPERVISOR))->get('/internship-assignment?needs_supervisor=1')->assertForbidden();
        $this->actingAs($this->user(User::ROLE_STUDENT))->get('/internship-assignment?needs_supervisor=1')->assertForbidden();
    }

    public function test_coordinator_and_admin_dashboards_list_the_action_item(): void
    {
        $this->fixture();

        foreach ([[User::ROLE_COORDINATOR, '/dashboard'], [User::ROLE_ADMIN, '/admin-dashboard']] as [$roleId, $uri]) {
            $user = $this->user($roleId);

            $items = collect($this->actingAs($user)->get($uri)->assertOk()->viewData('page')['props']['actionItems'])->keyBy('key');

            $this->assertSame([
                'key' => 'students_without_supervisor',
                'label' => '3 student(s) need a supervisor',
                'count' => 3,
                'href' => route('internship-assignment.index', ['needs_supervisor' => 1]),
            ], $items['students_without_supervisor'], "role {$roleId}");

            $this->actingAs($user)->get($items['students_without_supervisor']['href'])
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('filters.needs_supervisor', true));
        }
    }

    public function test_the_action_item_is_absent_when_nobody_needs_a_supervisor_and_for_supervisors(): void
    {
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $company->supervisors()->attach($supervisor->id);
        Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);
        Student::factory()->create();

        $keys = collect($this->actingAs($this->user(User::ROLE_COORDINATOR))->get('/dashboard')->viewData('page')['props']['actionItems'])->pluck('key');
        $this->assertNotContains('students_without_supervisor', $keys);
        $this->assertContains('students_without_company', $keys);

        // Drift the roster: a supervisor never gets the item.
        $company->supervisors()->detach($supervisor->id);
        $keys = collect($this->actingAs($supervisor)->get('/dashboard')->viewData('page')['props']['actionItems'])->pluck('key');
        $this->assertNotContains('students_without_supervisor', $keys);
    }

    // ------------------------------------------------------------ API

    public function test_api_items_carry_the_flag(): void
    {
        $this->fixture();
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $rows = collect($this->api('GET', '/api/v1/assignments?per_page=50', $coordinator)->assertOk()->json('data'))->keyBy('id');

        $this->assertCount(6, $rows);
        foreach ($this->students as $name => $student) {
            $this->assertSame(in_array($student->id, $this->flagged(), true), $rows[$student->id]['needs_supervisor'], $name);
        }

        $this->api('GET', "/api/v1/assignments/{$this->students['off_roster']->id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('data.needs_supervisor', true)
            ->assertJsonPath('data.supervisor_on_roster', false);
    }

    public function test_api_needs_supervisor_filter_matches_the_flag(): void
    {
        $this->fixture();
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['1', 'true'] as $value) {
            $response = $this->api('GET', "/api/v1/assignments?needs_supervisor={$value}", $admin)->assertOk();
            $this->assertEqualsCanonicalizing($this->flagged(), collect($response->json('data'))->pluck('id')->all());
            $response->assertJsonPath('meta.total', 3);
        }

        foreach (['0', 'false', ''] as $value) {
            $this->api('GET', "/api/v1/assignments?needs_supervisor={$value}", $admin)->assertOk()->assertJsonPath('meta.total', 6);
        }

        // ANDed with the other filters; supervisor_id=none is unchanged.
        $this->api('GET', '/api/v1/assignments?needs_supervisor=1&supervisor_id=none', $admin)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->students['no_supervisor']->id);

        $this->assertEqualsCanonicalizing(
            [$this->students['no_company']->id, $this->students['no_supervisor']->id],
            collect($this->api('GET', '/api/v1/assignments?supervisor_id=none', $admin)->json('data'))->pluck('id')->all(),
        );
    }

    public function test_api_needs_supervisor_filter_rejects_junk(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        foreach (['yes', '2', 'none'] as $value) {
            $this->api('GET', "/api/v1/assignments?needs_supervisor={$value}", $admin)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['needs_supervisor' => 'The needs supervisor filter must be 1 or 0.']);
        }

        $this->api('GET', '/api/v1/assignments?needs_supervisor[]=1', $admin)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('needs_supervisor');
    }

    public function test_api_dashboard_action_item_targets_the_filtered_students_screen(): void
    {
        $this->fixture();

        foreach ([User::ROLE_COORDINATOR, User::ROLE_ADMIN] as $roleId) {
            $user = $this->user($roleId);

            $response = $this->api('GET', '/api/v1/dashboard', $user)->assertOk();
            $item = collect($response->json('action_items'))->firstWhere('key', 'students_without_supervisor');

            $this->assertSame([
                'key' => 'students_without_supervisor',
                'label' => '3 student(s) need a supervisor',
                'count' => 3,
                'target' => ['screen' => 'students', 'params' => ['needs_supervisor' => 1]],
            ], $item);
            $this->assertStringContainsString('"params":{"needs_supervisor":1}', $response->getContent());

            // Same rows as the list filter the app opens.
            $this->api('GET', '/api/v1/assignments?needs_supervisor=1', $user)->assertJsonPath('meta.total', $item['count']);
        }

        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $keys = collect($this->api('GET', '/api/v1/dashboard', $supervisor)->json('action_items'))->pluck('key');
        $this->assertNotContains('students_without_supervisor', $keys);
    }
}
