<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use App\Services\UserManagementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Follow-ups the user approved after Module 13 QA: deactivated accounts are
 * logged out of the website on their next request, a Student account keeps
 * its role, and Administrator ids answer 404 to Coordinators on the website.
 */
class UserManagementHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_an_account_deactivated_elsewhere_is_logged_out_of_the_website(): void
    {
        $supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR, 'status' => 'active']);

        $this->actingAs($supervisor)->get('/dashboard')->assertOk();

        // Any path that deactivates the account (Internship Assignment, a
        // direct update, …), not only User Management.
        $supervisor->forceFill(['status' => 'inactive'])->save();

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);

        $this->assertGuest('web');
    }

    public function test_an_active_account_is_not_affected(): void
    {
        $supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR, 'status' => 'active']);

        $this->actingAs($supervisor)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($supervisor, 'web');
    }

    public function test_a_student_accounts_role_cannot_be_changed_on_the_website(): void
    {
        $coordinator = User::factory()->create(['role_id' => User::ROLE_COORDINATOR]);
        $student = User::factory()->create(['role_id' => User::ROLE_STUDENT]);

        $this->actingAs($coordinator)
            ->patch("/user-management/{$student->id}", [
                'name' => $student->name,
                'email' => $student->email,
                'role_id' => User::ROLE_SUPERVISOR,
            ])
            ->assertSessionHasErrors(['role_id' => UserManagementService::STUDENT_ROLE_MESSAGE]);

        $this->assertSame(User::ROLE_STUDENT, (int) $student->fresh()->role_id);
    }

    public function test_a_student_account_can_still_be_edited_keeping_its_role(): void
    {
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);
        $student = User::factory()->create(['role_id' => User::ROLE_STUDENT]);

        $this->actingAs($admin)
            ->patch("/user-management/{$student->id}", [
                'name' => 'Renamed Student',
                'email' => $student->email,
                'role_id' => User::ROLE_STUDENT,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Student', $student->fresh()->name);
    }

    public function test_a_student_accounts_role_cannot_be_changed_through_the_api(): void
    {
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);
        $student = User::factory()->create(['role_id' => User::ROLE_STUDENT]);

        $headers = ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken];

        $this->getJson("/api/v1/users/{$student->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.assignable_roles', [['id' => User::ROLE_STUDENT, 'role_name' => 'Student']]);

        $this->patchJson("/api/v1/users/{$student->id}", [
            'name' => $student->name,
            'email' => $student->email,
            'role_id' => User::ROLE_COORDINATOR,
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.role_id.0', UserManagementService::STUDENT_ROLE_MESSAGE);

        $this->assertSame(User::ROLE_STUDENT, (int) $student->fresh()->role_id);
    }

    public function test_coordinator_gets_404_for_an_administrator_id_on_the_website(): void
    {
        $coordinator = User::factory()->create(['role_id' => User::ROLE_COORDINATOR]);
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN]);

        $this->actingAs($coordinator);

        $this->patch("/user-management/{$admin->id}", [
            'name' => 'x', 'email' => $admin->email, 'role_id' => User::ROLE_SUPERVISOR,
        ])->assertNotFound();

        $this->patch("/user-management/{$admin->id}/toggle-status")->assertNotFound();

        // Same answer as an id that doesn't exist.
        $this->patch('/user-management/999999/toggle-status')->assertNotFound();

        $this->assertSame(User::ROLE_ADMIN, (int) $admin->fresh()->role_id);
        $this->assertSame('active', $admin->fresh()->status);
    }
}
