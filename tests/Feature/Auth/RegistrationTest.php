<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_self_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_authenticated_users_are_redirected_away_from_self_registration(): void
    {
        $user = User::factory()->create(['role_id' => 1]);

        $this->actingAs($user)
            ->get('/register')
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_student_can_self_register_and_gets_a_student_profile(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Student',
            'email' => 'new.student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'student_number' => '2024-00001',
            'course' => 'BSIT',
            'section' => 'A',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@example.com',
            'role_id' => 1,
        ]);

        $this->assertDatabaseHas('students', [
            'student_number' => '2024-00001',
            'course' => 'BSIT',
            'section' => 'A',
        ]);
    }

    public function test_self_registration_requires_student_profile_fields(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Student',
            'email' => 'incomplete@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['student_number', 'course', 'section']);
        $this->assertGuest();
    }

    public function test_admin_can_register_a_user_with_a_chosen_role_via_user_management(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->post('/user-management/create', [
            'name' => 'New Coordinator',
            'email' => 'new.coordinator@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 2,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'new.coordinator@example.com',
            'role_id' => 2,
        ]);
    }

    public function test_coordinator_can_also_register_a_user_via_user_management(): void
    {
        // /user-management/* is role:2,4 (Coordinator + Admin), not
        // Admin-only, despite the name — confirmed intentional.
        $coordinator = User::factory()->create(['role_id' => 2]);

        $response = $this->actingAs($coordinator)->post('/user-management/create', [
            'name' => 'New Supervisor',
            'email' => 'coordinator.created@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 3,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'coordinator.created@example.com',
            'role_id' => 3,
        ]);
    }

    public function test_coordinator_cannot_register_a_student_via_user_management(): void
    {
        // Students may only originate via the separate self-registration flow
        // (/register), which also creates the matching Student profile row.
        // This admin-driven flow never collects student profile fields, so a
        // role_id=1 submission must be rejected, not silently create a
        // profile-less account that later crashes on /my-attendance and
        // /my-reports.
        $coordinator = User::factory()->create(['role_id' => 2]);

        $response = $this->actingAs($coordinator)->post('/user-management/create', [
            'name' => 'New Student',
            'email' => 'coordinator.blocked-student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 1,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['email' => 'coordinator.blocked-student@example.com']);
    }

    public function test_admin_cannot_register_a_student_via_user_management(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->post('/user-management/create', [
            'name' => 'New Student',
            'email' => 'admin.blocked-student@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 1,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['email' => 'admin.blocked-student@example.com']);
    }

    public function test_student_cannot_register_a_user_via_user_management(): void
    {
        $student = User::factory()->create(['role_id' => 1]);

        $this->actingAs($student)
            ->post('/user-management/create', [
                'name' => 'Should Not Be Created',
                'email' => 'blocked@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role_id' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_coordinator_cannot_register_a_new_administrator(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);

        $response = $this->actingAs($coordinator)->post('/user-management/create', [
            'name' => 'Should Not Become Admin',
            'email' => 'escalation@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 4,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.com']);
    }

    public function test_admin_can_still_register_a_new_administrator(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->post('/user-management/create', [
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 4,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'new.admin@example.com',
            'role_id' => 4,
        ]);
    }

    public function test_get_user_management_create_is_removed_as_dead_scaffolding(): void
    {
        // Creation is handled inline on /user-management via POST
        // user-management.store; this GET route rendered the wrong (public
        // self-registration) component and was linked from nowhere in the
        // UI, so it has been removed. The path itself still exists for POST
        // (user-management.store), so GET now correctly 405s instead of
        // rendering the dead page.
        $admin = User::factory()->create(['role_id' => 4]);

        $this->actingAs($admin)
            ->get('/user-management/create')
            ->assertStatus(405);
    }
}
