<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Deactivating a user or changing their role on the website revokes their
 * mobile API tokens immediately (instead of waiting for the per-request
 * EnsureMobileAppAccess check on the app's next call).
 */
class TokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role_id' => $roleId, 'status' => 'active'], $attributes));
    }

    private function editPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
        ], $overrides);
    }

    private function tokenCount(User $user): int
    {
        return PersonalAccessToken::where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->count();
    }

    public function test_deactivating_a_user_revokes_all_their_tokens_and_only_theirs(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $bystander = $this->user(User::ROLE_STUDENT);
        $phone = $supervisor->createToken('phone')->plainTextToken;
        $supervisor->createToken('tablet');
        $bystander->createToken('phone');

        $this->actingAs($admin)
            ->patch(route('user-management.toggle-status', $supervisor->id))
            ->assertRedirect(route('user-management.index', absolute: false));

        $this->assertSame('inactive', $supervisor->fresh()->status);
        $this->assertSame(0, $this->tokenCount($supervisor));
        $this->assertSame(1, $this->tokenCount($bystander));

        // The app is signed out: the old token no longer authenticates.
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_coordinator_deactivating_a_student_also_revokes(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $student = $this->user(User::ROLE_STUDENT);
        $student->createToken('phone');

        $this->actingAs($coordinator)
            ->patch(route('user-management.toggle-status', $student->id))
            ->assertRedirect();

        $this->assertSame(0, $this->tokenCount($student));
    }

    public function test_reactivating_a_user_does_not_touch_tokens(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR, ['status' => 'inactive']);
        $supervisor->createToken('phone');

        $this->actingAs($admin)
            ->patch(route('user-management.toggle-status', $supervisor->id))
            ->assertRedirect();

        $this->assertSame('active', $supervisor->fresh()->status);
        $this->assertSame(1, $this->tokenCount($supervisor));
    }

    public function test_changing_a_users_role_revokes_their_tokens(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $token = $supervisor->createToken('phone')->plainTextToken;

        $this->actingAs($admin)
            ->patch(
                route('user-management.update', $supervisor->id),
                $this->editPayload($supervisor, ['role_id' => User::ROLE_COORDINATOR]),
            )
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('user-management.index', absolute: false));

        $this->assertSame(User::ROLE_COORDINATOR, (int) $supervisor->fresh()->role_id);
        $this->assertSame(0, $this->tokenCount($supervisor));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_editing_without_changing_the_role_keeps_tokens(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $supervisor->createToken('phone');

        $this->actingAs($admin)
            ->patch(
                route('user-management.update', $supervisor->id),
                $this->editPayload($supervisor, ['name' => 'Renamed Supervisor']),
            )
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Supervisor', $supervisor->fresh()->name);
        $this->assertSame(1, $this->tokenCount($supervisor));
    }

    public function test_a_rejected_role_change_keeps_tokens(): void
    {
        $coordinator = $this->user(User::ROLE_COORDINATOR);
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $supervisor->createToken('phone');

        // Coordinators may not promote anyone to Administrator.
        $this->actingAs($coordinator)
            ->patch(
                route('user-management.update', $supervisor->id),
                $this->editPayload($supervisor, ['role_id' => User::ROLE_ADMIN]),
            )
            ->assertSessionHasErrors('role_id');

        $this->assertSame(User::ROLE_SUPERVISOR, (int) $supervisor->fresh()->role_id);
        $this->assertSame(1, $this->tokenCount($supervisor));
    }
}
