<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A user deactivated by staff (User Management or Internship Assignment)
 * sees the "deactivated" message on their next website request rather
 * than a silent bounce to the login page, and can't do anything meanwhile.
 * Uses real database sessions, as in production.
 */
class DeactivationMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['session.driver' => 'database']);
    }

    /** Logs $user in through the login form and returns their session id. */
    private function loginSession(User $user): string
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect();

        $id = $this->app['session']->driver()->getId();
        $this->assertSame(1, DB::table('sessions')->where('id', $id)->where('user_id', $user->id)->count());

        $this->freshClient();

        return $id;
    }

    /** Forgets the in-memory login, so the next request relies on the cookie alone. */
    private function freshClient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
    }

    private function asSession(string $sessionId): static
    {
        $this->freshClient();

        return $this->withCookie(config('session.cookie'), $sessionId);
    }

    private function assertDeactivatedOnNextRequest(string $sessionId, User $user): void
    {
        // Still a session row: the message can be flashed into it.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->asSession($sessionId)->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);

        $this->assertGuest('web');
        $this->assertSame(0, DB::table('sessions')->where('id', $sessionId)->count());

        // The old session cookie no longer logs anyone in.
        $this->asSession($sessionId)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_user_management_deactivation_shows_the_message(): void
    {
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN, 'status' => 'active']);
        $supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR, 'status' => 'active']);
        $supervisor->createToken('phone');

        $sessionId = $this->loginSession($supervisor);
        $this->asSession($sessionId)->get('/dashboard')->assertOk();

        $this->freshClient();
        $this->actingAs($admin)
            ->patch("/user-management/{$supervisor->id}/toggle-status")
            ->assertRedirect();

        $this->assertSame('inactive', $supervisor->fresh()->status);
        $this->assertSame(0, $supervisor->tokens()->count());

        $this->assertDeactivatedOnNextRequest($sessionId, $supervisor);
    }

    public function test_internship_assignment_deactivation_shows_the_message(): void
    {
        $coordinator = User::factory()->create(['role_id' => User::ROLE_COORDINATOR, 'status' => 'active']);
        $student = Student::factory()->create();
        $user = $student->user;
        $user->forceFill(['status' => 'active'])->save();
        $user->createToken('phone');

        $sessionId = $this->loginSession($user);

        $this->freshClient();
        $this->actingAs($coordinator)
            ->patch("/internship-assignment/{$student->id}/toggle-status")
            ->assertRedirect();

        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());

        $this->assertDeactivatedOnNextRequest($sessionId, $user);
    }

    public function test_a_deactivated_user_cannot_act_before_being_logged_out(): void
    {
        $student = Student::factory()->create();
        $user = $student->user;
        $user->forceFill(['status' => 'active'])->save();

        $sessionId = $this->loginSession($user);
        $user->forceFill(['status' => 'inactive'])->save();

        // A mutating request is refused (and ends the session) instead of
        // reaching the controller.
        $this->asSession($sessionId)->post('/my-attendance/time-in', [])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);

        $this->assertSame(0, DB::table('attendances')->where('student_id', $student->id)->count());
        $this->assertGuest('web');
    }

    public function test_role_or_password_change_still_ends_web_sessions_without_the_message(): void
    {
        $admin = User::factory()->create(['role_id' => User::ROLE_ADMIN, 'status' => 'active']);
        $supervisor = User::factory()->create(['role_id' => User::ROLE_SUPERVISOR, 'status' => 'active']);

        $sessionId = $this->loginSession($supervisor);

        $this->freshClient();
        $this->actingAs($admin)
            ->patch("/user-management/{$supervisor->id}", [
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'role_id' => User::ROLE_COORDINATOR,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $supervisor->id)->count());

        $this->asSession($sessionId)->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();
    }
}
