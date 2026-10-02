<?php

namespace Tests\Feature\Policies;

use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Shared cast for the policy matrix tests:
 *
 * - $supervisor supervises $student (owned by $studentUser)
 * - $otherSupervisor supervises $otherStudent (owned by $otherStudentUser)
 * - $coordinator, $admin: program-wide
 * - $profilelessStudent: a role-1 user with no Student row
 */
abstract class PolicyTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $supervisor;

    protected User $otherSupervisor;

    protected User $coordinator;

    protected User $admin;

    protected User $studentUser;

    protected User $otherStudentUser;

    protected User $profilelessStudent;

    protected Student $student;

    protected Student $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR]);
        $this->otherSupervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR]);
        $this->coordinator = User::factory()->create(['role_id' => User::ROLE_COORDINATOR]);
        $this->admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);
        $this->profilelessStudent = User::factory()->create(['role_id' => User::ROLE_STUDENT]);

        $this->student = Student::factory()->create(['supervisor_id' => $this->supervisor->id]);
        $this->otherStudent = Student::factory()->create(['supervisor_id' => $this->otherSupervisor->id]);

        $this->studentUser = $this->student->user;
        $this->otherStudentUser = $this->otherStudent->user;
    }

    /**
     * Assert exactly which of the cast may perform $ability on $subject.
     *
     * @param  list<string>  $allowed  property names of the users who are allowed
     */
    protected function assertAbilityMatrix(string $ability, mixed $subject, array $allowed): void
    {
        $cast = [
            'studentUser', 'otherStudentUser', 'profilelessStudent',
            'supervisor', 'otherSupervisor', 'coordinator', 'admin',
        ];

        foreach ($cast as $name) {
            $expected = in_array($name, $allowed, true);
            // Fresh instance so no relation cached by a previous check leaks in.
            $user = $this->{$name}->fresh();

            $this->assertSame(
                $expected,
                Gate::forUser($user)->allows($ability, $subject),
                sprintf('%s should %s be allowed to %s.', $name, $expected ? '' : 'NOT', $ability),
            );
        }
    }
}
