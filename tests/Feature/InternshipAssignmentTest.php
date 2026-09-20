<?php

namespace Tests\Feature;

use App\Models\Company;
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

    public function test_coordinator_can_assign_student_to_company_with_available_slots(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $company = Company::factory()->create(['slots' => 2]);
        $student = Student::factory()->create();

        $response = $this->actingAs($coordinator)->patch(
            "/internship-assignment/{$student->id}",
            [
                'company_id' => $company->id,
                'supervisor_id' => '',
                'internship_status' => 'ongoing',
            ],
        );

        $response->assertRedirect(route('internship-assignment.index', absolute: false));
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
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
            [
                'company_id' => $company->id,
                'supervisor_id' => '',
                'internship_status' => 'ongoing',
            ],
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
            [
                'company_id' => $company->id,
                'supervisor_id' => '',
                'internship_status' => 'completed',
            ],
        );

        $response->assertSessionDoesntHaveErrors('company_id');
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'company_id' => $company->id,
            'internship_status' => 'completed',
        ]);
    }
}
