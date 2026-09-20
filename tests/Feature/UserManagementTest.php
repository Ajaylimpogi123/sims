<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_view_user_management_page(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->get('/user-management');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('UserManagement/Index')
            ->has('roles')
            ->has('users')
            ->has('filters')
            ->missing('branches')
        );
    }

    public function test_non_admin_cannot_view_user_management_page(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);

        $this->actingAs($coordinator)
            ->get('/user-management')
            ->assertForbidden();
    }

    public function test_admin_can_register_a_user_with_chosen_role(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->post('/user-management/create', [
            'name' => 'New Coordinator',
            'email' => 'coordinator@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role_id' => 2,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'email' => 'coordinator@example.com',
            'role_id' => 2,
        ]);
    }

    public function test_admin_can_update_a_users_role(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $target = User::factory()->create(['role_id' => 1]);

        $response = $this->actingAs($admin)->patch("/user-management/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => 3,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => 3,
        ]);
    }

    public function test_admin_can_toggle_another_users_status(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $target = User::factory()->create(['role_id' => 1, 'status' => 'active']);

        $response = $this->actingAs($admin)->patch("/user-management/{$target->id}/toggle-status");

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'inactive',
        ]);
    }

    public function test_admin_cannot_toggle_their_own_status(): void
    {
        $admin = User::factory()->create(['role_id' => 4, 'status' => 'active']);

        $this->actingAs($admin)
            ->patch("/user-management/{$admin->id}/toggle-status")
            ->assertForbidden();
    }
}
