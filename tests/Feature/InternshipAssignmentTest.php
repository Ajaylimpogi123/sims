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
