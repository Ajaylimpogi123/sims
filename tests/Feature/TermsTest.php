<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\InternshipReport;
use App\Models\Notification;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\CompanyManagementService;
use App\Services\InternshipAssignmentService;
use App\Services\NotificationService;
use App\Support\TermContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner feature (2026-10-16): School Year / Semester terms on the website —
 * term management, the header switcher and shared props, list / KPI
 * scoping, per-term slots, read-only past terms and registration.
 */
class TermsTest extends TestCase
{
    use RefreshDatabase;

    private Term $current;

    private Term $past;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Migration 23 creates the first current term.
        $this->current = Term::current() ?? Term::factory()->current()->create();
        $this->past = Term::factory()->create(['school_year_start' => 2020, 'semester' => 'second']);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role_id' => $roleId, 'status' => 'active']);
    }

    private function pastStudent(array $attributes = []): Student
    {
        return Student::factory()->inTerm($this->past)->create($attributes);
    }

    // ------------------------------------------------------------ model

    public function test_label_and_current_term(): void
    {
        $term = Term::factory()->create(['school_year_start' => 2041, 'semester' => 'summer']);

        $this->assertSame('SY 2041-2042 · Summer', $term->label);
        $this->assertSame('SY 2020-2021 · 2nd Semester', $this->past->label);
        $this->assertTrue($this->current->isCurrent());
        $this->assertFalse($term->isCurrent());
    }

    public function test_migration_created_a_current_term_from_the_date(): void
    {
        $first = Term::query()->orderBy('id')->first();

        $month = (int) $first->created_at->month;
        $year = (int) $first->created_at->year;

        [$expectedYear, $expectedSemester] = match (true) {
            $month >= 8 => [$year, 'first'],
            $month <= 5 => [$year - 1, 'second'],
            default => [$year - 1, 'summer'],
        };

        $this->assertSame($expectedYear, $first->school_year_start);
        $this->assertSame($expectedSemester, $first->semester);
        $this->assertSame(1, Term::query()->where('is_current', true)->count());
    }

    public function test_student_factory_defaults_to_the_current_term(): void
    {
        $this->assertSame($this->current->id, Student::factory()->create()->term_id);
    }

    // ------------------------------------------------------------ management

    public function test_only_coordinator_and_admin_manage_terms(): void
    {
        foreach ([User::ROLE_STUDENT, User::ROLE_SUPERVISOR] as $role) {
            $this->actingAs($this->user($role))->get(route('terms.index'))->assertForbidden();
            $this->actingAs($this->user($role))->post(route('terms.store'), ['school_year_start' => 2050, 'semester' => 'first'])->assertForbidden();
            $this->actingAs($this->user($role))->post(route('terms.set-current', $this->past))->assertForbidden();
        }

        $this->assertDatabaseMissing('terms', ['school_year_start' => 2050]);
    }

    public function test_index_lists_terms_newest_first_with_counts(): void
    {
        Student::factory()->count(2)->create();
        $this->pastStudent();

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->get(route('terms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Terms/Index')
                ->where('termList.0.id', $this->current->id)
                ->where('termList.0.students_count', 2)
                ->where('termList.0.is_current', true)
                ->where('termList.0.can_delete', false)
                ->where('termList.1.id', $this->past->id)
                ->where('termList.1.label', 'SY 2020-2021 · 2nd Semester')
                ->where('termList.1.semester_label', '2nd Semester')
                ->where('termList.1.students_count', 1)
                ->where('termList.1.can_delete', false)
                ->where('termList.1.is_past', true)
                ->where('termList.0.is_past', false)
                ->has('semesters', 3));
    }

    public function test_create_update_and_validation(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->actingAs($coordinator)
            ->post(route('terms.store'), ['school_year_start' => 2050, 'semester' => 'first', 'starts_on' => '2050-08-01', 'ends_on' => '2050-12-15'])
            ->assertRedirect(route('terms.index'))
            ->assertSessionHas('success', 'Term added.');

        $term = Term::query()->where('school_year_start', 2050)->firstOrFail();
        $this->assertFalse($term->is_current);
        $this->assertSame('2050-08-01', $term->starts_on->format('Y-m-d'));

        // Duplicate pair, bad semester, year range, end before start.
        $this->actingAs($coordinator)
            ->post(route('terms.store'), ['school_year_start' => 2050, 'semester' => 'first'])
            ->assertSessionHasErrors(['school_year_start' => 'That school year and semester already exists.']);
        $this->actingAs($coordinator)
            ->post(route('terms.store'), ['school_year_start' => 1999, 'semester' => 'winter', 'starts_on' => '2050-08-01', 'ends_on' => '2050-07-01'])
            ->assertSessionHasErrors(['school_year_start', 'semester', 'ends_on']);

        $this->actingAs($coordinator)
            ->patch(route('terms.update', $term), ['school_year_start' => 2050, 'semester' => 'second'])
            ->assertRedirect(route('terms.index'));
        $this->assertSame('second', $term->fresh()->semester);
        $this->assertNull($term->fresh()->starts_on);

        // Saving a term unchanged isn't a duplicate of itself.
        $this->actingAs($coordinator)
            ->patch(route('terms.update', $term), ['school_year_start' => 2050, 'semester' => 'second'])
            ->assertSessionHasNoErrors();
    }

    public function test_delete_refused_with_students_or_when_current(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $this->pastStudent();
        $empty = Term::factory()->create();

        $this->actingAs($coordinator)->delete(route('terms.destroy', $this->past))
            ->assertSessionHas('error', 'This term cannot be deleted while students are enrolled in it.');
        $this->actingAs($coordinator)->delete(route('terms.destroy', $this->current))
            ->assertSessionHas('error', 'The current term cannot be deleted.');
        $this->actingAs($coordinator)->delete(route('terms.destroy', $empty))
            ->assertSessionHas('success', 'Term deleted.');

        $this->assertModelExists($this->past);
        $this->assertModelExists($this->current);
        $this->assertModelMissing($empty);
    }

    public function test_set_current_switches_and_notifies_active_staff_and_supervisors(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $inactive = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $student = Student::factory()->create();
        $next = Term::factory()->create(['school_year_start' => 2050, 'semester' => 'first']);

        $this->actingAs($coordinator)
            ->post(route('terms.set-current', $next))
            ->assertRedirect(route('terms.index'))
            ->assertSessionHas('success', 'SY 2050-2051 · 1st Semester is now the current term.');

        $this->assertTrue($next->fresh()->is_current);
        $this->assertFalse($this->current->fresh()->is_current);
        $this->assertSame(1, Term::query()->where('is_current', true)->count());
        $this->assertSame($next->id, Term::currentId());

        foreach ([$coordinator, $admin, $supervisor] as $recipient) {
            $notification = Notification::query()->where('user_id', $recipient->id)->where('type', 'term_changed')->sole();
            $this->assertSame('New internship term', $notification->title);
            $this->assertSame('SY 2050-2051 · 1st Semester is now the current term.', $notification->body);
            $this->assertSame(['term_id' => $next->id], $notification->data);
            $this->assertSame(route('dashboard'), app(NotificationService::class)->urlFor($notification, $recipient));
        }

        $this->assertDatabaseMissing('notifications', ['user_id' => $inactive->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $student->user_id, 'type' => 'term_changed']);

        // Setting the already-current term again sends nothing.
        $this->actingAs($coordinator)->post(route('terms.set-current', $next));
        $this->assertSame(3, Notification::query()->where('type', 'term_changed')->count());
    }

    // ------------------------------------------------------------ switcher + shared props

    public function test_switcher_and_shared_props(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);

        $this->actingAs($coordinator)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentTerm', ['id' => $this->current->id, 'label' => $this->current->label])
                ->where('selectedTerm.id', $this->current->id)
                ->where('termArchived', false)
                ->where('termReadOnly', false)
                ->where('terms.0.id', $this->current->id)
                ->where('terms.1', ['id' => $this->past->id, 'label' => $this->past->label, 'is_current' => false, 'is_past' => true]));

        $this->actingAs($coordinator)->from(route('dashboard'))
            ->post(route('term.select'), ['term_id' => $this->past->id])
            ->assertRedirect(route('dashboard'));
        $this->assertSame($this->past->id, session(TermContext::SESSION_KEY));

        $this->actingAs($coordinator)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTerm', ['id' => $this->past->id, 'label' => $this->past->label, 'is_current' => false, 'is_past' => true])
                ->where('termArchived', true)
                ->where('termReadOnly', true));

        // An unknown term is a validation error; picking the current one
        // clears the choice.
        $this->actingAs($coordinator)->post(route('term.select'), ['term_id' => 999999])->assertSessionHasErrors('term_id');
        $this->actingAs($coordinator)->post(route('term.select'), ['term_id' => $this->current->id]);
        $this->assertNull(session(TermContext::SESSION_KEY));
    }

    public function test_admin_viewing_a_past_term_is_not_read_only(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->withSession([TermContext::SESSION_KEY => $this->past->id])->get(route('admin-dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('termArchived', true)->where('termReadOnly', false));
    }

    public function test_students_cant_switch_and_always_see_their_own_term(): void
    {
        $student = $this->pastStudent();

        $this->actingAs($student->user)->post(route('term.select'), ['term_id' => $this->current->id])->assertForbidden();

        $this->actingAs($student->user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTerm.id', $this->past->id)
                ->where('termArchived', true)
                ->where('student.term', ['id' => $this->past->id, 'label' => $this->past->label, 'is_current' => false, 'is_past' => true])
                ->where('student.can_submit', false));
    }

    // ------------------------------------------------------------ scoping

    public function test_lists_show_only_the_selected_term(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $admin = $this->user(User::ROLE_ADMIN);
        $now = Student::factory()->create();
        $old = $this->pastStudent();
        foreach ([$now, $old] as $student) {
            Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
            InternshipReport::factory()->create(['student_id' => $student->id]);
            Evaluation::factory()->create(['student_id' => $student->id]);
        }

        $pages = [
            [$coordinator, 'internship-assignment.index', 'students'],
            [$coordinator, 'attendance-monitoring.index', 'students'],
            [$coordinator, 'progress-monitoring.index', 'students'],
            [$coordinator, 'report-reviews.index', 'reports'],
            [$coordinator, 'supervisor-evaluations.index', 'evaluations'],
            [$admin, 'attendance-approvals.index', 'attendances'],
        ];

        foreach ([[$now, null], [$old, $this->past->id]] as [$expected, $selected]) {
            foreach ($pages as [$user, $route, $prop]) {
                $this->actingAs($user)->withSession($selected ? [TermContext::SESSION_KEY => $selected] : [])->get(route($route))
                    ->assertOk()
                    ->assertInertia(fn (Assert $page) => $page
                        ->has($prop, 1)
                        ->where($prop.'.0.'.($prop === 'students' ? 'id' : 'student_id'), $expected->id));
            }

            $this->actingAs($admin)->withSession($selected ? [TermContext::SESSION_KEY => $selected] : [])->get(route('admin-dashboard'))
                ->assertInertia(fn (Assert $page) => $page
                    ->where('kpis.totalStudents.value', 1)
                    ->where('counts.students', 1)
                    ->where('pendingApprovalsCount', 1)
                    ->where('kpis.pendingApprovals.value', 1)
                    ->where('kpis.pendingSupervisorEvaluations.value', 1));
        }

        $this->actingAs($coordinator)->withSession([TermContext::SESSION_KEY => null])->get(route('supervisor-evaluations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('students', 1)->where('students.0.id', $now->id));
    }

    public function test_company_students_count_and_slots_are_per_current_term(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $company = Company::factory()->create(['slots' => 1]);
        $this->pastStudent(['company_id' => $company->id]);
        $student = Student::factory()->notStarted()->create();

        $this->actingAs($coordinator)->get(route('company-management.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.students_count', 0)
                ->where('companies.0.delete_blocker', CompanyManagementService::HAS_STUDENTS));

        $this->actingAs($coordinator)->get(route('internship-assignment.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.students_count', 0)
                ->where('companies.0.supervisor_issue', 'no_supervisor'));

        // The past term's student doesn't take this term's only slot.
        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['company_id' => $company->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($company->id, $student->fresh()->company_id);

        // Now it is full for this term.
        $another = Student::factory()->notStarted()->create();
        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $another), $this->assignmentPayload($another, ['company_id' => $company->id]))
            ->assertSessionHasErrors('company_id');

        // Slots can't drop below this term's students (1), but may ignore
        // the past term's.
        $this->actingAs($coordinator)
            ->patch(route('company-management.update', $company), ['company_name' => $company->company_name, 'slots' => 1])
            ->assertSessionHasNoErrors();
        $this->actingAs($coordinator)
            ->patch(route('company-management.update', $company), ['company_name' => $company->company_name, 'slots' => 0])
            ->assertSessionHasErrors('slots');

        // The delete blocker counts every term.
        $this->actingAs($coordinator)->delete(route('company-management.destroy', $company))
            ->assertSessionHas('error');
    }

    public function test_supervisor_change_moves_only_current_term_students(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $old = $this->user(User::ROLE_SUPERVISOR);
        $new = $this->user(User::ROLE_SUPERVISOR);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $old->id]);
        $now = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $old->id]);
        $past = $this->pastStudent(['company_id' => $company->id, 'supervisor_id' => $old->id]);

        $this->actingAs($coordinator)
            ->patch(route('company-management.update', $company), ['company_name' => $company->company_name, 'slots' => 5, 'supervisor_id' => $new->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($new->id, $now->fresh()->supervisor_id);
        $this->assertSame($old->id, $past->fresh()->supervisor_id);
    }

    // ------------------------------------------------------------ moving terms

    public function test_not_started_student_can_move_to_another_term(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $next = Term::factory()->create(['school_year_start' => 2050, 'semester' => 'first']);
        $student = Student::factory()->notStarted()->create();
        $ongoing = Student::factory()->create();

        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['term_id' => $next->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($next->id, $student->fresh()->term_id);

        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $ongoing), $this->assignmentPayload($ongoing, ['term_id' => $next->id]))
            ->assertSessionHasErrors(['term_id' => InternshipAssignmentService::TERM_MOVE_MESSAGE]);
        $this->assertSame($this->current->id, $ongoing->fresh()->term_id);

        // Unchanged term_id is a no-op for a started student.
        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $ongoing), $this->assignmentPayload($ongoing, ['term_id' => $this->current->id]))
            ->assertSessionHasNoErrors();

        // An upcoming term is open: the coordinator can still edit the moved
        // student and bring them back.
        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student->fresh(), ['term_id' => $this->current->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->current->id, $student->fresh()->term_id);
    }

    public function test_only_an_admin_moves_a_student_into_a_past_term(): void
    {
        $student = Student::factory()->notStarted()->create();

        $this->actingAs($this->user(User::ROLE_COORDINATOR))
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['term_id' => $this->past->id]))
            ->assertSessionHasErrors(['term_id' => InternshipAssignmentService::TERM_PAST_MESSAGE]);
        $this->assertSame($this->current->id, $student->fresh()->term_id);

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['term_id' => $this->past->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->past->id, $student->fresh()->term_id);
    }

    public function test_upcoming_terms_are_open_but_not_yet_submittable(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $next = Term::factory()->create(['school_year_start' => 2050, 'semester' => 'first']);
        $student = Student::factory()->inTerm($next)->create();

        $this->assertFalse($next->isPast());
        $this->assertTrue($this->past->isPast());
        $this->assertSame(Student::TERM_NOT_STARTED_MESSAGE, $student->submissionState('x')['reason']);

        $this->actingAs($coordinator)->patch(route('attendance-monitoring.required-hours', $student), ['required_hours' => 300])
            ->assertForbidden(); // Coordinators are view-only here; the term isn't the reason.
        $this->actingAs($coordinator)
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['course' => 'ZZ-COURSE']))
            ->assertSessionHasNoErrors();
        $this->assertSame('ZZ-COURSE', $student->fresh()->course);

        $this->actingAs($coordinator)->withSession([TermContext::SESSION_KEY => $next->id])->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTerm.is_current', false)
                ->where('termArchived', false)
                ->where('termReadOnly', false));
    }

    public function test_term_references_in_props_carry_is_past(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $student = $this->pastStudent();

        $this->actingAs($coordinator)->withSession([TermContext::SESSION_KEY => $this->past->id])->get(route('internship-assignment.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('students.0.term.id', $this->past->id)
                ->where('students.0.term.is_past', true)
                ->where('students.0.term.is_current', false)
                ->where('selectedTerm.is_past', true));

        $this->actingAs($coordinator)->get(route('user-management.index'))
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($users) => collect($users)
                ->firstWhere('id', $student->user_id)['student']['term']['is_past'] === true));
    }

    public function test_user_management_respects_past_terms(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $student = $this->pastStudent();
        $user = $student->user;

        $this->actingAs($coordinator)->patch(route('user-management.toggle-status', $user->id))
            ->assertSessionHasErrors('term');
        $this->actingAs($coordinator)->patch(route('user-management.update', $user->id), [
            'name' => 'Changed Name', 'email' => $user->email, 'role_id' => User::ROLE_STUDENT,
        ])->assertSessionHasErrors('term');

        $this->assertSame('active', $user->fresh()->status);
        $this->assertNotSame('Changed Name', $user->fresh()->name);

        $this->actingAs($this->user(User::ROLE_ADMIN))->patch(route('user-management.toggle-status', $user->id))
            ->assertSessionHasNoErrors();
        $this->assertSame('inactive', $user->fresh()->status);
    }

    public function test_slots_are_checked_against_the_target_term(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $company = Company::factory()->create(['slots' => 1]);
        $this->pastStudent(['company_id' => $company->id]);
        $student = Student::factory()->notStarted()->create(['company_id' => $company->id]);

        // Moving into the past term (full there) at the same company.
        $this->actingAs($admin)
            ->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student, ['company_id' => $company->id, 'term_id' => $this->past->id]))
            ->assertSessionHasErrors('company_id');
        $this->assertSame($this->current->id, $student->fresh()->term_id);
    }

    // ------------------------------------------------------------ read-only past terms

    public function test_past_term_writes_are_refused_for_non_admins_on_the_website(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = $this->pastStudent(['supervisor_id' => $supervisor->id, 'internship_status' => 'not_started']);
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);
        $evaluation = Evaluation::factory()->create(['student_id' => $student->id, 'supervisor_id' => $supervisor->id]);
        $message = 'This record belongs to an archived term (SY 2020-2021 · 2nd Semester). Only an Administrator can change it.';

        $this->actingAs($coordinator)->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student))
            ->assertSessionHasErrors(['term' => $message]);
        $this->actingAs($coordinator)->patch(route('internship-assignment.toggle-status', $student))
            ->assertSessionHasErrors('term');
        $this->actingAs($coordinator)->patch(route('report-reviews.review', $report), ['comment' => 'ok'])
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->patch(route('attendance-approvals.approve-time-in', $attendance))
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->patch(route('attendance-monitoring.required-hours', $student), ['required_hours' => 300])
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->post(route('attendance-monitoring.attendances.store', $student), ['date' => today()->subDays(40)->toDateString(), 'time_in' => '08:00', 'time_out' => '17:00'])
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->delete(route('attendance-monitoring.attendances.destroy', $attendance))
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->patch(route('supervisor-evaluations.submit', $evaluation))
            ->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->post(route('supervisor-evaluations.store'), [
            'student_id' => $student->id, 'evaluation_period_start' => '2026-01-05', 'evaluation_period_end' => '2026-01-09',
        ])->assertSessionHasErrors('term');
        $this->actingAs($supervisor)->get(route('supervisor-evaluations.show', $evaluation))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));

        $this->assertSame('pending', $attendance->fresh()->time_in_status);
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame('draft', $evaluation->fresh()->status);
        $this->assertSame(486, $student->fresh()->required_hours);
        $this->assertSame(1, Evaluation::query()->count());
        $this->assertModelExists($attendance);
    }

    public function test_admin_may_still_change_past_terms(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $student = $this->pastStudent();
        $attendance = Attendance::factory()->pendingTimeIn()->create(['student_id' => $student->id]);
        $report = InternshipReport::factory()->create(['student_id' => $student->id]);

        $this->actingAs($admin)->patch(route('attendance-approvals.approve-time-in', $attendance))->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('report-reviews.review', $report), ['comment' => 'ok'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('attendance-monitoring.required-hours', $student), ['required_hours' => 300])->assertSessionHasNoErrors();
        $this->actingAs($admin)->patch(route('internship-assignment.update', $student), $this->assignmentPayload($student->fresh(), ['course' => 'BSCS']))
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $attendance->fresh()->time_in_status);
        $this->assertSame('reviewed', $report->fresh()->status);
        $this->assertSame(300, $student->fresh()->required_hours);
        $this->assertSame('BSCS', $student->fresh()->course);
    }

    public function test_past_term_students_cant_submit_work(): void
    {
        $student = $this->pastStudent();

        $this->assertFalse($student->canSubmitWork());
        $this->assertSame(['can_submit' => false, 'internship_status' => 'ongoing', 'reason' => 'Your internship term has ended.'],
            $student->submissionState('x'));

        $this->actingAs($student->user)->get(route('attendance.index'))
            ->assertInertia(fn (Assert $page) => $page->where('submission.reason', 'Your internship term has ended.'));
    }

    // ------------------------------------------------------------ registration

    public function test_registration_enrolls_in_the_current_term(): void
    {
        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('registrationOpen', true));

        $this->post(route('register'), $this->registration())->assertRedirect(route('dashboard'));

        $this->assertSame($this->current->id, Student::query()->where('student_number', '2026-12345')->sole()->term_id);
    }

    public function test_registration_is_closed_without_a_current_term(): void
    {
        Term::query()->update(['is_current' => false]);
        Term::flushCurrent();

        $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('registrationOpen', false));

        $this->post(route('register'), $this->registration())
            ->assertSessionHasErrors(['term' => 'Registration is closed: no active internship term.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('students', ['student_number' => '2026-12345']);
    }

    private function registration(): array
    {
        return [
            'name' => 'New Student',
            'email' => 'new.student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_number' => '2026-12345',
            'course' => 'BSIT',
            'section' => 'A',
        ];
    }

    private function assignmentPayload(Student $student, array $overrides = []): array
    {
        return $overrides + [
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $student->company_id ?? '',
            'internship_status' => $student->internship_status,
            'internship_schedule' => $student->internship_schedule,
            'required_hours' => $student->required_hours,
        ];
    }
}
