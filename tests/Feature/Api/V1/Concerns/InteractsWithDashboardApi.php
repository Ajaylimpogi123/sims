<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the Module 5 API tests: call the API with a token, read the
 * website dashboard's Inertia props for the same user, and a fixture with
 * two supervisors whose students must never leak into each other's numbers.
 */
trait InteractsWithDashboardApi
{
    private function activeUser(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function getApi(string $uri, ?User $user, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars($headers));
    }

    /**
     * The website dashboard's props for this user (non-deferred props).
     */
    private function webDashboardProps(User $user): array
    {
        $this->app['auth']->forgetGuards();

        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->app['auth']->forgetGuards();

        return $response->viewData('page')['props'];
    }

    /**
     * The website's deferred `analytics` prop for this user and filters.
     */
    private function webAnalytics(User $user, array $query = []): array
    {
        $this->app['auth']->forgetGuards();

        $uri = '/dashboard'.($query ? '?'.http_build_query($query) : '');

        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analytics',
                'X-Inertia-Partial-Component' => 'Dashboard/Index',
            ])
            ->get($uri)
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $response->viewData('page')['props']['analytics'];
    }

    /**
     * Two supervisors (A, B), each with students at different companies,
     * attendance (pending / approved / rejected), reports, evaluations and
     * notifications, plus one unassigned student.
     *
     * @return array<string, mixed>
     */
    private function dashboardFixture(): array
    {
        $supervisorA = $this->activeUser(3);
        $supervisorB = $this->activeUser(3);
        $coordinator = $this->activeUser(2);
        $admin = $this->activeUser(4);

        $companyX = Company::factory()->create(['company_name' => 'Xylo Corp', 'status' => 'active']);
        $companyY = Company::factory()->create(['company_name' => 'Yarrow Inc', 'status' => 'active']);

        $studentA1 = Student::factory()->create([
            'supervisor_id' => $supervisorA->id,
            'company_id' => $companyX->id,
            'internship_status' => 'ongoing',
            'required_hours' => 100,
        ]);
        $studentA2 = Student::factory()->create([
            'supervisor_id' => $supervisorA->id,
            'company_id' => $companyY->id,
            'internship_status' => 'completed',
            'required_hours' => 200,
        ]);
        $studentB1 = Student::factory()->create([
            'supervisor_id' => $supervisorB->id,
            'company_id' => $companyY->id,
            'internship_status' => 'ongoing',
            'required_hours' => 100,
        ]);
        $unassigned = Student::factory()->create(['required_hours' => null]);

        // A1: today pending time-out, plus enough approved hours to be
        // nearing completion (91.5 of 100).
        Attendance::factory()->create([
            'student_id' => $studentA1->id,
            'date' => today()->toDateString(),
            'time_in' => '08:05:00',
            'time_out' => '17:00:00',
            'time_in_status' => 'approved',
            'time_out_status' => 'pending',
            'rendered_hours' => 8.25,
        ]);
        Attendance::factory()->create([
            'student_id' => $studentA1->id,
            'date' => today()->subDays(3)->toDateString(),
            'rendered_hours' => 83.25,
        ]);

        // A2 and B1: three rejected legs each (frequent rejections).
        foreach ([$studentA2, $studentB1] as $student) {
            foreach (range(4, 6) as $daysAgo) {
                Attendance::factory()->create([
                    'student_id' => $student->id,
                    'date' => today()->subDays($daysAgo)->toDateString(),
                    'time_in_status' => 'rejected',
                    'rendered_hours' => 0,
                ]);
            }
        }

        Attendance::factory()->create([
            'student_id' => $studentB1->id,
            'date' => today()->toDateString(),
            'time_in_status' => 'pending',
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ]);

        InternshipReport::factory()->create(['student_id' => $studentA1->id, 'status' => 'pending', 'period_start' => today()->subDays(2), 'period_end' => today()->subDays(2)]);
        InternshipReport::factory()->reviewed()->create(['student_id' => $studentA1->id, 'period_start' => today()->subDays(9), 'period_end' => today()->subDays(3)]);
        InternshipReport::factory()->create(['student_id' => $studentB1->id, 'status' => 'pending']);

        $criterion = EvaluationCriteria::factory()->create(['category' => 'Communication']);
        Evaluation::factory()->create([
            'student_id' => $studentA1->id,
            'supervisor_id' => $supervisorA->id,
            'company_id' => $companyX->id,
            'status' => 'draft',
        ]);
        $evaluationB = Evaluation::factory()->submitted()->create([
            'student_id' => $studentB1->id,
            'supervisor_id' => $supervisorB->id,
            'company_id' => $companyY->id,
        ]);
        EvaluationResponse::factory()->create([
            'evaluation_id' => $evaluationB->id,
            'evaluation_criteria_id' => $criterion->id,
            'rating' => 4,
        ]);

        $notifications = [
            'supervisorA' => Notification::factory()->create(['user_id' => $supervisorA->id, 'type' => 'report_submitted', 'data' => ['report_id' => 1]]),
            'studentA1' => Notification::factory()->create(['user_id' => $studentA1->user_id, 'type' => 'report_reviewed', 'data' => ['report_id' => 2]]),
            'studentB1' => Notification::factory()->create(['user_id' => $studentB1->user_id, 'type' => 'report_reviewed', 'data' => ['report_id' => 3]]),
            'coordinator' => Notification::factory()->create(['user_id' => $coordinator->id, 'type' => 'student_registered', 'data' => ['user_id' => $unassigned->user_id]]),
            'admin' => Notification::factory()->create(['user_id' => $admin->id, 'type' => 'attendance_pending', 'data' => ['attendance_id' => 1]]),
        ];

        return compact(
            'supervisorA', 'supervisorB', 'coordinator', 'admin',
            'companyX', 'companyY',
            'studentA1', 'studentA2', 'studentB1', 'unassigned',
            'notifications',
        );
    }

    /**
     * The user for a role in the fixture (Student = A1's account).
     */
    private function fixtureUser(array $fixture, int $roleId): User
    {
        return match ($roleId) {
            1 => $fixture['studentA1']->user,
            2 => $fixture['coordinator'],
            3 => $fixture['supervisorA'],
            4 => $fixture['admin'],
        };
    }
}
