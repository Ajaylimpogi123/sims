<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Input that isn't valid UTF-8 is a 422 on every /api route, never a 500
 * from MySQL ("Incorrect string value") or from json_encode while
 * rendering the error.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        User::factory()->create(['role_id' => 1, 'status' => 'active', 'email' => 'juan@example.com']);
    }

    /** Form-encoded login, as PHP decodes e.g. device_name=%FF%FEabc. */
    private function formLogin(array $overrides): TestResponse
    {
        return $this->post('/api/v1/login', array_merge([
            'email' => 'juan@example.com',
            'password' => 'password',
            'device_name' => 'Pixel',
        ], $overrides), ['Accept' => 'application/json']);
    }

    public function test_malformed_device_name_is_a_422(): void
    {
        $response = $this->formLogin(['device_name' => "\xFF\xFEabc"]);

        $response->assertStatus(422)
            ->assertExactJson([
                'message' => 'The device name field must be valid UTF-8 text.',
                'errors' => ['device_name' => ['The device name field must be valid UTF-8 text.']],
            ]);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_malformed_email_and_password_are_422(): void
    {
        $this->formLogin(['email' => "juan\xC3\x28@example.com"])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->formLogin(['password' => "pass\xFF"])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_malformed_nested_values_and_keys_are_422(): void
    {
        $this->formLogin(['meta' => ['a' => "\xFF"]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meta.a']);

        $this->formLogin(["\xFF" => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['input']);
    }

    public function test_malformed_query_string_on_an_authenticated_route_is_422(): void
    {
        $token = User::first()->createToken('phone')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me?q=%FF')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_an_undecodable_json_body_is_422_not_an_empty_request(): void
    {
        foreach (["{\"email\":\"juan@example.com\",\"device_name\":\"P\xC3\x28\"}", '{"email":'] as $body) {
            $this->call('POST', '/api/v1/login', [], [], [], $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]), $body)
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => 'The request body is not valid JSON.',
                    'errors' => ['input' => ['The request body is not valid JSON.']],
                ]);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_valid_non_ascii_text_still_works(): void
    {
        $this->formLogin(['device_name' => 'Galaxy ñ 日本'])->assertOk();

        $this->assertSame('Galaxy ñ 日本', PersonalAccessToken::first()->name);
    }
}
