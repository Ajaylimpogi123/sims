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

    public function test_student_cannot_view_user_management_page(): void
    {
        $student = User::factory()->create(['role_id' => 1]);

        $this->actingAs($student)
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

    public function test_coordinators_user_list_never_includes_administrator_rows(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $otherAdmin = User::factory()->create(['role_id' => 4]);
        User::factory()->create(['role_id' => 1]);
        User::factory()->create(['role_id' => 3]);

        $response = $this->actingAs($coordinator)->get('/user-management');

        $response->assertOk();
        $response->assertInertia(function (Assert $page) use ($otherAdmin) {
            $page->component('UserManagement/Index');

            $page->where('roles', fn ($roles) => collect($roles)
                ->doesntContain(fn ($role) => (int) $role['id'] === 4));

            $page->where('users.data', fn ($users) => collect($users)
                ->doesntContain(fn ($user) => (int) $user['id'] === $otherAdmin->id
                    || (int) $user['role_id'] === 4));
        });
    }

    public function test_admins_user_list_still_includes_administrator_rows(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $otherAdmin = User::factory()->create(['role_id' => 4]);

        $response = $this->actingAs($admin)->get('/user-management');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('UserManagement/Index')
            ->where('users.data', fn ($users) => collect($users)
                ->contains(fn ($user) => (int) $user['id'] === $otherAdmin->id))
        );
    }

    public function test_pagination_total_reflects_the_filtered_count_for_a_coordinator(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        User::factory()->count(2)->create(['role_id' => 1]);
        User::factory()->count(3)->create(['role_id' => 4]);

        // 1 (coordinator, self) + 2 students = 3 non-admin users total;
        // the 3 administrators must not count toward the total shown.
        $response = $this->actingAs($coordinator)->get('/user-management');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('UserManagement/Index')
            ->where('users.total', 3)
        );
    }

    public function test_coordinator_cannot_promote_an_existing_user_to_administrator(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $target = User::factory()->create(['role_id' => 1]);

        $response = $this->actingAs($coordinator)->patch("/user-management/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => 4,
        ]);

        $response->assertSessionHasErrors('role_id');
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => 1,
        ]);
    }

    public function test_coordinator_cannot_edit_an_existing_administrators_account(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $admin = User::factory()->create(['role_id' => 4, 'name' => 'Original Admin']);

        $response = $this->actingAs($coordinator)->patch("/user-management/{$admin->id}", [
            'name' => 'Renamed By Coordinator',
            'email' => $admin->email,
            'role_id' => 4,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'name' => 'Original Admin',
        ]);
    }

    public function test_admin_can_still_promote_a_user_to_administrator(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $target = User::factory()->create(['role_id' => 2]);

        $response = $this->actingAs($admin)->patch("/user-management/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => 4,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role_id' => 4,
        ]);
    }

    public function test_admin_can_still_edit_another_administrators_account(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $otherAdmin = User::factory()->create(['role_id' => 4, 'name' => 'Original Admin']);

        $response = $this->actingAs($admin)->patch("/user-management/{$otherAdmin->id}", [
            'name' => 'Renamed By Admin',
            'email' => $otherAdmin->email,
            'role_id' => 4,
        ]);

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'name' => 'Renamed By Admin',
        ]);
    }

    public function test_coordinator_cannot_toggle_an_administrators_status(): void
    {
        $coordinator = User::factory()->create(['role_id' => 2]);
        $admin = User::factory()->create(['role_id' => 4, 'status' => 'active']);

        $this->actingAs($coordinator)
            ->patch("/user-management/{$admin->id}/toggle-status")
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_still_toggle_another_administrators_status(): void
    {
        $admin = User::factory()->create(['role_id' => 4]);
        $otherAdmin = User::factory()->create(['role_id' => 4, 'status' => 'active']);

        $response = $this->actingAs($admin)->patch("/user-management/{$otherAdmin->id}/toggle-status");

        $response->assertRedirect(route('user-management.index', absolute: false));
        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'status' => 'inactive',
        ]);
    }
}
