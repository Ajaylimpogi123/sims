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

    public function test_placing_a_student_gives_them_the_companys_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $supervisor->id]);
        $student = Student::factory()->create();

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => $company->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);
    }

    public function test_a_sent_supervisor_id_is_ignored(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $someoneElse = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $supervisor->id]);
        $student = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        foreach ([$someoneElse->id, null, [$someoneElse->id], true] as $sent) {
            $this->actingAs($coordinator)
                ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['supervisor_id' => $sent]))
                ->assertSessionHasNoErrors();

            $this->assertSame($supervisor->id, $student->fresh()->supervisor_id);
        }
    }

    public function test_moving_company_switches_to_the_new_companys_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $oldSupervisor = User::factory()->create(['role_id' => 3]);
        $newSupervisor = User::factory()->create(['role_id' => 3]);
        $oldCompany = Company::factory()->create(['slots' => 5, 'supervisor_id' => $oldSupervisor->id]);
        $newCompany = Company::factory()->create(['slots' => 5, 'supervisor_id' => $newSupervisor->id]);
        $unsupervised = Company::factory()->create(['slots' => 5]);

        $student = Student::factory()->create([
            'company_id' => $oldCompany->id,
            'supervisor_id' => $oldSupervisor->id,
        ]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => $newCompany->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $newCompany->id,
            'supervisor_id' => $newSupervisor->id,
        ]);

        // A company without a supervisor leaves the student with none.
        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student->fresh(), ['company_id' => $unsupervised->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $unsupervised->id,
            'supervisor_id' => null,
        ]);
    }

    public function test_clearing_the_company_clears_the_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $supervisor->id]);

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => null,
            'supervisor_id' => null,
        ]);
    }

    public function test_editing_an_unrelated_field_keeps_the_supervisor(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3]);
        $company = Company::factory()->create(['slots' => 5, 'supervisor_id' => $supervisor->id]);

        $student = Student::factory()->create([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
        ]);

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            $this->updatePayload($student, ['name' => 'Updated Name']),
        );

        $response->assertSessionHasNoErrors();
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

    public function test_a_student_already_at_an_inactive_company_with_an_inactive_supervisor_can_still_be_edited(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'inactive']);
        $company = Company::factory()->inactive()->create(['slots' => 1, 'supervisor_id' => $supervisor->id]);
        $student = Student::factory()->create(['company_id' => $company->id, 'supervisor_id' => $supervisor->id]);

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, [
                'section' => 'Z',
                'internship_status' => 'completed',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('internship-assignment.index', absolute: false));

        $this->assertDatabaseHas('students', ['id' => $student->id, 'section' => 'Z', 'internship_status' => 'completed', 'supervisor_id' => $supervisor->id]);

        // Taking the student off the company is always allowed.
        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student->fresh(), ['company_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->supervisor_id);
    }

    public function test_array_values_are_a_validation_error_not_a_server_error(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $student = Student::factory()->create();

        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, [
                'company_id' => [$company->id],
                'email' => ['a@b.test'],
                'student_number' => ['X-1'],
            ]))
            ->assertSessionHasErrors(['company_id', 'email', 'student_number']);
    }

    public function test_boolean_or_empty_array_ids_are_a_validation_error_and_blank_means_none(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 5]);
        $student = Student::factory()->create(['company_id' => $company->id]);

        foreach ([true, []] as $bad) {
            $this->actingAs($coordinator)
                ->patchJson("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('company_id');
        }

        $this->assertSame($company->id, $student->fresh()->company_id);

        // The form's "" still means unassigned.
        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}", $this->updatePayload($student, ['company_id' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($student->fresh()->company_id);
    }

    public function test_index_exposes_company_status_and_supervisor_for_the_form(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $supervisor = User::factory()->create(['role_id' => 3, 'status' => 'inactive', 'name' => 'Sam Supervisor']);
        Company::factory()->inactive()->create(['supervisor_id' => $supervisor->id]);

        $this->actingAs($coordinator)
            ->get('/internship-assignment')
            ->assertInertia(fn ($page) => $page
                ->component('InternshipAssignment/Index')
                ->where('companies.0.status', 'inactive')
                ->where('companies.0.supervisor_id', $supervisor->id)
                ->where('companies.0.supervisor.name', 'Sam Supervisor')
                ->where('companies.0.supervisor.status', 'inactive'));
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
