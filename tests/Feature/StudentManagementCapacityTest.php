<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentManagementCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function updatePayload(Student $student, ?int $companyId): array
    {
        return [
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $companyId,
            'internship_schedule' => $student->internship_schedule,
        ];
    }

    public function test_coordinator_can_assign_company_with_available_slots_via_student_update(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 2]);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/student-management/{$student->id}",
            $this->updatePayload($student, $company->id),
        );

        $response->assertRedirect(route('student-management.index', absolute: false));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
        ]);
    }

    public function test_student_update_is_blocked_once_company_reaches_slot_capacity(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 1]);

        Student::factory()->create(['company_id' => $company->id]);
        $overflowStudent = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/student-management/{$overflowStudent->id}",
            $this->updatePayload($overflowStudent, $company->id),
        );

        $response->assertSessionHasErrors('company_id');
        $this->assertDatabaseHas('students', [
            'id' => $overflowStudent->id,
            'company_id' => null,
        ]);
    }

    public function test_student_update_succeeds_when_internship_schedule_is_omitted_entirely(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $student = Student::factory()->create(['internship_schedule' => 'Mon-Fri 9AM-5PM']);

        $payload = $this->updatePayload($student, null);
        unset($payload['internship_schedule']);

        $response = $this->actingAs($coordinator)->patch(
            "/student-management/{$student->id}",
            $payload,
        );

        $response->assertRedirect(route('student-management.index', absolute: false));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'internship_schedule' => null,
        ]);
    }
}
