<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_sees_their_own_hours_summary(): void
    {
        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create([
            'user_id' => $user->id,
            'required_hours' => 100,
        ]);
        Attendance::factory()->create([
            'student_id' => $student->id,
            'rendered_hours' => 8,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('roleId', 1)
                ->where('hours.rendered', 8)
                ->where('hours.required', 100)
            );
    }

    public function test_student_without_profile_sees_no_profile_notice(): void
    {
        $user = User::factory()->create(['role_id' => 1]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('noProfile', true)
            );
    }

    public function test_coordinator_and_admin_see_staff_counts(): void
    {
        Company::factory()->create(['status' => 'active']);
        $students = Student::factory()->count(2)->create();
        InternshipReport::factory()->create([
            'status' => 'pending',
            'student_id' => $students->first()->id,
        ]);

        foreach ([2, 4] as $roleId) {
            $user = User::factory()->create(['role_id' => $roleId]);

            $this->actingAs($user)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/Index')
                    ->where('roleId', $roleId)
                    ->where('counts.students', 2)
                    ->where('counts.companies', 1)
                    ->where('counts.pendingReportReviews', 1)
                );
        }
    }

    public function test_supervisor_sees_only_their_supervised_students(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $otherSupervisor = User::factory()->create(['role_id' => 3]);

        Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Student::factory()->create(['supervisor_id' => $otherSupervisor->id]);

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('roleId', 3)
                ->where('counts.supervisedStudents', 1)
            );
    }
}
