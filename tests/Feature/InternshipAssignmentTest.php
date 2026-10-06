<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternshipAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * Builds a full valid payload for the merged update endpoint, seeded
     * from the student's current values so individual tests only need to
     * override the field(s) they care about.
     */
    private function updatePayload(Student $student, array $overrides = []): array
    {
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

    public function test_coordinator_can_assign_student_to_company_with_available_slots(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 2]);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $company->id,
                'internship_status' => 'ongoing',
            ]),
        );

        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
            'internship_status' => 'ongoing',
        ]);
    }

    public function test_assignment_is_blocked_once_company_reaches_slot_capacity(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 1]);

        Student::factory()->create(['company_id' => $company->id]);
        $overflowStudent = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$overflowStudent->id}",
            $this->updatePayload($overflowStudent, ['company_id' => $company->id]),
        );

        $response->assertSessionHasErrors('company_id');
        $this->assertDatabaseHas('students', [
            'id' => $overflowStudent->id,
            'company_id' => null,
        ]);
    }

    public function test_reassigning_the_same_company_does_not_trip_the_capacity_check(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 1]);
        $student = Student::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $company->id,
                'internship_status' => 'completed',
            ]),
        );

        $response->assertSessionDoesntHaveErrors('company_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
            'internship_status' => 'completed',
        ]);
    }

    public function test_update_succeeds_when_internship_schedule_is_omitted_entirely(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = Student::factory()->create(['internship_schedule' => 'Mon-Fri 9AM-5PM']);

        $payload = $this->updatePayload($student);
        unset($payload['internship_schedule']);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $payload,
        );

        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'internship_schedule' => null,
        ]);
    }

    public function test_coordinator_can_update_only_profile_fields(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'name' => 'Updated Name',
                'course' => 'BSIT',
                'section' => 'C',
            ]),
        );

        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $student->user_id,
            'name' => 'Updated Name',
        ]);
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'course' => 'BSIT',
            'section' => 'C',
        ]);
    }

    public function test_email_must_be_unique_ignoring_the_students_own_current_email(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $existingUser = User::factory()->create(['email' => 'taken@example.com']);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['email' => 'taken@example.com']),
        );

        $response->assertSessionHasErrors('email');

        // Saving with the student's own unchanged email should still work.
        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student),
        );
        $response->assertSessionDoesntHaveErrors('email');
    }

    public function test_supervisor_must_actually_be_a_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $notASupervisor = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['supervisor_id' => $notASupervisor->id]),
        );

        $response->assertSessionHasErrors('supervisor_id');
    }

    public function test_supervisor_must_be_on_the_companys_roster_to_be_assigned(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        // Deliberately NOT attached to $company's roster.
        $student = Student::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $company->id,
                'supervisor_id' => $supervisor->id,
            ]),
        );

        $response->assertSessionHasErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'supervisor_id' => null,
        ]);
    }

    public function test_supervisor_on_the_companys_roster_can_be_assigned(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $company->id,
                'supervisor_id' => $supervisor->id,
            ]),
        );

        $response->assertSessionDoesntHaveErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    public function test_a_company_with_no_roster_supervisors_rejects_any_supervisor_assignment(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisorElsewhere = User::factory()->create(['role_id' => 3]);
        $otherCompany = Company::factory()->create();
        $otherCompany->supervisors()->attach($supervisorElsewhere->id);
        $student = Student::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $company->id,
                'supervisor_id' => $supervisorElsewhere->id,
            ]),
        );

        $response->assertSessionHasErrors('supervisor_id');
    }

    public function test_supervisor_validated_against_the_resulting_company_when_both_change_together(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $oldCompany = Company::factory()->create(['slots' => 5]);
        $newCompany = Company::factory()->create(['slots' => 5]);
        $oldSupervisor = User::factory()->create(['role_id' => 3]);
        $newSupervisor = User::factory()->create(['role_id' => 3]);
        $oldCompany->supervisors()->attach($oldSupervisor->id);
        $newCompany->supervisors()->attach($newSupervisor->id);

        $student = Student::factory()->create([
            'company_id' => $oldCompany->id,
            'supervisor_id' => $oldSupervisor->id,
        ]);

        // Changing to the new company AND the new company's own supervisor
        // in the same request must succeed — validated against the
        // resulting (new) company, not the student's current one.
        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $newCompany->id,
                'supervisor_id' => $newSupervisor->id,
            ]),
        );

        $response->assertSessionDoesntHaveErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $newCompany->id,
            'supervisor_id' => $newSupervisor->id,
        ]);
    }

    public function test_keeping_the_old_supervisor_while_changing_company_is_rejected(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $oldCompany = Company::factory()->create(['slots' => 5]);
        $newCompany = Company::factory()->create(['slots' => 5]);
        $oldSupervisor = User::factory()->create(['role_id' => 3]);
        $oldCompany->supervisors()->attach($oldSupervisor->id);
        // $oldSupervisor is NOT on $newCompany's roster.

        $student = Student::factory()->create([
            'company_id' => $oldCompany->id,
            'supervisor_id' => $oldSupervisor->id,
        ]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => $newCompany->id,
                // supervisor_id left as the old supervisor on purpose
            ]),
        );

        $response->assertSessionHasErrors('supervisor_id');
    }

    public function test_clearing_the_company_while_keeping_a_supervisor_is_rejected(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => '',
                'supervisor_id' => $supervisor->id,
            ]),
        );

        $response->assertSessionHasErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    public function test_clearing_both_company_and_supervisor_together_succeeds(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, [
                'company_id' => '',
                'supervisor_id' => '',
            ]),
        );

        $response->assertSessionDoesntHaveErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => null,
            'supervisor_id' => null,
        ]);
    }

    public function test_editing_an_unrelated_field_still_works_after_supervisor_leaves_the_roster(): void
    {
        // Regression test (Finding 6): the company roster
        // (company_supervisors pivot, "available for assignment") and a
        // student's actual assignment (students.supervisor_id) are
        // separate mechanisms and can legitimately drift apart — e.g. a
        // supervisor detached from a company's roster while still actively
        // assigned to a student there. Saving unrelated fields (here: just
        // the student's name) must not be blocked by re-validating roster
        // membership for a supervisor_id that isn't actually changing in
        // this request.
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        // Supervisor detached from the roster while still assigned to the student.
        $company->supervisors()->detach($supervisor->id);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['name' => 'Updated Name']),
        );

        $response->assertSessionDoesntHaveErrors('supervisor_id');
        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $student->user_id,
            'name' => 'Updated Name',
        ]);
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    public function test_reassigning_to_a_different_supervisor_still_requires_roster_membership_after_drift(): void
    {
        // Same drifted setup as above, but this request actually tries to
        // change the supervisor — roster membership must still be enforced
        // for that new value.
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);
        $otherSupervisor = User::factory()->create(['role_id' => 3]); // not on roster

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $company->supervisors()->detach($supervisor->id);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['supervisor_id' => $otherSupervisor->id]),
        );

        $response->assertSessionHasErrors('supervisor_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    public function test_updating_only_profile_fields_does_not_fire_an_assignment_notification(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $studentUser = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $studentUser->id]);

        $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['name' => 'Changed Name Only']),
        );

        $this->assertSame(
            0,
            Notification::where('type', 'assignment_updated')->count(),
        );
    }

    public function test_changing_the_assignment_fires_an_assignment_notification(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $studentUser = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'internship_status' => 'not_started',
        ]);

        $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['internship_status' => 'ongoing']),
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $studentUser->id,
            'type' => 'assignment_updated',
        ]);
    }

    public function test_assigning_a_student_to_an_inactive_company_is_refused(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->inactive()->create(['slots' => 5, 'company_name' => 'Dormant Inc']);
        $student = Student::factory()->create();

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => $company->id]))
            ->assertSessionHasErrors(['company_id' => 'Dormant Inc is inactive. Activate it in Company Management before assigning students to it.']);

        $this->assertNull($student->fresh()->company_id);
    }

    public function test_picking_a_new_supervisor_at_an_inactive_company_is_refused(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->inactive()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'active']);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create(['company_id' => $company->id]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['supervisor_id' => $supervisor->id]))
            ->assertSessionHasErrors('company_id');

        $this->assertNull($student->fresh()->supervisor_id);
    }

    public function test_a_student_already_at_an_inactive_company_with_an_inactive_supervisor_can_still_be_edited(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->inactive()->create(['slots' => 1]);
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'inactive']);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, [
                'section' => 'Z',
                'internship_status' => 'completed',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('internship-assignment.index', absolute: false));

        $this->assertDatabaseHas('students', ['id' => $student->id, 'section' => 'Z', 'internship_status' => 'completed']);

        // Removing the supervisor is always allowed.
        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student->fresh(), ['supervisor_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->supervisor_id);
    }

    public function test_assigning_an_inactive_supervisor_is_refused(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'inactive']);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create(['company_id' => $company->id]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['supervisor_id' => $supervisor->id]))
            ->assertSessionHasErrors(['supervisor_id' => "This supervisor's account is inactive. Activate it in User Management before assigning students to them."]);

        $this->assertNull($student->fresh()->supervisor_id);
    }

    public function test_array_values_are_a_validation_error_not_a_server_error(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company->supervisors()->attach($supervisor->id);
        $student = Student::factory()->create();

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, [
                'company_id' => [$company->id],
                'supervisor_id' => [$supervisor->id],
                'email' => ['a@b.test'],
                'student_number' => ['X-1'],
            ]))
            ->assertSessionHasErrors(['company_id', 'supervisor_id', 'email', 'student_number']);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, [
                'company_id' => [$company->id],
                'supervisor_id' => $supervisor->id,
            ]))
            ->assertSessionHasErrors('company_id');
    }

    public function test_index_exposes_company_and_roster_status_for_the_form(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->inactive()->create();
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'inactive']);
        $company->supervisors()->attach($supervisor->id);

        $this->actingAs($coordinator)
            ->get('/internship-assignment')
            ->assertInertia(fn ($page) => $page
                ->component('InternshipAssignment/Index')
                ->where('companies.0.status', 'inactive')
                ->where('companies.0.supervisors.0.status', 'inactive'));
    }

    public function test_coordinator_can_toggle_a_students_account_status(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $studentUser = User::factory()->create(['role_id' => 1, 'status' => 'active']);
        $student = Student::factory()->create(['user_id' => $studentUser->id]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}/toggle-status",
        );

        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'status' => 'inactive',
        ]);
    }
}
