<?php

namespace Tests\Feature\Api\V1;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner feature (2026-10-16): terms through /api/v1 — the list, staff
 * management, `term_id` on lists and dashboards, the archived-term 422
 * and the per-row write flags.
 */
class TermsApiTest extends TestCase
{
    use RefreshDatabase;

    private Term $current;

    private Term $past;

    private const ARCHIVED = 'This record belongs to an archived term (SY 2020-2021 · 2nd Semester). Only an Administrator can change it.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->current = Term::current() ?? Term::factory()->current()->create();
        $this->past = Term::factory()->create(['school_year_start' => 2020, 'semester' => 'second']);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function api(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ])->json($method, $uri, $data);
    }

    private function pastStudent(array $attributes = []): Student
    {
        return Student::factory()->inTerm($this->past)->create($attributes);
    }

    private function assertArchived(TestResponse $response): void
    {
        $response->assertStatus(422)->assertExactJson([
            'message' => self::ARCHIVED,
            'code' => 'term_archived',
            'errors' => ['term' => [self::ARCHIVED]],
        ]);
    }

    // ------------------------------------------------------------ list + /me

    public function test_every_role_lists_terms_staff_get_counts_and_flags(): void
    {
        $this->pastStudent();

        $this->api('GET', '/api/v1/terms', $this->user(User::ROLE_SUPERVISOR))
            ->assertOk()
            ->assertJsonPath('current_term_id', $this->current->id)
            ->assertJsonPath('can_create', false)
            ->assertJsonPath('data.1', [
                'id' => $this->past->id,
                'label' => 'SY 2020-2021 · 2nd Semester',
                'school_year_start' => 2020,
                'semester' => 'second',
                'semester_label' => '2nd Semester',
                'starts_on' => null,
                'ends_on' => null,
                'is_current' => false,
                'is_past' => true,
            ]);

        $this->api('GET', '/api/v1/terms', $this->user(User::ROLE_COORDINATOR))
            ->assertOk()
            ->assertJsonPath('can_create', true)
            ->assertJsonPath('data.0.id', $this->current->id)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.1.students_count', 1)
            ->assertJsonPath('data.1.can_edit', true)
            ->assertJsonPath('data.1.can_delete', false);

        $student = Student::factory()->create();
        $this->api('GET', '/api/v1/terms', $student->user)->assertOk()->assertJsonPath('can_create', false);
    }

    public function test_me_carries_the_current_term_and_the_students_term(): void
    {
        $student = $this->pastStudent();

        $this->api('GET', '/api/v1/me', $student->user)
            ->assertOk()
            ->assertJsonPath('current_term.id', $this->current->id)
            ->assertJsonPath('current_term.is_current', true)
            ->assertJsonPath('student.term', ['id' => $this->past->id, 'label' => $this->past->label, 'is_current' => false, 'is_past' => true])
            ->assertJsonPath('student.can_submit', false);
    }

    // ------------------------------------------------------------ management

    public function test_staff_create_update_set_current_and_delete(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);

        $this->api('POST', '/api/v1/terms', $supervisor, ['school_year_start' => 2050, 'semester' => 'first'])->assertForbidden();

        $created = $this->api('POST', '/api/v1/terms', $coordinator, ['school_year_start' => 2050, 'semester' => 'first'])
            ->assertCreated()
            ->assertJsonPath('message', 'Term added.')
            ->assertJsonPath('term.label', 'SY 2050-2051 · 1st Semester')
            ->assertJsonPath('term.students_count', 0)
            ->assertJsonPath('term.can_delete', true)
            ->json('term.id');

        $this->api('POST', '/api/v1/terms', $coordinator, ['school_year_start' => 2050, 'semester' => 'first'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_year_start' => 'That school year and semester already exists.']);
        $this->api('POST', '/api/v1/terms', $coordinator, ['school_year_start' => true, 'semester' => 'x', 'starts_on' => '2050-02-01', 'ends_on' => '2050-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_year_start', 'semester', 'ends_on']);

        $this->api('PATCH', "/api/v1/terms/{$created}", $coordinator, ['school_year_start' => 2050, 'semester' => 'summer', 'starts_on' => '2051-06-01', 'ends_on' => '2051-07-31'])
            ->assertOk()
            ->assertJsonPath('term.semester', 'summer')
            ->assertJsonPath('term.starts_on', '2051-06-01');

        $this->api('POST', "/api/v1/terms/{$created}/current", $coordinator)
            ->assertOk()
            ->assertJsonPath('message', 'SY 2050-2051 · Summer is now the current term.')
            ->assertJsonPath('term.is_current', true);
        $this->assertSame($created, Term::currentId());
        $this->assertTrue(Notification::query()->where('user_id', $supervisor->id)->where('type', 'term_changed')->exists());

        $this->api('GET', '/api/v1/notifications', $supervisor)
            ->assertOk()
            ->assertJsonPath('data.0.type', 'term_changed')
            ->assertJsonPath('data.0.target.screen', 'home');

        $this->api('DELETE', "/api/v1/terms/{$created}", $coordinator)
            ->assertStatus(422)
            ->assertJsonPath('code', 'term_is_current');

        $this->pastStudent();
        $this->api('DELETE', "/api/v1/terms/{$this->past->id}", $coordinator)
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'This term cannot be deleted while students are enrolled in it.',
                'code' => 'term_has_students',
                'errors' => ['term' => ['This term cannot be deleted while students are enrolled in it.']],
            ]);

        // The former current term (no students) can go now.
        $this->api('DELETE', "/api/v1/terms/{$this->current->id}", $coordinator)
            ->assertOk()
            ->assertExactJson(['message' => 'Term deleted.']);
        $this->api('DELETE', '/api/v1/terms/999999', $coordinator)->assertNotFound();
    }

    // ------------------------------------------------------------ term_id on lists

    public function test_lists_and_dashboard_follow_term_id(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $now = Student::factory()->create();
        $old = $this->pastStudent();
        foreach ([$now, $old] as $student) {
            Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
            InternshipReport::factory()->create(['student_id' => $student->id]);
            Evaluation::factory()->create(['student_id' => $student->id]);
        }

        foreach ([[$now, ''], [$old, '?term_id='.$this->past->id]] as [$expected, $query]) {
            $this->api('GET', '/api/v1/assignments'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
            $this->api('GET', '/api/v1/monitoring/students'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
            $this->api('GET', '/api/v1/progress'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expected->id);
            $this->api('GET', '/api/v1/approvals'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.id', $expected->id);
            $this->api('GET', '/api/v1/approvals/count'.$query, $admin)->assertOk()->assertJsonPath('count', 1);
            $this->api('GET', '/api/v1/report-reviews'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.id', $expected->id);
            $this->api('GET', '/api/v1/evaluations'.$query, $admin)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student.id', $expected->id);
            $this->api('GET', '/api/v1/evaluations/students'.$query, $admin)->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.id', $expected->id);
            $this->api('GET', '/api/v1/analytics/filter-options'.$query, $admin)->assertOk()->assertJsonCount(1, 'students');
            $this->api('GET', '/api/v1/dashboard'.$query, $admin)
                ->assertOk()
                ->assertJsonPath('term.id', $expected->term_id)
                ->assertJsonPath('kpis.total_students.value', 1)
                ->assertJsonPath('kpis.pending_approvals.value', 1);
            $this->api('GET', '/api/v1/analytics'.$query, $admin)
                ->assertOk()
                ->assertJsonPath('filters.term_id', $expected->term_id)
                ->assertJsonPath('internship.status_breakdown.0.value', 1);
        }

        // Detail reads still open a past term's records.
        $this->api('GET', "/api/v1/assignments/{$old->id}", $admin)->assertOk();
        $this->api('GET', "/api/v1/monitoring/students/{$old->id}", $admin)->assertOk();

        foreach (['/api/v1/assignments', '/api/v1/dashboard', '/api/v1/approvals/count'] as $uri) {
            $this->api('GET', $uri.'?term_id=999999', $admin)->assertStatus(422)->assertJsonValidationErrors('term_id');
        }
    }

    public function test_students_always_get_their_own_term(): void
    {
        $student = $this->pastStudent();

        $this->api('GET', '/api/v1/dashboard?term_id='.$this->current->id, $student->user)
            ->assertOk()
            ->assertJsonPath('term.id', $this->past->id)
            ->assertJsonPath('student.internship.term.id', $this->past->id)
            ->assertJsonPath('student.internship.can_submit', false);
    }

    public function test_options_counts_current_term_and_expose_supervisor_issue(): void
    {
        $demoted = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create(['slots' => 2, 'supervisor_id' => $demoted->id]);
        Student::factory()->create(['company_id' => $company->id]);
        $this->pastStudent(['company_id' => $company->id]);

        $this->api('GET', '/api/v1/assignments/options?term_id='.$this->past->id, $this->user(User::ROLE_COORDINATOR))
            ->assertOk()
            ->assertJsonPath('companies.0.students_count', 1)
            ->assertJsonPath('companies.0.slots_available', 1)
            ->assertJsonPath('companies.0.supervisor_issue', 'not_a_supervisor');

        $this->api('GET', '/api/v1/companies', $this->user(User::ROLE_ADMIN))
            ->assertOk()
            ->assertJsonPath('data.0.students_count', 1)
            ->assertJsonPath('data.0.can_delete', false);
    }

    // ------------------------------------------------------------ archived writes

    public function test_archived_writes_are_a_term_archived_422_for_non_admins(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = $this->pastStudent(['supervisor_id' => $supervisor->id]);
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
        $entry = Attendance::factory()->create(['student_id' => $student->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);
        $evaluation = Evaluation::factory()->create(['student_id' => $student->id, 'supervisor_id' => $supervisor->id]);

        $this->assertArchived($this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, [
            'name' => $student->user->name, 'email' => $student->user->email, 'student_number' => $student->student_number,
            'course' => 'ZZ-COURSE', 'section' => $student->section, 'company_id' => null, 'internship_status' => 'ongoing',
        ]));
        $this->assertArchived($this->api('POST', "/api/v1/assignments/{$student->id}/deactivate", $coordinator));
        $this->assertArchived($this->api('POST', "/api/v1/report-reviews/{$report->id}/review", $coordinator, ['comment' => 'ok']));
        $this->assertArchived($this->api('POST', "/api/v1/attendance/{$attendance->id}/time-in/approve", $supervisor));
        $this->assertArchived($this->api('PATCH', "/api/v1/monitoring/students/{$student->id}/required-hours", $supervisor, ['required_hours' => 300]));
        $this->assertArchived($this->api('PATCH', "/api/v1/monitoring/attendance/{$entry->id}", $supervisor, ['date' => $entry->date->format('Y-m-d'), 'time_in' => '08:00', 'time_out' => '12:00']));
        $this->assertArchived($this->api('DELETE', "/api/v1/monitoring/attendance/{$entry->id}", $supervisor));
        $this->assertArchived($this->api('POST', "/api/v1/evaluations/{$evaluation->id}/submit", $supervisor));

        $this->assertNotSame('ZZ-COURSE', $student->fresh()->course);
        $this->assertSame('active', $student->user->fresh()->status);
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame('pending', $attendance->fresh()->time_in_status);
        $this->assertModelExists($entry);
    }

    public function test_flags_reflect_archived_terms_for_non_admins(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $student = $this->pastStudent(['supervisor_id' => $supervisor->id]);
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);
        $evaluation = Evaluation::factory()->create(['student_id' => $student->id, 'supervisor_id' => $supervisor->id]);
        $q = '?term_id='.$this->past->id;

        $this->api('GET', '/api/v1/assignments'.$q, $coordinator)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_toggle_status', false)
            ->assertJsonPath('data.0.term_id', $this->past->id)
            ->assertJsonPath('data.0.term.is_current', false);
        $this->api('GET', '/api/v1/monitoring/students'.$q, $supervisor)
            ->assertJsonPath('data.0.can_edit_attendance', false)
            ->assertJsonPath('data.0.can_edit_required_hours', false);
        $this->api('GET', "/api/v1/monitoring/students/{$student->id}/attendance", $supervisor)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_delete', false);
        $this->api('GET', '/api/v1/approvals'.$q, $supervisor)
            ->assertJsonPath('data.0.review.time_in.can_approve', false);
        $this->api('GET', '/api/v1/report-reviews'.$q, $coordinator)->assertJsonPath('data.0.can_review', false);
        $this->api('GET', '/api/v1/evaluations'.$q, $supervisor)
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.can_submit', false)
            ->assertJsonPath('can_create', false);

        // The Administrator keeps every action.
        $this->api('GET', '/api/v1/assignments'.$q, $admin)->assertJsonPath('data.0.can_edit', true);
        $this->api('GET', '/api/v1/approvals'.$q, $admin)->assertJsonPath('data.0.review.time_in.can_approve', true);
        $this->api('GET', '/api/v1/report-reviews'.$q, $admin)->assertJsonPath('data.0.can_review', true);
        $this->api('GET', '/api/v1/evaluations'.$q, $admin)
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('can_create', true);

        $this->api('POST', "/api/v1/attendance/{$attendance->id}/time-in/approve", $admin)->assertOk();
        $this->api('POST', "/api/v1/report-reviews/{$report->id}/review", $admin, ['comment' => 'ok'])->assertOk();
        $this->assertSame('reviewed', $report->fresh()->status);
        $this->assertSame('draft', $evaluation->fresh()->status);
    }

    public function test_term_moves_and_user_management_on_past_terms(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $next = Term::factory()->create(['school_year_start' => 2050, 'semester' => 'first']);
        $student = Student::factory()->notStarted()->create();
        $payload = fn (Student $s, int $termId) => [
            'name' => $s->user->name, 'email' => $s->user->email, 'student_number' => $s->student_number,
            'course' => $s->course, 'section' => $s->section, 'company_id' => null,
            'internship_status' => 'not_started', 'term_id' => $termId,
        ];

        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $payload($student, $this->past->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['term_id' => 'Only an Administrator can move a student into a past term.']);

        // Upcoming terms stay editable for the coordinator.
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $payload($student, $next->id))
            ->assertOk()
            ->assertJsonPath('student.term_id', $next->id)
            ->assertJsonPath('student.term.is_past', false)
            ->assertJsonPath('student.can_edit', true);
        $this->api('PATCH', "/api/v1/assignments/{$student->id}", $coordinator, $payload($student, $this->current->id))
            ->assertOk()
            ->assertJsonPath('student.term_id', $this->current->id);

        // User Management: a past-term student account is read-only too.
        $old = $this->pastStudent();
        $this->assertArchived($this->api('POST', "/api/v1/users/{$old->user_id}/deactivate", $coordinator));
        $this->assertArchived($this->api('PATCH', "/api/v1/users/{$old->user_id}", $coordinator, [
            'name' => 'Changed Name', 'email' => $old->user->email, 'role_id' => User::ROLE_STUDENT,
        ]));
        $this->api('GET', "/api/v1/users/{$old->user_id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_toggle_status', false);
        $this->api('POST', "/api/v1/users/{$old->user_id}/deactivate", $this->user(User::ROLE_ADMIN))->assertOk();
        $this->assertSame('inactive', $old->user->fresh()->status);
    }

    public function test_past_term_students_cant_submit_or_delete_reports(): void
    {
        $student = $this->pastStudent();
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);

        $this->api('GET', '/api/v1/attendance/today', $student->user)
            ->assertOk()
            ->assertJsonPath('submission.can_submit', false)
            ->assertJsonPath('submission.reason', 'Your internship term has ended.');
        $this->api('GET', "/api/v1/reports/{$report->id}", $student->user)
            ->assertOk()
            ->assertJsonPath('report.can_edit', false)
            ->assertJsonPath('report.can_delete', false);
        $this->api('DELETE', "/api/v1/reports/{$report->id}", $student->user)->assertStatus(422);
        $this->assertModelExists($report);
    }
}
