<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use App\Support\AppScreen;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\V1\Concerns\InteractsWithDashboardApi;
use Tests\TestCase;

class DashboardApiTest extends TestCase
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
    public static function roles(): array
    {
        return [
            'student' => [1],
            'coordinator' => [2],
            'supervisor' => [3],
            'admin' => [4],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function staffAndSupervisorRoles(): array
    {
        return [
            'coordinator' => [2],
            'supervisor' => [3],
            'admin' => [4],
        ];
    }

    // ---------------------------------------------------------------- auth

    public function test_dashboard_requires_a_token(): void
    {
        $this->getApi('/api/v1/dashboard', null)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    #[DataProvider('roles')]
    public function test_every_role_gets_the_same_six_top_level_keys(int $roleId): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/dashboard', $this->fixtureUser($fixture, $roleId))->assertOk();

        $this->assertSame(
            ['role_id', 'student', 'kpis', 'supervised_students', 'action_items', 'recent_activity'],
            array_keys($response->json()),
        );
        $response->assertJsonPath('role_id', $roleId);
    }

    // ---------------------------------------------------------------- student

    public function test_student_gets_their_own_summary(): void
    {
        $fixture = $this->dashboardFixture();
        $student = $fixture['studentA1'];
        $todayAttendance = Attendance::where('student_id', $student->id)->where('date', today()->toDateString())->first();
        $reports = InternshipReport::where('student_id', $student->id)->orderByDesc('period_start')->get();

        $this->getApi('/api/v1/dashboard', $student->user)
            ->assertOk()
            ->assertExactJson([
                'role_id' => 1,
                'student' => [
                    'internship' => [
                        'status' => 'ongoing',
                        'company' => ['id' => $fixture['companyX']->id, 'name' => 'Xylo Corp'],
                        'supervisor' => ['id' => $fixture['supervisorA']->id, 'name' => $fixture['supervisorA']->name],
                    ],
                    'hours' => [
                        'rendered' => 91.5,
                        'required' => 100,
                        'remaining' => 8.5,
                        'progress_percent' => 92,
                    ],
                    'today_attendance' => [
                        'id' => $todayAttendance->id,
                        'date' => today()->format('Y-m-d'),
                        'time_in' => today()->format('Y-m-d').'T08:05:00+08:00',
                        'time_in_status' => 'approved',
                        'time_out' => today()->format('Y-m-d').'T17:00:00+08:00',
                        'time_out_status' => 'pending',
                    ],
                    'reports' => ['pending' => 1, 'reviewed' => 1],
                    'recent_reports' => $reports->map(fn (InternshipReport $report) => [
                        'id' => $report->id,
                        'type' => $report->type,
                        'period_start' => $report->period_start->format('Y-m-d'),
                        'period_end' => $report->period_end->format('Y-m-d'),
                        'status' => $report->status,
                    ])->all(),
                ],
                'kpis' => null,
                'supervised_students' => null,
                'action_items' => [],
                'recent_activity' => [],
            ]);
    }

    public function test_student_sees_only_their_own_numbers(): void
    {
        $fixture = $this->dashboardFixture();

        // B1 has a pending report and a pending attendance today; A1's
        // summary must not count them.
        $this->getApi('/api/v1/dashboard', $fixture['studentB1']->user)
            ->assertOk()
            ->assertJsonPath('student.hours.rendered', 0)
            ->assertJsonPath('student.reports', ['pending' => 1, 'reviewed' => 0])
            ->assertJsonPath('student.today_attendance.time_in_status', 'pending')
            ->assertJsonPath('student.today_attendance.time_out', null)
            ->assertJsonPath('student.internship.company.name', 'Yarrow Inc');
    }

    public function test_student_hours_are_rounded_to_two_decimals_and_unset_targets_are_null(): void
    {
        $student = Student::factory()->create(['required_hours' => null, 'company_id' => null, 'supervisor_id' => null]);
        foreach ([1.11, 2.22, 3.33] as $i => $hours) {
            Attendance::factory()->create([
                'student_id' => $student->id,
                'date' => today()->subDays($i + 1)->toDateString(),
                'rendered_hours' => $hours,
            ]);
        }

        $response = $this->getApi('/api/v1/dashboard', $student->user)->assertOk();

        $this->assertSame(6.66, $response->json('student.hours.rendered'));
        $response
            ->assertJsonPath('student.hours.required', null)
            ->assertJsonPath('student.hours.remaining', null)
            ->assertJsonPath('student.hours.progress_percent', null)
            ->assertJsonPath('student.internship.company', null)
            ->assertJsonPath('student.internship.supervisor', null)
            ->assertJsonPath('student.today_attendance', null)
            ->assertJsonPath('student.recent_reports', []);
    }

    public function test_remaining_hours_never_go_negative_and_progress_is_capped(): void
    {
        $student = Student::factory()->create(['required_hours' => 10]);
        Attendance::factory()->create(['student_id' => $student->id, 'rendered_hours' => 12.5]);

        $this->getApi('/api/v1/dashboard', $student->user)
            ->assertOk()
            ->assertJsonPath('student.hours.remaining', 0)
            ->assertJsonPath('student.hours.progress_percent', 100);
    }

    public function test_student_without_a_profile_gets_null_student(): void
    {
        $user = $this->activeUser(1);

        $this->getApi('/api/v1/dashboard', $user)
            ->assertOk()
            ->assertExactJson([
                'role_id' => 1,
                'student' => null,
                'kpis' => null,
                'supervised_students' => null,
                'action_items' => [],
                'recent_activity' => [],
            ]);
    }

    // ---------------------------------------------------------------- supervisor

    public function test_supervisor_is_scoped_to_their_own_students(): void
    {
        $fixture = $this->dashboardFixture();
        $supervisor = $fixture['supervisorA'];

        $response = $this->getApi('/api/v1/dashboard', $supervisor)->assertOk();

        $response
            ->assertJsonPath('student', null)
            ->assertJsonPath('kpis.assigned_students', ['value' => 2, 'trend' => null])
            ->assertJsonPath('kpis.active_internships.value', 1)
            ->assertJsonPath('kpis.todays_attendance.value', 1)
            ->assertJsonPath('kpis.pending_attendance_approvals.value', 1)
            ->assertJsonPath('kpis.reports_awaiting_review.value', 1)
            ->assertJsonPath('kpis.pending_evaluations.value', 1)
            ->assertJsonPath('kpis.completed_evaluations.value', 0)
            ->assertJsonPath('kpis.students_requiring_attention.value', 1);

        $this->assertEqualsCanonicalizing(
            [$fixture['studentA1']->id, $fixture['studentA2']->id],
            collect($response->json('supervised_students'))->pluck('id')->all(),
        );

        $a1 = collect($response->json('supervised_students'))->firstWhere('id', $fixture['studentA1']->id);
        $this->assertSame([
            'id' => $fixture['studentA1']->id,
            'name' => $fixture['studentA1']->user->name,
            'internship_status' => 'ongoing',
            'rendered_hours' => 91.5,
            'required_hours' => 100,
            'progress_percent' => 92,
        ], $a1);

        // Own inbox + own students' notifications only, never B1's.
        $activity = collect($response->json('recent_activity'));
        $this->assertEqualsCanonicalizing(
            [$fixture['notifications']['supervisorA']->id, $fixture['notifications']['studentA1']->id],
            $activity->pluck('id')->all(),
        );
        $this->assertTrue($activity->firstWhere('id', $fixture['notifications']['supervisorA']->id)['is_own']);
        $this->assertFalse($activity->firstWhere('id', $fixture['notifications']['studentA1']->id)['is_own']);

        // A student's report_reviewed opens report-reviews for the supervisor.
        $this->assertSame(
            ['screen' => 'report-reviews', 'params' => ['report_id' => 2]],
            $activity->firstWhere('id', $fixture['notifications']['studentA1']->id)['target'],
        );
    }

    public function test_supervisor_with_no_students_gets_zeros_and_empty_lists(): void
    {
        $this->dashboardFixture();
        $supervisor = $this->activeUser(3);

        $response = $this->getApi('/api/v1/dashboard', $supervisor)->assertOk();

        foreach ($response->json('kpis') as $key => $kpi) {
            $this->assertSame(0, $kpi['value'], $key);
        }

        $response
            ->assertJsonPath('supervised_students', [])
            ->assertJsonPath('action_items', [])
            ->assertJsonPath('recent_activity', []);
    }

    // ---------------------------------------------------------------- staff

    public function test_coordinator_gets_system_wide_kpis(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/dashboard', $fixture['coordinator'])->assertOk();

        $this->assertSame([
            'total_students', 'active_internship_assignments', 'companies', 'active_supervisors',
            'students_currently_on_internship', 'students_without_company', 'pending_approvals',
            'attendance_summary', 'internship_completion_progress', 'reports_awaiting_review',
            'students_requiring_attention',
        ], array_keys($response->json('kpis')));

        $response
            ->assertJsonPath('student', null)
            ->assertJsonPath('supervised_students', null)
            ->assertJsonPath('kpis.total_students.value', 4)
            ->assertJsonPath('kpis.students_without_company.value', 1)
            ->assertJsonPath('kpis.pending_approvals.value', 2)
            ->assertJsonPath('kpis.reports_awaiting_review.value', 2)
            ->assertJsonPath('kpis.students_requiring_attention.value', 2)
            ->assertJsonPath('kpis.attendance_summary', ['present' => 2, 'rejected' => 6]);

        $this->assertSame(
            ['students_without_company', 'reports_awaiting_review', 'evaluations_awaiting_completion', 'nearing_completion', 'frequent_rejections'],
            collect($response->json('action_items'))->pluck('key')->all(),
            'Coordinators never get the approvals action item.',
        );
    }

    public function test_admin_gets_system_wide_kpis_and_the_approvals_item(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/dashboard', $fixture['admin'])->assertOk();

        $this->assertSame([
            'total_students', 'total_companies', 'total_supervisors', 'active_internship_assignments',
            'completed_internships', 'pending_approvals', 'attendance_summary',
            'students_currently_on_internship', 'students_nearing_completion',
            'students_with_attendance_issues', 'pending_supervisor_evaluations',
        ], array_keys($response->json('kpis')));

        $response
            ->assertJsonPath('kpis.total_students.value', 4)
            ->assertJsonPath('kpis.total_supervisors.value', 2)
            ->assertJsonPath('kpis.completed_internships.value', 1)
            ->assertJsonPath('kpis.students_nearing_completion.value', 1)
            ->assertJsonPath('action_items.0', [
                'key' => 'pending_approvals',
                'label' => '2 attendance approval(s) awaiting action',
                'count' => 2,
                'target' => ['screen' => 'approvals', 'params' => []],
            ]);

        // Own inbox only; other users' notifications aren't in it.
        $this->assertSame(
            [$fixture['notifications']['admin']->id],
            collect($response->json('recent_activity'))->pluck('id')->all(),
        );
        $response->assertJsonPath('recent_activity.0.is_own', true);
    }

    public function test_action_item_params_encode_as_a_json_object(): void
    {
        $fixture = $this->dashboardFixture();

        $response = $this->getApi('/api/v1/dashboard', $fixture['admin'])->assertOk();

        $this->assertStringContainsString('"params":{}', $response->getContent());
        $this->assertStringNotContainsString('"params":[]', $response->getContent());
    }

    #[DataProvider('staffAndSupervisorRoles')]
    public function test_every_action_item_target_is_a_page_the_role_can_open(int $roleId): void
    {
        $fixture = $this->dashboardFixture();
        $user = $this->fixtureUser($fixture, $roleId);

        $items = $this->getApi('/api/v1/dashboard', $user)->assertOk()->json('action_items');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $screen = $item['target']['screen'];
            $this->assertArrayHasKey($screen, AppScreen::ROUTES);

            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->get(AppScreen::url($screen))->assertOk();
        }
    }

    // ---------------------------------------------------------------- parity

    #[DataProvider('staffAndSupervisorRoles')]
    public function test_staff_and_supervisor_numbers_equal_the_website(int $roleId): void
    {
        $fixture = $this->dashboardFixture();
        $user = $this->fixtureUser($fixture, $roleId);

        $web = $this->webDashboardProps($user);
        $api = $this->getApi('/api/v1/dashboard', $user)->assertOk()->json();

        $this->assertSame(
            collect($web['kpis'])->mapWithKeys(fn ($kpi, $key) => [Str::snake($key) => $kpi])->all(),
            $api['kpis'],
        );

        $this->assertSame(
            collect($web['actionItems'])->map(fn ($item) => [$item['key'], $item['label'], $item['count'], $item['href']])->all(),
            collect($api['action_items'])->map(fn ($item) => [$item['key'], $item['label'], $item['count'], AppScreen::url($item['target']['screen'])])->all(),
        );

        $this->assertSame(
            collect($web['recentActivity'])->pluck('id')->all(),
            collect($api['recent_activity'])->pluck('id')->all(),
        );

        if ($roleId === 3) {
            $this->assertEquals(
                collect($web['supervisedStudents'])->map(fn ($s) => [$s['id'], $s['name'], $s['internship_status'], round($s['rendered_hours'], 2), $s['required_hours']])->all(),
                collect($api['supervised_students'])->map(fn ($s) => [$s['id'], $s['name'], $s['internship_status'], $s['rendered_hours'], $s['required_hours']])->all(),
            );
        }
    }

    public function test_student_numbers_equal_the_website(): void
    {
        $fixture = $this->dashboardFixture();
        $user = $fixture['studentA1']->user;

        $web = $this->webDashboardProps($user);
        $api = $this->getApi('/api/v1/dashboard', $user)->assertOk()->json('student');

        $this->assertSame($web['student']['internship_status'], $api['internship']['status']);
        $this->assertSame($web['student']['company_name'], $api['internship']['company']['name']);
        $this->assertSame($web['student']['supervisor_name'], $api['internship']['supervisor']['name']);
        $this->assertEquals(round($web['hours']['rendered'], 2), $api['hours']['rendered']);
        $this->assertSame($web['hours']['required'], $api['hours']['required']);
        $this->assertEquals(round($web['hours']['remaining'], 2), $api['hours']['remaining']);
        $this->assertSame($web['todayAttendance']['id'], $api['today_attendance']['id']);
        $this->assertSame($web['pendingReportsCount'], $api['reports']['pending']);
        $this->assertSame(
            collect($web['recentReports'])->pluck('id')->all(),
            collect($api['recent_reports'])->pluck('id')->all(),
        );
    }

    public function test_inactive_account_is_refused(): void
    {
        $user = User::factory()->create(['role_id' => 3, 'status' => 'inactive']);

        $this->getApi('/api/v1/dashboard', $user)
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }
}
