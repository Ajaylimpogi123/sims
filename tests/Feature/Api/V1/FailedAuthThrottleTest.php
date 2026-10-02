<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Requests to authenticated API routes that end in 401 (missing, invalid
 * or revoked token) are limited per IP *before* auth:sanctum runs, so a
 * client can't spray token guesses (each one a DB lookup) without limit.
 */
class FailedAuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function me(?string $token, string $ip = '203.0.113.9'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('GET', '/api/v1/me', [], [], [], $server);
    }

    public function test_bad_tokens_from_one_ip_are_rate_limited_after_sixty_a_minute(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->me("{$i}|garbage")->assertUnauthorized();
        }

        $this->me('61|garbage')
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('Retry-After');

        $this->me(null)->assertStatus(429);
    }

    public function test_other_ips_are_not_affected(): void
    {
        for ($i = 1; $i <= 61; $i++) {
            $this->me("{$i}|garbage");
        }

        $token = User::factory()->create(['role_id' => 1, 'status' => 'active'])->createToken('phone')->plainTextToken;

        $this->me($token, '198.51.100.5')->assertOk();
        $this->me('1|garbage', '198.51.100.5')->assertUnauthorized();
    }

    public function test_successful_requests_do_not_count(): void
    {
        $token = User::factory()->create(['role_id' => 1, 'status' => 'active'])->createToken('phone')->plainTextToken;

        for ($i = 0; $i < 70; $i++) {
            $this->me($token)->assertOk();
        }

        $this->me('1|garbage')->assertUnauthorized();
    }
}
