<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Login brute-force limits (mobile API and website) can't be dodged by
 * varying client-controlled input: X-Forwarded-For, the shape of `email`,
 * or invisible / case / width variants of the address that the database
 * collation still matches to the same account.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** A client connecting directly (not through a trusted proxy). */
    private const CLIENT_IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        User::factory()->create(['role_id' => 1, 'status' => 'active', 'email' => 'juan@example.com']);
    }

    private function apiLogin(mixed $email, string $password = 'wrong', array $server = []): TestResponse
    {
        return $this->withServerVariables(array_merge(['REMOTE_ADDR' => self::CLIENT_IP], $server))
            ->postJson('/api/v1/login', [
                'email' => $email,
                'password' => $password,
                'device_name' => 'Pixel',
            ]);
    }

    // ------------------------------------------------- X-Forwarded-For spoof

    public function test_spoofed_x_forwarded_for_does_not_reset_the_api_login_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->apiLogin('juan@example.com', 'wrong', ['HTTP_X_FORWARDED_FOR' => "198.51.100.{$i}"])
                ->assertStatus(422);
        }

        $this->apiLogin('juan@example.com', 'password', ['HTTP_X_FORWARDED_FOR' => '198.51.100.77'])
            ->assertStatus(429);
    }

    public function test_spoofed_x_forwarded_for_does_not_reset_the_web_login_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_X_FORWARDED_FOR' => "198.51.100.{$i}"])
                ->post('/login', ['email' => 'juan@example.com', 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_X_FORWARDED_FOR' => '198.51.100.77'])
            ->post('/login', ['email' => 'juan@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_x_forwarded_headers_from_an_untrusted_client_are_ignored(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => self::CLIENT_IP,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/up');

        $this->assertSame(self::CLIENT_IP, request()->ip());
        $this->assertFalse(request()->isSecure());
    }

    public function test_a_trusted_local_proxy_like_ngrok_still_forwards_client_ip_and_https(): void
    {
        // ngrok / nginx on the same machine connect from loopback.
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'abc.ngrok-free.app',
        ])->get('/up');

        $this->assertSame('198.51.100.1', request()->ip());
        $this->assertTrue(request()->isSecure());
        $this->assertSame('https://abc.ngrok-free.app', request()->getSchemeAndHttpHost());
    }

    public function test_trusted_proxies_can_be_configured(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '10.1.2.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ])->get('/up');

        $this->assertSame('198.51.100.1', request()->ip());
    }

    // ---------------------------------------------------- non-string email

    public function test_email_sent_as_an_array_is_a_422_not_a_500(): void
    {
        $response = $this->apiLogin(['juan@example.com'], 'password');

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertStringNotContainsString('Array to string', $response->getContent());
    }

    public function test_other_non_string_emails_are_a_422(): void
    {
        foreach ([['a' => ['b']], 12345, true] as $email) {
            $this->apiLogin($email, 'password')->assertStatus(422)->assertJsonValidationErrors(['email']);
        }
    }

    // --------------------------------------------- variants of one address

    public function test_invisible_case_and_whitespace_variants_share_the_api_limit(): void
    {
        $variants = [
            'juan@example.com',
            "ju\u{200B}an@example.com",   // zero-width space
            "juan\u{200D}@example.com",   // zero-width joiner
            "\u{FEFF}JUAN@example.com",   // BOM + upper case
            "juan@exa\u{00AD}mple.com",   // soft hyphen
            ' Juan@Example.COM ',
        ];

        foreach (array_slice($variants, 0, 5) as $email) {
            $this->apiLogin($email)->assertStatus(422);
        }

        $this->apiLogin($variants[5], 'password')->assertStatus(429);
    }

    public function test_variants_the_database_matches_to_the_account_share_the_limit(): void
    {
        // Whatever MySQL's collation treats as the same address must land in
        // the same bucket, e.g. accent / width variants.
        foreach (['juán@example.com', 'ｊuan@example.com', 'JUAN@EXAMPLE.COM', "jua\u{0301}n@example.com", 'juan@example.com'] as $email) {
            $this->apiLogin($email);
        }

        $this->apiLogin('juan@example.com', 'password')->assertStatus(429);
    }

    public function test_another_account_from_the_same_ip_is_not_affected(): void
    {
        User::factory()->create(['role_id' => 1, 'status' => 'active', 'email' => 'maria@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->apiLogin('juan@example.com')->assertStatus(422);
        }

        $this->apiLogin('maria@example.com', 'password')->assertOk();
    }
}
