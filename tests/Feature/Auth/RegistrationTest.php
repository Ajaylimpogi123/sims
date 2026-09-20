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

    public function test_non_admin_cannot_register_a_user_via_user_management(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)
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
}
