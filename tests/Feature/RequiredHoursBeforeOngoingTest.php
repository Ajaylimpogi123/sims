<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Marking an internship Ongoing needs required hours, and an ongoing
 * student's required hours can't be cleared (website + API). Also the
 * inactive-supervisor case of needs_supervisor and `supervisor_issue`.
 */
class RequiredHoursBeforeOngoingTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = 'Set the required hours before marking the internship Ongoing.';

    private const LOCKED = "Required hours can't be cleared while the internship is Ongoing.";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
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

    /**
     * Save the assignment through the website or the API.
     */
    private function save(string $channel, Student $student, array $overrides = []): TestResponse
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $payload = $this->payload($student, $overrides);

        return $channel === 'web'
            ? $this->actingAs($coordinator)->patch("/internship-assignment/{$student->id}", $payload)
            : $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $payload);
    }

    private function setHours(string $channel, Student $student, mixed $value): TestResponse
    {
        $admin = $this->user(User::ROLE_ADMIN);

        return $channel === 'web'
            ? $this->actingAs($admin)->patch("/attendance-monitoring/{$student->id}/required-hours", ['required_hours' => $value])
            : $this->api('PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", $admin, ['required_hours' => $value]);
    }

    public static function refusedStarts(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['not_started', 'completed'] as $status) {
                foreach (['null' => null, 'zero' => 0] as $label => $hours) {
                    $cases["{$channel} {$status} hours {$label}"] = [$channel, $status, $hours];
                }
            }
        }

        return $cases;
    }

    // ------------------------------------------------ assignment save

    #[DataProvider('refusedStarts')]
    public function test_marking_ongoing_without_required_hours_is_refused(string $channel, string $status, ?int $hours): void
    {
        $student = Student::factory()->create(['internship_status' => $status, 'required_hours' => $hours]);

        $response = $this->save($channel, $student, ['internship_status' => 'ongoing', 'course' => 'BSCS-NEW']);

        if ($channel === 'web') {
            $response->assertSessionHasErrors(['internship_status' => self::MISSING]);
        } else {
            $response->assertStatus(422)->assertExactJson([
                'message' => self::MISSING,
                'code' => 'required_hours_missing',
                'errors' => ['internship_status' => [self::MISSING]],
            ]);
        }

        $fresh = $student->fresh();
        $this->assertSame($status, $fresh->internship_status);
        $this->assertNotSame('BSCS-NEW', $fresh->course);
    }

    public static function channels(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    #[DataProvider('channels')]
    public function test_marking_ongoing_with_required_hours_is_allowed(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => 300]);

        $response = $this->save($channel, $student, ['internship_status' => 'ongoing']);

        $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk()->assertJsonPath('student.required_hours', 300);
        $this->assertSame('ongoing', $student->fresh()->internship_status);
    }

    #[DataProvider('channels')]
    public function test_an_already_ongoing_student_without_hours_can_still_be_saved(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => null]);

        $response = $this->save($channel, $student, ['course' => 'BSCS-NEW']);

        $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk();
        $this->assertSame('BSCS-NEW', $student->fresh()->course);
        $this->assertSame('ongoing', $student->fresh()->internship_status);
    }

    #[DataProvider('channels')]
    public function test_other_status_changes_do_not_need_required_hours(string $channel): void
    {
        $ongoing = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => null]);
        $notStarted = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => null]);

        $first = $this->save($channel, $ongoing, ['internship_status' => 'completed']);
        $second = $this->save($channel, $notStarted, ['internship_status' => 'completed']);

        foreach ([$first, $second] as $response) {
            $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk();
        }

        $this->assertSame('completed', $ongoing->fresh()->internship_status);
        $this->assertSame('completed', $notStarted->fresh()->internship_status);
    }

    public function test_required_hours_are_exposed_to_both_assignment_screens(): void
    {
        $set = Student::factory()->create(['required_hours' => 300]);
        $unset = Student::factory()->create(['required_hours' => null]);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->api('GET', "/api/v1/assignments/{$set->id}", $admin)->assertJsonPath('data.required_hours', 300);
        $this->api('GET', "/api/v1/assignments/{$unset->id}", $admin)->assertJsonPath('data.required_hours', null);

        $rows = collect($this->actingAs($admin)->get('/internship-assignment')->assertOk()
            ->viewData('page')['props']['students'])->keyBy('id');

        $this->assertSame(300, $rows[$set->id]['required_hours']);
        $this->assertNull($rows[$unset->id]['required_hours']);
    }

    // ------------------------------------------------- required hours

    public static function clearingValues(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['null' => null, 'empty' => '', 'zero' => 0, 'zero string' => '0', 'minus zero' => '-0', 'plus zero' => '+0'] as $label => $value) {
                $cases["{$channel} {$label}"] = [$channel, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('clearingValues')]
    public function test_an_ongoing_students_required_hours_cannot_be_cleared(string $channel, mixed $value): void
    {
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => 486]);

        $response = $this->setHours($channel, $student, $value);

        if ($channel === 'web') {
            $response->assertSessionHasErrors(['required_hours' => self::LOCKED]);
        } else {
            $response->assertStatus(422)->assertExactJson([
                'message' => self::LOCKED,
                'errors' => ['required_hours' => [self::LOCKED]],
            ]);
        }

        $this->assertSame(486, $student->fresh()->required_hours);
    }

    #[DataProvider('channels')]
    public function test_an_ongoing_students_required_hours_can_be_changed(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => null]);

        $response = $this->setHours($channel, $student, 600);

        $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk()->assertJsonPath('student.required_hours', 600);
        $this->assertSame(600, $student->fresh()->required_hours);
    }

    public static function clearableStatuses(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['not_started', 'completed'] as $status) {
                $cases["{$channel} {$status}"] = [$channel, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('clearableStatuses')]
    public function test_required_hours_can_be_cleared_when_not_ongoing(string $channel, string $status): void
    {
        $student = Student::factory()->create(['internship_status' => $status, 'required_hours' => 486]);

        $response = $this->setHours($channel, $student, '');

        $channel === 'web' ? $response->assertSessionHasNoErrors() : $response->assertOk()->assertJsonPath('student.required_hours', null);
        $this->assertNull($student->fresh()->required_hours);
    }

    // ------------------------------------------- inactive supervisors

    public function test_supervisor_issue_follows_the_precedence_and_matches_the_scope(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        // Inactive AND demoted: not_a_supervisor wins.
        $demotedInactive = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $inactive = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $active = $this->user(User::ROLE_SUPERVISOR);

        // Each student at a company whose Company Supervisor is theirs.
        $placed = function (?User $supervisor): Student {
            $company = Company::factory()->create(['slots' => 10, 'supervisor_id' => $supervisor?->id]);

            return Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor?->id]);
        };

        $students = [
            'not_a_supervisor' => $placed($demotedInactive),
            'inactive' => $placed($inactive),
            'no_supervisor' => $placed(null),
        ];
        $fine = $placed($active);
        // No company: never flagged, even with an inactive supervisor.
        $unplaced = Student::factory()->create(['supervisor_id' => $inactive->id]);
        $demotedInactive->update(['role_id' => User::ROLE_COORDINATOR]);

        $rows = collect($this->api('GET', '/api/v1/assignments?per_page=50', $admin)->assertOk()->json('data'))->keyBy('id');

        foreach ($students as $issue => $student) {
            $this->assertTrue($rows[$student->id]['needs_supervisor'], $issue);
            $this->assertSame($issue, $rows[$student->id]['supervisor_issue'], $issue);
        }

        foreach ([$fine, $unplaced] as $student) {
            $this->assertFalse($rows[$student->id]['needs_supervisor']);
            $this->assertNull($rows[$student->id]['supervisor_issue']);
        }

        $this->assertSame('inactive', $rows[$students['inactive']->id]['supervisor']['status']);

        $flagged = collect($students)->pluck('id')->sort()->values()->all();
        $this->assertSame($flagged, Student::query()->needsSupervisor()->orderBy('id')->pluck('id')->all());

        $filtered = collect($this->api('GET', '/api/v1/assignments?needs_supervisor=1&per_page=50', $admin)->json('data'))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame($flagged, $filtered);

        $item = collect($this->api('GET', '/api/v1/dashboard', $admin)->json('action_items'))->firstWhere('key', 'students_without_supervisor');
        $this->assertSame(3, $item['count']);

        $this->actingAs($admin)->get('/internship-assignment')
            ->assertInertia(fn (Assert $page) => $page
                ->where('students', fn ($webRows) => collect($webRows)->keyBy('id')
                    ->every(fn ($row, $id) => $row['supervisor_issue'] === $rows[$id]['supervisor_issue']
                        && $row['needs_supervisor'] === $rows[$id]['needs_supervisor']))
                ->where('students', fn ($webRows) => collect($webRows)->firstWhere('id', $students['inactive']->id)['supervisor']['status'] === 'inactive'));
    }

    public function test_reactivating_the_supervisor_clears_the_flag(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $company = Company::factory()->create(['slots' => 10, 'supervisor_id' => $supervisor->id]);
        $student = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->assertSame([$student->id], Student::query()->needsSupervisor()->pluck('id')->all());

        $supervisor->update(['status' => 'active']);

        $this->assertSame([], Student::query()->needsSupervisor()->pluck('id')->all());
    }
}
