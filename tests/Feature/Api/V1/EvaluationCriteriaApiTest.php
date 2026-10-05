<?php

namespace Tests\Feature\Api\V1;

use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 12: Evaluation Criteria management through /api/v1
 * (Administrator only), sharing EvaluationCriteriaService with the website.
 */
class EvaluationCriteriaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function api(string $method, string $uri, ?User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    private function rated(EvaluationCriteria $criterion, int $rating = 4): EvaluationResponse
    {
        return EvaluationResponse::factory()->create([
            'evaluation_id' => Evaluation::factory()->submitted()->create()->id,
            'evaluation_criteria_id' => $criterion->id,
            'rating' => $rating,
        ]);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function endpoints(int $id): array
    {
        return [
            ['GET', '/api/v1/evaluation-criteria'],
            ['POST', '/api/v1/evaluation-criteria'],
            ['PATCH', "/api/v1/evaluation-criteria/{$id}"],
            ['POST', "/api/v1/evaluation-criteria/{$id}/activate"],
            ['POST', "/api/v1/evaluation-criteria/{$id}/deactivate"],
            ['DELETE', "/api/v1/evaluation-criteria/{$id}"],
        ];
    }

    public function test_only_administrators_manage_criteria(): void
    {
        $criterion = EvaluationCriteria::factory()->create();

        foreach ($this->endpoints($criterion->id) as [$method, $uri]) {
            $this->api($method, $uri, null)->assertUnauthorized();

            foreach ([1, 2, 3] as $role) {
                $this->api($method, $uri, $this->user($role), ['label' => 'X', 'category' => 'Y'])->assertForbidden();
            }
        }

        $this->assertSame(1, EvaluationCriteria::count());
        $this->assertTrue($criterion->fresh()->is_active);
    }

    public function test_the_list_has_every_criterion_with_its_usage_in_the_websites_order(): void
    {
        $b = EvaluationCriteria::factory()->create(['category' => 'B', 'sort_order' => 1]);
        $a2 = EvaluationCriteria::factory()->inactive()->create(['category' => 'A', 'sort_order' => 2]);
        $a1 = EvaluationCriteria::factory()->create(['category' => 'A', 'sort_order' => 1, 'description' => null]);
        $this->rated($a2);
        $this->rated($a2);

        $response = $this->api('GET', '/api/v1/evaluation-criteria', $this->user(4))->assertOk();

        $this->assertSame([$a1->id, $a2->id, $b->id], array_column($response->json('criteria'), 'id'));
        $this->assertSame([
            'id' => $a2->id, 'category' => 'A', 'label' => $a2->label, 'description' => $a2->description,
            'sort_order' => 2, 'is_active' => false, 'responses_count' => 2,
        ], $response->json('criteria.1'));
        $this->assertNull($response->json('criteria.0.description'));
    }

    public function test_create_update_activate_and_deactivate(): void
    {
        $admin = $this->user(4);

        $id = $this->api('POST', '/api/v1/evaluation-criteria', $admin, [
            'label' => 'Punctual', 'category' => 'Attendance', 'description' => 'On time', 'sort_order' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Evaluation criterion added.')
            ->assertJsonPath('criterion.is_active', true)
            ->assertJsonPath('criterion.sort_order', 3)
            ->assertJsonPath('criterion.responses_count', 0)
            ->json('criterion.id');

        $this->api('PATCH', "/api/v1/evaluation-criteria/{$id}", $admin, [
            'label' => 'Always punctual', 'category' => 'Attendance', 'description' => null,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Evaluation criterion updated.')
            ->assertJsonPath('criterion.label', 'Always punctual')
            ->assertJsonPath('criterion.description', null)
            ->assertJsonPath('criterion.sort_order', 3); // omitted = unchanged

        foreach (['deactivate' => false, 'activate' => true] as $action => $active) {
            // Idempotent: twice in a row leaves the same state.
            foreach ([1, 2] as $ignored) {
                $this->api('POST', "/api/v1/evaluation-criteria/{$id}/{$action}", $admin)
                    ->assertOk()
                    ->assertJsonPath('message', 'Evaluation criterion status updated.')
                    ->assertJsonPath('criterion.is_active', $active);
            }
        }
    }

    public function test_a_cleared_or_omitted_sort_order_is_zero(): void
    {
        $admin = $this->user(4);

        foreach ([null, ''] as $sortOrder) {
            $this->api('POST', '/api/v1/evaluation-criteria', $admin, ['label' => 'A', 'category' => 'C', 'sort_order' => $sortOrder])
                ->assertCreated()
                ->assertJsonPath('criterion.sort_order', 0);
        }

        $this->api('POST', '/api/v1/evaluation-criteria', $admin, ['label' => 'A', 'category' => 'C'])
            ->assertCreated()
            ->assertJsonPath('criterion.sort_order', 0);

        $criterion = EvaluationCriteria::factory()->create(['sort_order' => 7]);
        $this->api('PATCH', "/api/v1/evaluation-criteria/{$criterion->id}", $admin, ['label' => 'A', 'category' => 'C', 'sort_order' => null])
            ->assertOk()
            ->assertJsonPath('criterion.sort_order', 0);
    }

    public function test_the_website_stores_a_cleared_sort_order_as_zero_instead_of_failing(): void
    {
        $admin = $this->user(4);
        $criterion = EvaluationCriteria::factory()->create(['sort_order' => 7]);

        $this->actingAs($admin)->post('/evaluation-criteria', ['label' => 'Web', 'category' => 'C', 'sort_order' => ''])
            ->assertRedirect(route('evaluation-criteria.index'))
            ->assertSessionHas('success', 'Evaluation criterion added.');
        $this->assertSame(0, EvaluationCriteria::where('label', 'Web')->value('sort_order'));

        $this->actingAs($admin)->patch("/evaluation-criteria/{$criterion->id}", ['label' => 'Web 2', 'category' => 'C', 'sort_order' => ''])
            ->assertRedirect();
        $this->assertSame(0, $criterion->fresh()->sort_order);
    }

    public static function badBodies(): array
    {
        return [
            'label missing' => [['label' => null], 'label'],
            'label array' => [['label' => ['a']], 'label'],
            'label too long' => [['label' => str_repeat('a', 256)], 'label'],
            'category missing' => [['category' => ''], 'category'],
            'category number' => [['category' => 5], 'category'],
            'description too long' => [['description' => str_repeat('a', 2001)], 'description'],
            'sort order negative' => [['sort_order' => -1], 'sort_order'],
            'sort order fraction' => [['sort_order' => 1.5], 'sort_order'],
            'sort order text' => [['sort_order' => 'first'], 'sort_order'],
            'sort order boolean' => [['sort_order' => true], 'sort_order'],
            'sort order too big' => [['sort_order' => 2147483648], 'sort_order'],
        ];
    }

    #[DataProvider('badBodies')]
    public function test_bad_bodies_are_422_and_change_nothing(array $overrides, string $key): void
    {
        $admin = $this->user(4);
        $criterion = EvaluationCriteria::factory()->create();
        $before = $criterion->fresh()->toArray();
        $body = array_merge(['label' => 'Label', 'category' => 'Category', 'description' => 'D', 'sort_order' => 1], $overrides);

        $this->api('POST', '/api/v1/evaluation-criteria', $admin, $body)->assertStatus(422)->assertJsonValidationErrors($key);
        $this->api('PATCH', "/api/v1/evaluation-criteria/{$criterion->id}", $admin, $body)->assertStatus(422)->assertJsonValidationErrors($key);

        $this->assertSame(1, EvaluationCriteria::count());
        $this->assertSame($before, $criterion->fresh()->toArray());
    }

    public function test_invalid_utf8_is_422(): void
    {
        $admin = $this->user(4);
        $this->app['auth']->forgetGuards();

        $this->call('POST', '/api/v1/evaluation-criteria', [], [], [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken,
        ]), '{"label":"Bad '."\xC3\x28".'","category":"C"}')
            ->assertStatus(422)
            ->assertJsonValidationErrors('input');

        $this->assertSame(0, EvaluationCriteria::count());
    }

    public function test_the_maximum_sort_order_is_accepted(): void
    {
        $this->api('POST', '/api/v1/evaluation-criteria', $this->user(4), ['label' => 'A', 'category' => 'C', 'sort_order' => 2147483647])
            ->assertCreated()
            ->assertJsonPath('criterion.sort_order', 2147483647);
    }

    public function test_an_unused_criterion_is_deleted(): void
    {
        $criterion = EvaluationCriteria::factory()->create();

        $this->api('DELETE', "/api/v1/evaluation-criteria/{$criterion->id}", $this->user(4))
            ->assertOk()
            ->assertExactJson(['message' => 'Evaluation criterion deleted.', 'deleted' => true, 'criterion' => null]);

        $this->assertNull($criterion->fresh());
    }

    public function test_a_used_criterion_is_deactivated_and_its_ratings_kept(): void
    {
        $criterion = EvaluationCriteria::factory()->create();
        $response = $this->rated($criterion, 5);

        $this->api('DELETE', "/api/v1/evaluation-criteria/{$criterion->id}", $this->user(4))
            ->assertOk()
            ->assertJsonPath('message', 'This criterion has existing responses, so it was deactivated instead of deleted.')
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('criterion.id', $criterion->id)
            ->assertJsonPath('criterion.is_active', false)
            ->assertJsonPath('criterion.responses_count', 1);

        $this->assertFalse($criterion->fresh()->is_active);
        $this->assertSame(5, $response->fresh()->rating);
        $this->assertSame($criterion->id, $response->fresh()->evaluation_criteria_id);
    }

    public function test_a_rating_saved_while_deleting_turns_the_delete_into_a_deactivation(): void
    {
        $criterion = EvaluationCriteria::factory()->create();

        // A rating lands between the request's read and the locked re-read.
        $landed = false;
        DB::listen(function ($query) use (&$landed, $criterion) {
            if ($landed || ! str_contains($query->sql, 'from `evaluation_criteria`')) {
                return;
            }

            $landed = true;
            $this->rated($criterion);
        });

        $this->api('DELETE', "/api/v1/evaluation-criteria/{$criterion->id}", $this->user(4))
            ->assertOk()
            ->assertJsonPath('deleted', false);

        $this->assertTrue($landed);
        $this->assertFalse($criterion->fresh()->is_active);
    }

    public function test_unknown_and_malformed_ids_are_404(): void
    {
        $admin = $this->user(4);

        foreach (['999999', '0', 'abc', '99999999999999999999'] as $id) {
            $this->api('PATCH', "/api/v1/evaluation-criteria/{$id}", $admin, ['label' => 'A', 'category' => 'C'])->assertNotFound();
            $this->api('POST', "/api/v1/evaluation-criteria/{$id}/activate", $admin)->assertNotFound();
            $this->api('DELETE', "/api/v1/evaluation-criteria/{$id}", $admin)->assertNotFound();
        }
    }

    public function test_api_and_website_create_identical_criteria(): void
    {
        $admin = $this->user(4);
        $body = ['label' => 'Same', 'category' => 'Cat', 'description' => 'Desc', 'sort_order' => 4];

        $apiId = $this->api('POST', '/api/v1/evaluation-criteria', $admin, $body)->assertCreated()->json('criterion.id');
        $this->actingAs($admin)->post('/evaluation-criteria', $body)->assertRedirect();

        $strip = fn (EvaluationCriteria $criterion) => collect($criterion->fresh()->toArray())->except(['id', 'created_at', 'updated_at'])->all();
        $this->assertSame($strip(EvaluationCriteria::find($apiId)), $strip(EvaluationCriteria::whereKeyNot($apiId)->first()));
    }
}
