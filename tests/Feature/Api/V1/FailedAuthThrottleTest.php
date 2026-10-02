<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Requests to authenticated API routes with a token that can't be real
 * (missing, malformed, unknown/revoked id, wrong secret) are limited per
 * IP. A real token is always checked and never throttled because of
 * someone else's junk on the same (NAT / campus Wi-Fi) IP.
 */
class FailedAuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function me(?string $token, string $ip = self::SHARED_IP): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('GET', '/api/v1/me', [], [], [], $server);
    }

    /** Same shape (and checksum) as a Sanctum token, but not issued. */
    private function wellFormedFake(int $id = 999999): string
    {
        $entropy = Str::random(40);

        return $id.'|'.$entropy.hash('crc32b', $entropy);
    }

    private function validToken(): string
    {
        return User::factory()->create(['role_id' => 1, 'status' => 'active'])->createToken('phone')->plainTextToken;
    }

    private function exhaustLimit(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->me("{$i}|garbage")->assertUnauthorized();
        }
    }

    public function test_junk_tokens_from_one_ip_are_rate_limited_after_sixty_a_minute(): void
    {
        $this->exhaustLimit();

        $this->me('61|garbage')
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('Retry-After');

        $this->me(null)->assertStatus(429);
        $this->me('no-pipe-token')->assertStatus(429);
    }

    public function test_a_valid_token_on_a_flooded_shared_ip_still_works(): void
    {
        $token = $this->validToken();

        $this->exhaustLimit();
        $this->me('1|garbage')->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->me($token)->assertOk();
        }
    }

    public function test_malformed_junk_over_the_limit_never_reaches_the_token_table(): void
    {
        $this->exhaustLimit();

        $entropy = Str::random(40);
        $junk = [
            null,
            'garbage',
            '1|garbage',
            'abc|'.$entropy.hash('crc32b', $entropy),
            '1|'.$entropy.'00000000',               // bad checksum
            '1|'.$entropy.hash('crc32b', $entropy).'x',
        ];

        DB::enableQueryLog();

        foreach ($junk as $token) {
            $this->me($token)->assertStatus(429);
        }

        $tokenQueries = array_filter(
            DB::getQueryLog(),
            fn (array $query) => str_contains($query['query'], 'personal_access_tokens'),
        );
        $this->assertSame([], array_values($tokenQueries));
    }

    public function test_well_formed_fakes_count_and_are_refused_over_the_limit(): void
    {
        $user = User::factory()->create(['role_id' => 1, 'status' => 'active']);
        $real = $user->createToken('phone');
        $wrongSecret = $real->accessToken->id.'|'.substr($this->wellFormedFake(), strlen('999999|'));

        // Under the limit a fake is a plain 401, and it counts.
        $this->me($this->wellFormedFake())->assertUnauthorized();
        $this->me($wrongSecret)->assertUnauthorized();

        for ($i = 0; $i < 58; $i++) {
            $this->me($this->wellFormedFake())->assertUnauthorized();
        }

        // Over the limit: unknown id, wrong secret and revoked tokens are 429.
        $this->me($this->wellFormedFake())->assertStatus(429)->assertHeader('Retry-After');
        $this->me($wrongSecret)->assertStatus(429);

        $revoked = $user->createToken('old-phone')->plainTextToken;
        $user->tokens()->where('name', 'old-phone')->delete();
        $this->me($revoked)->assertStatus(429);

        // The real token is still fine.
        $this->me($real->plainTextToken)->assertOk();
    }

    public function test_other_ips_are_not_affected(): void
    {
        $this->exhaustLimit();

        $this->me($this->validToken(), '198.51.100.5')->assertOk();
        $this->me('1|garbage', '198.51.100.5')->assertUnauthorized();
    }

    public function test_successful_requests_do_not_count(): void
    {
        $token = $this->validToken();

        for ($i = 0; $i < 70; $i++) {
            $this->me($token)->assertOk();
        }

        $this->me('1|garbage')->assertUnauthorized();
    }
}
