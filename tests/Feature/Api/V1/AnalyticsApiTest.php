<?php

namespace Tests\Feature\Api\V1;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\Concerns\InteractsWithDashboardApi;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use InteractsWithDashboardApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function staffRoles(): array
    {
        return [
            'coordinator' => [2],
            'admin' => [4],
        ];
    }

    /**
     * The website's analytics prop, converted to the API's shape, so the
     * two can be compared value for value.
     */
    private function expectedFromWeb(array $web): array
    {
        $series = fn (array $rows, string $key, string $value) => array_map(
            fn (array $row) => ['key' => $row[$key], 'value' => $row[$value]],
            $rows,
        );

        return [
            'filters' => $web['filters'],
            'status_breakdown' => $series($web['internship']['statusBreakdown'], 'status', 'total'),
            'students_by_company' => $series($web['internship']['studentsByCompany'], 'company', 'total'),
            'completion_progress' => $series($web['internship']['completionProgressBuckets'], 'bucket', 'total'),
            'outcomes' => $series($web['attendance']['outcomes'], 'outcome', 'total'),
            'trend' => $web['attendance']['trend'],
            'frequent_rejections' => $web['attendance']['frequentRejections'],
            'completion' => $series($web['evaluation']['completion'], 'status', 'total'),
            'by_category' => $series($web['evaluation']['byCategory'], 'category', 'average_rating'),
            'funnel' => $series($web['reports']['funnel'], 'status', 'total'),
            'submission_trend' => array_map(fn ($row) => ['date' => $row['date'], 'value' => $row['total']], $web['reports']['submissionTrend']),
        ];
    }

    private function actualFromApi(array $api): array
    {
        $series = fn (array $rows) => array_map(fn (array $row) => ['key' => $row['key'], 'value' => $row['value']], $rows);

        return [
            'filters' => $api['filters'],
            'status_breakdown' => $series($api['internship']['status_breakdown']),
            'students_by_company' => $series($api['internship']['students_by_company']),
            'completion_progress' => $series($api['internship']['completion_progress']),
            'outcomes' => $series($api['attendance']['outcomes']),
            'trend' => $api['attendance']['trend'],
            'frequent_rejections' => $api['attendance']['frequent_rejections'],
            'completion' => $series($api['evaluation']['completion']),
            'by_category' => $series($api['evaluation']['by_category']),
            'funnel' => $series($api['reports']['funnel']),
            'submission_trend' => $api['reports']['submission_trend'],
        ];
    }

    // ---------------------------------------------------------------- access

    public function test_analytics_endpoints_require_a_token(): void
    {
        $this->getApi('/api/v1/analytics', null)->assertUnauthorized();
        $this->getApi('/api/v1/analytics/filter-options', null)->assertUnauthorized();
    }

    public function test_students_cannot_use_analytics(): void
    {
        $fixture = $this->dashboardFixture();
        $student = $fixture['studentA1']->user;

        $this->getApi('/api/v1/analytics', $student)->assertForbidden()->assertExactJson(['message' => 'Unauthorized access']);
        $this->getApi('/api/v1/analytics/filter-options', $student)->assertForbidden();
    }

    // ---------------------------------------------------------------- parity

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rolesAndFilters(): array
    {
        $cases = [];

        foreach (['coordinator' => 2, 'supervisor' => 3, 'admin' => 4] as $name => $roleId) {
            $cases["{$name} unfiltered"] = [$roleId, 'none'];
            $cases["{$name} date range"] = [$roleId, 'dates'];
            $cases["{$name} company"] = [$roleId, 'company'];
            $cases["{$name} student"] = [$roleId, 'student'];
        }

        return $cases;
    }

    #[DataProvider('rolesAndFilters')]
    public function test_analytics_equal_the_website_for_the_same_filters(int $roleId, string $filterSet): void
    {
        $fixture = $this->dashboardFixture();
        $user = $this->fixtureUser($fixture, $roleId);

        $query = match ($filterSet) {
            'none' => [],
            'dates' => ['date_from' => today()->subDays(5)->toDateString(), 'date_to' => today()->toDateString()],
            'company' => ['company_id' => $fixture['companyY']->id],
            'student' => ['student_id' => $fixture['studentA2']->id],
        };

        $web = $this->webAnalytics($user, $query);
        $api = $this->getApi('/api/v1/analytics', $user, $query)->assertOk()->json();

        $this->assertEquals($this->expectedFromWeb($web), $this->actualFromApi($api));
    }

    public function test_series_carry_keys_labels_and_values(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/analytics', $fixture['admin'])->assertOk();

        $this->assertSame(['filters', 'internship', 'attendance', 'evaluation', 'reports'], array_keys($response->json()));

        $statuses = collect($response->json('internship.status_breakdown'))->keyBy('key');
        $this->assertSame(['key' => 'ongoing', 'label' => 'Ongoing', 'value' => 2], $statuses['ongoing']);
        $this->assertSame(['key' => 'not_started', 'label' => 'Not Started', 'value' => 1], $statuses['not_started']);

        $response
            ->assertJsonPath('internship.completion_progress.0', ['key' => '0-25%', 'label' => '0-25%', 'value' => 2])
            ->assertJsonCount(5, 'internship.completion_progress')
            ->assertJsonPath('attendance.outcomes', [
                ['key' => 'present', 'label' => 'Present', 'value' => 2],
                ['key' => 'rejected', 'label' => 'Rejected', 'value' => 6],
            ])
            ->assertJsonPath('evaluation.by_category', [
                ['key' => 'Communication', 'label' => 'Communication', 'value' => 4],
            ])
            ->assertJsonPath('filters', [
                'date_from' => null, 'date_to' => null, 'company_id' => null, 'supervisor_id' => null, 'student_id' => null,
            ]);

        $evaluations = collect($response->json('evaluation.completion'))->keyBy('key');
        $this->assertSame('Draft', $evaluations['draft']['label']);
        $this->assertSame('Submitted', $evaluations['submitted']['label']);

        $funnel = collect($response->json('reports.funnel'))->keyBy('key');
        $this->assertSame(['key' => 'pending', 'label' => 'Pending', 'value' => 2], $funnel['pending']);
        $this->assertSame(['key' => 'reviewed', 'label' => 'Reviewed', 'value' => 1], $funnel['reviewed']);

        $trend = collect($response->json('attendance.trend'))->keyBy('date');
        $this->assertSame(['date' => today()->toDateString(), 'present' => 1, 'rejected' => 0], $trend[today()->toDateString()]);
    }

    // ---------------------------------------------------------------- scoping

    public function test_supervisor_sees_only_their_own_students(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/analytics', $fixture['supervisorA'])->assertOk();

        $this->assertSame(2, collect($response->json('internship.status_breakdown'))->sum('value'));
        $this->assertSame(
            [$fixture['studentA2']->id],
            collect($response->json('attendance.frequent_rejections'))->pluck('student_id')->all(),
        );
        $this->assertSame(2, collect($response->json('reports.funnel'))->sum('value'));
        $this->assertSame(
            [['key' => 'draft', 'label' => 'Draft', 'value' => 1]],
            $response->json('evaluation.completion'),
        );
        $response->assertJsonPath('evaluation.by_category', []);
        $response->assertJsonPath('filters.supervisor_id', $fixture['supervisorA']->id);
    }

    public function test_supervisor_cannot_widen_scope_with_another_supervisor_id(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/analytics', $fixture['supervisorA'], [
            'supervisor_id' => $fixture['supervisorB']->id,
        ])->assertOk();

        $response->assertJsonPath('filters.supervisor_id', $fixture['supervisorA']->id);
        $this->assertSame(2, collect($response->json('internship.status_breakdown'))->sum('value'));
    }

    public function test_supervisor_gets_403_for_a_student_or_company_outside_their_scope(): void
    {
        $fixture = $this->dashboardFixture();
        $supervisor = $fixture['supervisorA'];

        $this->getApi('/api/v1/analytics', $supervisor, ['student_id' => $fixture['studentB1']->id])->assertForbidden();
        $this->getApi('/api/v1/analytics', $supervisor, ['student_id' => $fixture['unassigned']->id])->assertForbidden();

        $otherCompany = \App\Models\Company::factory()->create();
        $this->getApi('/api/v1/analytics', $supervisor, ['company_id' => $otherCompany->id])->assertForbidden();

        // A company shared with supervisor B's student is allowed, but only
        // A's own student there is counted.
        $response = $this->getApi('/api/v1/analytics', $supervisor, ['company_id' => $fixture['companyY']->id])->assertOk();
        $this->assertSame(1, collect($response->json('internship.status_breakdown'))->sum('value'));
    }

    #[DataProvider('staffRoles')]
    public function test_staff_are_system_wide_and_can_filter_by_supervisor(int $roleId): void
    {
        $fixture = $this->dashboardFixture();
        $user = $this->fixtureUser($fixture, $roleId);

        $all = $this->getApi('/api/v1/analytics', $user)->assertOk();
        $this->assertSame(4, collect($all->json('internship.status_breakdown'))->sum('value'));

        $onlyB = $this->getApi('/api/v1/analytics', $user, ['supervisor_id' => $fixture['supervisorB']->id])->assertOk();
        $onlyB->assertJsonPath('filters.supervisor_id', $fixture['supervisorB']->id);
        $this->assertSame(1, collect($onlyB->json('internship.status_breakdown'))->sum('value'));
        $this->assertSame(
            [$fixture['studentB1']->id],
            collect($onlyB->json('attendance.frequent_rejections'))->pluck('student_id')->all(),
        );
    }

    // ---------------------------------------------------------------- validation

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'date_from not a date' => [['date_from' => 'abc'], 'date_from'],
            'date_from impossible date' => [['date_from' => '2026-02-30'], 'date_from'],
            'date_from wrong format' => [['date_from' => '10/03/2026'], 'date_from'],
            'date_from with time' => [['date_from' => '2026-10-03 10:00:00'], 'date_from'],
            'date_from array' => [['date_from' => ['2026-10-03']], 'date_from'],
            'date_to before date_from' => [['date_from' => '2026-10-03', 'date_to' => '2026-10-01'], 'date_to'],
            'company_id not an integer' => [['company_id' => 'abc'], 'company_id'],
            'company_id zero' => [['company_id' => 0], 'company_id'],
            'company_id negative' => [['company_id' => -5], 'company_id'],
            'company_id array' => [['company_id' => [1]], 'company_id'],
            'company_id overflow' => [['company_id' => '99999999999999999999999'], 'company_id'],
            'company_id unknown' => [['company_id' => 999999], 'company_id'],
            'supervisor_id decimal' => [['supervisor_id' => '1.5'], 'supervisor_id'],
            'supervisor_id unknown' => [['supervisor_id' => 999999], 'supervisor_id'],
            'student_id unknown' => [['student_id' => 999999], 'student_id'],
            'student_id text' => [['student_id' => 'x'], 'student_id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_422_not_500(array $query, string $field): void
    {
        $fixture = $this->dashboardFixture();

        $this->getApi('/api/v1/analytics', $fixture['admin'], $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_empty_filters_are_ignored(): void
    {
        $fixture = $this->dashboardFixture();

        $this->getApi('/api/v1/analytics', $fixture['admin'], [
            'date_from' => '', 'date_to' => '', 'company_id' => '', 'supervisor_id' => '', 'student_id' => '',
        ])->assertOk()->assertJsonPath('filters.company_id', null);
    }

    public function test_analytics_on_an_empty_database_returns_empty_series(): void
    {
        $admin = $this->activeUser(4);

        $this->getApi('/api/v1/analytics', $admin)
            ->assertOk()
            ->assertJsonPath('internship.status_breakdown', [])
            ->assertJsonPath('attendance.trend', [])
            ->assertJsonPath('attendance.outcomes.0.value', 0)
            ->assertJsonCount(5, 'internship.completion_progress');
    }

    // ---------------------------------------------------------------- filter options

    public function test_filter_options_are_scoped_for_a_supervisor(): void
    {
        $fixture = $this->dashboardFixture();
        $supervisor = $fixture['supervisorA'];

        $response = $this->getApi('/api/v1/analytics/filter-options', $supervisor)->assertOk();

        $this->assertSame(['companies', 'supervisors', 'students'], array_keys($response->json()));
        $response->assertJsonPath('supervisors', [['id' => $supervisor->id, 'name' => $supervisor->name]]);
        $this->assertEqualsCanonicalizing(
            [$fixture['studentA1']->id, $fixture['studentA2']->id],
            collect($response->json('students'))->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$fixture['companyX']->id, $fixture['companyY']->id],
            collect($response->json('companies'))->pluck('id')->all(),
        );

        $a1 = collect($response->json('students'))->firstWhere('id', $fixture['studentA1']->id);
        $this->assertSame([
            'id' => $fixture['studentA1']->id,
            'name' => $fixture['studentA1']->user->name,
            'student_number' => $fixture['studentA1']->student_number,
        ], $a1);
    }

    public function test_filter_options_are_system_wide_for_staff(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/analytics/filter-options', $fixture['coordinator'])->assertOk();

        $this->assertCount(4, $response->json('students'));
        $this->assertEqualsCanonicalizing(
            [$fixture['supervisorA']->id, $fixture['supervisorB']->id],
            collect($response->json('supervisors'))->pluck('id')->all(),
        );
        $response->assertJsonPath('companies.0', ['id' => $fixture['companyX']->id, 'name' => 'Xylo Corp']);
    }
}
