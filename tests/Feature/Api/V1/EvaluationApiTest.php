<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\Student;
use App\Models\User;
use App\Services\EvaluationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 12: Supervisor Evaluations through /api/v1. Coordinator views
 * everything; Supervisor works on own students' evaluations only;
 * Administrator works on every evaluation and locks / reopens. Shares the
 * website's list scope (Evaluation::visibleTo), EvaluationPolicy and
 * EvaluationService.
 */
class EvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    private int $month = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    // ---------------------------------------------------------- helpers

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function student(?User $supervisor, array $overrides = []): Student
    {
        return Student::factory()->create(array_merge([
            'user_id' => $this->user(1)->id,
            'supervisor_id' => $supervisor?->id,
            'company_id' => Company::factory()->create()->id,
        ], $overrides));
    }

    /**
     * An evaluation, each call one month earlier than the previous one.
     */
    private function evaluation(Student $student, string $status = 'draft', array $attributes = []): Evaluation
    {
        $start = now()->startOfMonth()->subMonths($this->month++);

        return Evaluation::factory()->create(array_merge([
            'student_id' => $student->id,
            'company_id' => $student->company_id,
            'supervisor_id' => $student->supervisor_id,
            'evaluation_period_start' => $start->toDateString(),
            'evaluation_period_end' => $start->copy()->endOfMonth()->toDateString(),
            'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
            'locked_at' => $status === 'locked' ? now() : null,
        ], $attributes));
    }

    /**
     * @return list<EvaluationCriteria>
     */
    private function criteria(int $count = 2): array
    {
        return EvaluationCriteria::factory()->count($count)->create()->all();
    }

    /**
     * @param  list<EvaluationCriteria>  $criteria
     */
    private function ratings(array $criteria, int $rating = 4): array
    {
        return array_map(fn (EvaluationCriteria $criterion) => [
            'evaluation_criteria_id' => $criterion->id,
            'rating' => $rating,
            'comment' => 'Good.',
        ], $criteria);
    }

    private function body(array $criteria = [], array $overrides = []): array
    {
        return array_merge([
            'evaluation_period_start' => '2026-08-01',
            'evaluation_period_end' => '2026-08-31',
            'strengths' => 'Reliable',
            'areas_for_improvement' => 'Speed',
            'recommendations' => 'Hire',
            'supervisor_remarks' => 'Well done',
            'responses' => $this->ratings($criteria),
        ], $overrides);
    }

    private function api(string $method, string $uri, ?User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        return $method === 'GET'
            ? $this->withHeaders($headers)->get($uri.($data ? '?'.http_build_query($data) : ''))
            : $this->withHeaders($headers)->json($method, $uri, $data);
    }

    private function raw(string $method, string $uri, User $user, string $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ]), $body);
    }

    /**
     * @return list<int>
     */
    private function listIds(User $user, array $query = []): array
    {
        $ids = [];
        $cursor = null;

        do {
            $response = $this->api('GET', '/api/v1/evaluations', $user, array_filter([
                ...$query, 'per_page' => 2, 'cursor' => $cursor,
            ], fn ($value) => $value !== null))->assertOk();

            $ids = [...$ids, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('meta.next_cursor');
        } while ($response->json('meta.has_more'));

        return $ids;
    }

    /**
     * Runs $competitor right after the request's first read of the
     * evaluation, i.e. between the policy check and the service's locked
     * re-read.
     */
    private function landBetweenCheckAndLock(callable $competitor): \Closure
    {
        $landed = false;

        DB::listen(function ($query) use (&$landed, $competitor) {
            if ($landed || ! str_contains($query->sql, 'from `evaluations`')) {
                return;
            }

            $landed = true;
            $competitor();
        });

        return function () use (&$landed) {
            $this->assertTrue($landed, 'The competing change never ran.');
        };
    }

    // ---------------------------------------------------------- access

    public function test_every_endpoint_requires_a_token(): void
    {
        foreach ([
            ['GET', '/api/v1/evaluations'], ['GET', '/api/v1/evaluations/1'], ['GET', '/api/v1/evaluations/criteria'],
            ['GET', '/api/v1/evaluations/students'], ['POST', '/api/v1/evaluations'], ['PATCH', '/api/v1/evaluations/1'],
            ['POST', '/api/v1/evaluations/1/submit'], ['POST', '/api/v1/evaluations/1/lock'], ['POST', '/api/v1/evaluations/1/reopen'],
        ] as [$method, $uri]) {
            $this->api($method, $uri, null)->assertUnauthorized();
        }
    }

    public function test_students_get_403_everywhere(): void
    {
        $student = $this->student(null);
        $evaluation = $this->evaluation($student, 'submitted');
        $user = $student->user;

        foreach ([
            ['GET', '/api/v1/evaluations'], ['GET', "/api/v1/evaluations/{$evaluation->id}"], ['GET', '/api/v1/evaluations/criteria'],
            ['GET', '/api/v1/evaluations/students'], ['POST', '/api/v1/evaluations'], ['PATCH', "/api/v1/evaluations/{$evaluation->id}"],
            ['POST', "/api/v1/evaluations/{$evaluation->id}/submit"], ['POST', "/api/v1/evaluations/{$evaluation->id}/lock"],
            ['POST', "/api/v1/evaluations/{$evaluation->id}/reopen"],
        ] as [$method, $uri]) {
            $this->api($method, $uri, $user)->assertForbidden();
        }
    }

    public function test_coordinators_see_everything_and_change_nothing(): void
    {
        $coordinator = $this->user(2);
        $criteria = $this->criteria();
        $draft = $this->evaluation($this->student($this->user(3)));
        $submitted = $this->evaluation($this->student(null), 'submitted');

        $this->api('GET', '/api/v1/evaluations', $coordinator)
            ->assertOk()
            ->assertJsonPath('can_create', false)
            ->assertJsonCount(2, 'data');

        $this->api('GET', "/api/v1/evaluations/{$draft->id}", $coordinator)
            ->assertOk()
            ->assertJsonPath('evaluation.can_edit', false)
            ->assertJsonPath('evaluation.can_submit', false)
            ->assertJsonPath('evaluation.can_lock', false)
            ->assertJsonPath('evaluation.can_reopen', false);

        $this->api('GET', '/api/v1/evaluations/criteria', $coordinator)->assertOk();

        $this->api('GET', '/api/v1/evaluations/students', $coordinator)->assertForbidden();
        $this->api('POST', '/api/v1/evaluations', $coordinator, $this->body($criteria, ['student_id' => $draft->student_id]))->assertForbidden();
        $this->api('PATCH', "/api/v1/evaluations/{$draft->id}", $coordinator, $this->body($criteria))->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$draft->id}/submit", $coordinator)->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$submitted->id}/lock", $coordinator)->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$submitted->id}/reopen", $coordinator)->assertForbidden();

        $this->assertSame(2, Evaluation::count());
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('submitted', $submitted->fresh()->status);
    }

    public function test_a_supervisor_sees_and_changes_own_students_evaluations_only(): void
    {
        $supervisor = $this->user(3);
        $criteria = $this->criteria();
        $own = $this->evaluation($this->student($supervisor));
        $othersDraft = $this->evaluation($this->student($this->user(3)));
        $othersSubmitted = $this->evaluation($this->student($this->user(3)), 'submitted');
        $unassigned = $this->evaluation($this->student(null));

        $this->assertSame([$own->id], $this->listIds($supervisor));
        $this->api('GET', '/api/v1/evaluations', $supervisor)->assertJsonPath('can_create', true);

        $this->api('GET', "/api/v1/evaluations/{$own->id}", $supervisor)
            ->assertOk()
            ->assertJsonPath('evaluation.can_edit', true)
            ->assertJsonPath('evaluation.can_submit', true)
            ->assertJsonPath('evaluation.can_lock', false)
            ->assertJsonPath('evaluation.can_reopen', false);

        foreach ([$othersDraft, $unassigned] as $evaluation) {
            $this->api('GET', "/api/v1/evaluations/{$evaluation->id}", $supervisor)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->api('PATCH', "/api/v1/evaluations/{$evaluation->id}", $supervisor, $this->body($criteria))->assertNotFound();
            $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/submit", $supervisor)->assertNotFound();
        }

        // Lock / reopen are Administrator-only, even for own students.
        $ownSubmitted = $this->evaluation($own->student, 'submitted');
        $this->api('POST', "/api/v1/evaluations/{$ownSubmitted->id}/lock", $supervisor)->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$ownSubmitted->id}/reopen", $supervisor)->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$othersSubmitted->id}/lock", $supervisor)->assertForbidden();

        $this->assertSame('draft', $othersDraft->fresh()->status);
        $this->assertSame('submitted', $ownSubmitted->fresh()->status);
    }

    public function test_supervisor_scope_follows_the_students_current_assignment(): void
    {
        $old = $this->user(3);
        $new = $this->user(3);
        $student = $this->student($old);
        $evaluation = $this->evaluation($student);

        $student->update(['supervisor_id' => $new->id]);

        $this->api('GET', "/api/v1/evaluations/{$evaluation->id}", $old)->assertNotFound();
        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/submit", $old)->assertNotFound();
        $this->api('GET', "/api/v1/evaluations/{$evaluation->id}", $new)->assertOk()
            ->assertJsonPath('evaluation.supervisor.id', $old->id); // the snapshot stays
    }

    public function test_a_student_id_filter_never_widens_a_supervisors_scope(): void
    {
        $supervisor = $this->user(3);
        $this->evaluation($this->student($supervisor));
        $other = $this->evaluation($this->student($this->user(3)));

        $this->api('GET', '/api/v1/evaluations', $supervisor, ['student_id' => $other->student_id])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unknown_malformed_and_oversized_ids_are_404(): void
    {
        $admin = $this->user(4);

        foreach (['999999', '0', 'abc', '1.5', '-1', '99999999999999999999'] as $id) {
            $this->api('GET', "/api/v1/evaluations/{$id}", $admin)->assertNotFound();
            $this->api('POST', "/api/v1/evaluations/{$id}/lock", $admin)->assertNotFound();
        }
    }

    // ---------------------------------------------------------- reads

    public function test_items_carry_the_evaluation_object_and_detail(): void
    {
        $admin = $this->user(4);
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        [$a, $b] = $this->criteria();
        $evaluation = app(EvaluationService::class)->createDraft($student, $this->body([$a, $b], [
            'responses' => [
                ['evaluation_criteria_id' => $a->id, 'rating' => 5, 'comment' => 'Great'],
                ['evaluation_criteria_id' => $b->id, 'rating' => 2, 'comment' => null],
            ],
        ]));
        $evaluation->update(['status' => 'locked', 'submitted_at' => now(), 'locked_at' => now(), 'locked_by' => $admin->id]);

        $row = $this->api('GET', '/api/v1/evaluations', $admin)->assertOk()->json('data.0');

        $this->assertSame([
            'id', 'status', 'period_start', 'period_end', 'overall_rating', 'rating_max', 'student', 'company',
            'supervisor', 'submitted_at', 'locked_at', 'locked_by', 'created_at', 'updated_at',
            'can_edit', 'can_submit', 'can_lock', 'can_reopen',
        ], array_keys($row));
        $this->assertSame('locked', $row['status']);
        $this->assertSame('2026-08-01', $row['period_start']);
        $this->assertSame(3.5, $row['overall_rating']);
        $this->assertSame(5, $row['rating_max']);
        $this->assertSame([
            'id' => $student->id, 'user_id' => $student->user_id,
            'name' => $student->user->name, 'student_number' => $student->student_number,
        ], $row['student']);
        $this->assertSame(['id' => $student->company_id, 'name' => $student->company->company_name], $row['company']);
        $this->assertSame(['id' => $supervisor->id, 'name' => $supervisor->name], $row['supervisor']);
        $this->assertSame(['id' => $admin->id, 'name' => $admin->name], $row['locked_by']);
        $this->assertFalse($row['can_lock']);
        $this->assertTrue($row['can_reopen']);

        $detail = $this->api('GET', "/api/v1/evaluations/{$evaluation->id}", $admin)->assertOk()->json('evaluation');

        $this->assertSame('Reliable', $detail['strengths']);
        $this->assertSame('Speed', $detail['areas_for_improvement']);
        $this->assertSame('Hire', $detail['recommendations']);
        $this->assertSame('Well done', $detail['supervisor_remarks']);
        $this->assertEqualsCanonicalizing([
            ['evaluation_criteria_id' => $a->id, 'rating' => 5, 'comment' => 'Great'],
            ['evaluation_criteria_id' => $b->id, 'rating' => 2, 'comment' => null],
        ], $detail['responses']);
        $flat = collect($detail['categories'])->flatMap(fn ($group) => $group['criteria'])->keyBy('id');
        $this->assertSame(['id', 'label', 'description', 'is_active', 'rating', 'max', 'comment'], array_keys($flat[$a->id]));
        $this->assertSame(5, $flat[$a->id]['rating']);
        $this->assertTrue($flat[$a->id]['is_active']);
    }

    public function test_list_is_newest_period_first_and_paginates_without_repeats(): void
    {
        $admin = $this->user(4);
        $student = $this->student(null);
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->evaluation($student)->id;
        }

        // Same period as the newest: id breaks the tie.
        $twin = $this->evaluation($student, 'draft', [
            'evaluation_period_start' => Evaluation::find($ids[0])->evaluation_period_start->toDateString(),
        ]);

        $this->assertSame([$twin->id, ...$ids], $this->listIds($admin));
    }

    public function test_list_matches_the_website_list_for_every_staff_role(): void
    {
        $supervisor = $this->user(3);
        $this->evaluation($this->student($supervisor));
        $this->evaluation($this->student($supervisor), 'submitted');
        $this->evaluation($this->student($this->user(3)), 'locked');
        $this->evaluation($this->student(null));

        foreach ([$supervisor, $this->user(2), $this->user(4)] as $user) {
            $webIds = [];
            $this->actingAs($user)->get('/supervisor-evaluations')->assertInertia(function (Assert $page) use (&$webIds) {
                $webIds = array_column($page->toArray()['props']['evaluations'], 'id');
            });

            $this->assertSame($webIds, $this->listIds($user));
        }
    }

    public function test_list_filters_by_status_student_and_search(): void
    {
        $admin = $this->user(4);
        $maria = $this->student(null, ['student_number' => '2023-0001']);
        $maria->user->update(['name' => 'Maria 100% Santos']);
        $juan = $this->student(null, ['student_number' => '2023-0002']);
        $juan->user->update(['name' => 'Juan Cruz']);

        $mariaDraft = $this->evaluation($maria);
        $mariaSubmitted = $this->evaluation($maria, 'submitted');
        $juanLocked = $this->evaluation($juan, 'locked');

        $this->assertSame([$mariaSubmitted->id], $this->listIds($admin, ['status' => 'submitted']));
        $this->assertSame([$juanLocked->id], $this->listIds($admin, ['student_id' => $juan->id]));
        $this->assertSame([$mariaDraft->id, $mariaSubmitted->id], $this->listIds($admin, ['search' => '100%']));
        $this->assertSame([], $this->listIds($admin, ['search' => '1000%']));
        $this->assertSame([$juanLocked->id], $this->listIds($admin, ['search' => '-0002']));
        $this->assertSame([$mariaDraft->id, $mariaSubmitted->id, $juanLocked->id], $this->listIds($admin, ['search' => '  ']));
    }

    public static function badQueries(): array
    {
        return [
            'unknown status' => [['status' => 'pending'], 'status'],
            'status array' => [['status' => ['draft']], 'status'],
            'student id text' => [['student_id' => 'abc'], 'student_id'],
            'student id zero' => [['student_id' => 0], 'student_id'],
            'search too long' => [['search' => str_repeat('a', 256)], 'search'],
            'search invalid utf8' => [['search' => "\xB1"], 'search'],
            'per page too big' => [['per_page' => 51], 'per_page'],
            'tampered cursor' => [['cursor' => 'eyJmb28iOjF9'], 'cursor'],
            'report cursor' => [['cursor' => rtrim(strtr(base64_encode(json_encode(['status' => 'pending', 'period_start' => '2026-01-01 00:00:00', 'id' => 1, '_pointsToNextItems' => true])), '+/', '-_'), '=')], 'cursor'],
        ];
    }

    #[DataProvider('badQueries')]
    public function test_bad_list_queries_are_422(array $query, string $key): void
    {
        $this->api('GET', '/api/v1/evaluations', $this->user(4), $query)
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
    }

    public function test_criteria_are_the_active_ones_grouped_by_category_in_the_websites_order(): void
    {
        $b2 = EvaluationCriteria::factory()->create(['category' => 'B', 'sort_order' => 2]);
        $a1 = EvaluationCriteria::factory()->create(['category' => 'A', 'sort_order' => 1]);
        $b1 = EvaluationCriteria::factory()->create(['category' => 'B', 'sort_order' => 1]);
        EvaluationCriteria::factory()->inactive()->create(['category' => 'A', 'sort_order' => 0]);

        $response = $this->api('GET', '/api/v1/evaluations/criteria', $this->user(3))->assertOk();

        $response->assertJsonPath('rating_max', 5);
        $this->assertSame(['A', 'B'], array_column($response->json('categories'), 'category'));
        $this->assertSame([$a1->id], array_column($response->json('categories.0.criteria'), 'id'));
        $this->assertSame([$b1->id, $b2->id], array_column($response->json('categories.1.criteria'), 'id'));
        $this->assertSame(['id', 'label', 'description', 'sort_order'], array_keys($response->json('categories.0.criteria.0')));

        $webIds = [];
        $this->actingAs($this->user(4))->get('/supervisor-evaluations')->assertInertia(function (Assert $page) use (&$webIds) {
            $webIds = array_column($page->toArray()['props']['criteria'], 'id');
        });
        $this->assertSame($webIds, [$a1->id, $b1->id, $b2->id]);
    }

    public function test_the_student_picker_lists_who_the_caller_may_evaluate(): void
    {
        $supervisor = $this->user(3);
        $own = $this->student($supervisor);
        $other = $this->student($this->user(3));
        $unassigned = $this->student(null, ['company_id' => null]);

        $mine = $this->api('GET', '/api/v1/evaluations/students', $supervisor)->assertOk();
        $this->assertSame([$own->id], array_column($mine->json('students'), 'id'));
        $this->assertSame(['id', 'user_id', 'name', 'student_number', 'company'], array_keys($mine->json('students.0')));

        $all = $this->api('GET', '/api/v1/evaluations/students', $this->user(4))->assertOk();
        $this->assertEqualsCanonicalizing([$own->id, $other->id, $unassigned->id], array_column($all->json('students'), 'id'));
        $this->assertNull(collect($all->json('students'))->firstWhere('id', $unassigned->id)['company']);
    }

    // ---------------------------------------------------------- lifecycle

    public function test_a_supervisor_creates_edits_and_submits_and_an_admin_locks_and_reopens(): void
    {
        $supervisor = $this->user(3);
        $admin = $this->user(4);
        $student = $this->student($supervisor);
        [$a, $b] = $this->criteria();

        $created = $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body([$a], ['student_id' => $student->id]))
            ->assertCreated()
            ->assertJsonPath('message', 'Evaluation draft saved.')
            ->assertJsonPath('evaluation.status', 'draft')
            ->assertJsonPath('evaluation.overall_rating', 4)
            ->assertJsonPath('evaluation.supervisor.id', $supervisor->id)
            ->assertJsonPath('evaluation.company.id', $student->company_id)
            ->assertJsonPath('evaluation.can_edit', true);
        $id = $created->json('evaluation.id');

        // Not every active criterion is rated yet.
        $this->api('POST', "/api/v1/evaluations/{$id}/submit", $supervisor)
            ->assertStatus(422)
            ->assertJsonPath('errors.responses.0', EvaluationService::UNRATED_MESSAGE);
        $this->assertSame('draft', Evaluation::find($id)->status);

        $this->api('PATCH', "/api/v1/evaluations/{$id}", $supervisor, $this->body([], [
            'strengths' => 'Even better',
            'responses' => [
                ['evaluation_criteria_id' => $a->id, 'rating' => 5],
                ['evaluation_criteria_id' => $b->id, 'rating' => 2, 'comment' => 'Late twice'],
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Evaluation draft updated.')
            ->assertJsonPath('evaluation.strengths', 'Even better')
            ->assertJsonPath('evaluation.overall_rating', 3.5)
            ->assertJsonCount(2, 'evaluation.responses');

        $this->api('POST', "/api/v1/evaluations/{$id}/submit", $supervisor)
            ->assertOk()
            ->assertJsonPath('message', 'Evaluation submitted.')
            ->assertJsonPath('evaluation.status', 'submitted')
            ->assertJsonPath('evaluation.can_edit', false)
            ->assertJsonPath('evaluation.can_submit', false);
        $this->assertNotNull(Evaluation::find($id)->submitted_at);

        $this->api('GET', "/api/v1/evaluations/{$id}", $admin)
            ->assertJsonPath('evaluation.can_lock', true)
            ->assertJsonPath('evaluation.can_reopen', true);

        $this->api('POST', "/api/v1/evaluations/{$id}/lock", $admin)
            ->assertOk()
            ->assertJsonPath('message', 'Evaluation locked.')
            ->assertJsonPath('evaluation.status', 'locked')
            ->assertJsonPath('evaluation.locked_by.id', $admin->id)
            ->assertJsonPath('evaluation.can_lock', false)
            ->assertJsonPath('evaluation.can_reopen', true);

        $this->api('POST', "/api/v1/evaluations/{$id}/reopen", $admin)
            ->assertOk()
            ->assertJsonPath('message', 'Evaluation reopened for editing.')
            ->assertJsonPath('evaluation.status', 'draft')
            ->assertJsonPath('evaluation.submitted_at', null)
            ->assertJsonPath('evaluation.locked_at', null)
            ->assertJsonPath('evaluation.locked_by', null)
            ->assertJsonPath('evaluation.can_edit', true);

        $this->api('GET', "/api/v1/evaluations/{$id}", $supervisor)->assertJsonPath('evaluation.can_submit', true);
    }

    public function test_an_admin_can_create_and_edit_for_any_student(): void
    {
        $admin = $this->user(4);
        $student = $this->student($this->user(3));
        $criteria = $this->criteria(1);

        $id = $this->api('POST', '/api/v1/evaluations', $admin, $this->body($criteria, ['student_id' => $student->id]))
            ->assertCreated()
            ->json('evaluation.id');

        $this->api('PATCH', "/api/v1/evaluations/{$id}", $admin, $this->body($criteria, ['strengths' => 'Admin edit']))
            ->assertOk();
        $this->api('POST', "/api/v1/evaluations/{$id}/submit", $admin)->assertOk();

        $this->assertSame('submitted', Evaluation::find($id)->status);
    }

    public function test_a_student_the_caller_may_not_evaluate_is_a_422_like_an_unknown_one(): void
    {
        $supervisor = $this->user(3);
        $criteria = $this->criteria(1);
        $others = $this->student($this->user(3));
        $unassigned = $this->student(null);

        foreach ([$others->id, $unassigned->id, 999999] as $studentId) {
            $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body($criteria, ['student_id' => $studentId]))
                ->assertStatus(422)
                ->assertJsonPath('errors.student_id.0', 'The selected student id is invalid.');
        }

        $this->assertSame(0, Evaluation::count());
    }

    public function test_duplicate_evaluations_for_the_same_student_and_period_are_allowed_as_on_the_website(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $criteria = $this->criteria(1);

        $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body($criteria, ['student_id' => $student->id]))->assertCreated();
        $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body($criteria, ['student_id' => $student->id]))->assertCreated();

        $this->assertSame(2, Evaluation::count());
    }

    // ---------------------------------------------------------- state refusals

    public static function wrongStates(): array
    {
        return [
            'edit submitted' => ['PATCH', '', 'submitted', 'Only draft evaluations can be edited.'],
            'edit locked' => ['PATCH', '', 'locked', 'Only draft evaluations can be edited.'],
            'submit submitted' => ['POST', '/submit', 'submitted', 'Only draft evaluations can be submitted.'],
            'submit locked' => ['POST', '/submit', 'locked', 'Only draft evaluations can be submitted.'],
            'lock draft' => ['POST', '/lock', 'draft', 'Only submitted evaluations can be locked.'],
            'lock locked' => ['POST', '/lock', 'locked', 'Only submitted evaluations can be locked.'],
            'reopen draft' => ['POST', '/reopen', 'draft', 'Only submitted or locked evaluations can be reopened.'],
        ];
    }

    #[DataProvider('wrongStates')]
    public function test_a_change_in_the_wrong_state_is_a_422_with_the_fresh_evaluation(string $method, string $suffix, string $status, string $message): void
    {
        $admin = $this->user(4);
        $criteria = $this->criteria(1);
        $evaluation = $this->evaluation($this->student(null), $status, ['strengths' => 'Original']);
        $before = $evaluation->fresh()->toArray();

        $this->api($method, "/api/v1/evaluations/{$evaluation->id}{$suffix}", $admin, $this->body($criteria, ['strengths' => 'Changed']))
            ->assertStatus(422)
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('message', $message)
            ->assertJsonPath('code', 'evaluation_rule')
            ->assertJsonPath('evaluation.id', $evaluation->id)
            ->assertJsonPath('evaluation.status', $status);

        $this->assertSame($before, $evaluation->fresh()->toArray());
        $this->assertSame(0, EvaluationResponse::count());
    }

    public function test_a_supervisor_editing_an_own_submitted_evaluation_gets_the_rule_refusal(): void
    {
        $supervisor = $this->user(3);
        $evaluation = $this->evaluation($this->student($supervisor), 'submitted');

        $this->api('PATCH', "/api/v1/evaluations/{$evaluation->id}", $supervisor, $this->body($this->criteria(1)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'evaluation_rule');
    }

    public function test_a_submit_racing_another_submit_exactly_one_wins(): void
    {
        $supervisor = $this->user(3);
        $admin = $this->user(4);
        $criteria = $this->criteria(1);
        $evaluation = app(EvaluationService::class)->createDraft($this->student($supervisor), $this->body($criteria));

        $assertLanded = $this->landBetweenCheckAndLock(
            fn () => app(EvaluationService::class)->submit(Evaluation::find($evaluation->id))
        );

        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/submit", $admin)
            ->assertStatus(422)
            ->assertJsonPath('code', 'evaluation_rule')
            ->assertJsonPath('message', 'Only draft evaluations can be submitted.')
            ->assertJsonPath('evaluation.status', 'submitted');

        $assertLanded();
    }

    public function test_an_edit_racing_a_submit_does_not_change_the_submitted_evaluation(): void
    {
        $supervisor = $this->user(3);
        $criteria = $this->criteria(1);
        $evaluation = app(EvaluationService::class)->createDraft($this->student($supervisor), $this->body($criteria));

        $assertLanded = $this->landBetweenCheckAndLock(
            fn () => app(EvaluationService::class)->submit(Evaluation::find($evaluation->id))
        );

        $this->api('PATCH', "/api/v1/evaluations/{$evaluation->id}", $supervisor, $this->body($criteria, [
            'strengths' => 'Too late',
            'responses' => $this->ratings($criteria, 1),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only draft evaluations can be edited.');

        $assertLanded();
        $fresh = $evaluation->fresh();
        $this->assertSame('Reliable', $fresh->strengths);
        $this->assertSame('4.00', $fresh->overall_rating);
        $this->assertSame([4], $fresh->responses()->pluck('rating')->all());
    }

    public function test_a_lock_racing_a_reopen_exactly_one_wins(): void
    {
        $admin = $this->user(4);
        $evaluation = $this->evaluation($this->student(null), 'submitted');

        $assertLanded = $this->landBetweenCheckAndLock(
            fn () => app(EvaluationService::class)->reopen(Evaluation::find($evaluation->id))
        );

        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/lock", $admin)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only submitted evaluations can be locked.')
            ->assertJsonPath('evaluation.status', 'draft');

        $assertLanded();
        $this->assertNull($evaluation->fresh()->locked_by);
    }

    public function test_a_double_reopen_reopens_once_then_refuses(): void
    {
        $admin = $this->user(4);
        $evaluation = $this->evaluation($this->student(null), 'locked');

        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/reopen", $admin)->assertOk();
        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/reopen", $admin)
            ->assertStatus(422)
            ->assertJsonPath('code', 'evaluation_rule');
    }

    public function test_an_evaluation_deleted_meanwhile_is_a_404(): void
    {
        $admin = $this->user(4);
        $evaluation = $this->evaluation($this->student(null), 'submitted');

        $assertLanded = $this->landBetweenCheckAndLock(
            fn () => DB::table('evaluations')->where('id', $evaluation->id)->delete()
        );

        $this->api('POST', "/api/v1/evaluations/{$evaluation->id}/lock", $admin)->assertNotFound();

        $assertLanded();
    }

    // ---------------------------------------------------------- validation

    public static function badBodies(): array
    {
        return [
            'start missing' => [['evaluation_period_start' => null], 'evaluation_period_start'],
            'start words' => [['evaluation_period_start' => 'tomorrow'], 'evaluation_period_start'],
            'start not a real date' => [['evaluation_period_start' => '2026-02-30'], 'evaluation_period_start'],
            'start other format' => [['evaluation_period_start' => '08/01/2026'], 'evaluation_period_start'],
            'start too early' => [['evaluation_period_start' => '1999-12-31'], 'evaluation_period_start'],
            'start year 1' => [['evaluation_period_start' => '0001-01-01', 'evaluation_period_end' => '0001-01-02'], 'evaluation_period_start'],
            'start array' => [['evaluation_period_start' => ['2026-08-01']], 'evaluation_period_start'],
            'end before start' => [['evaluation_period_end' => '2026-07-31'], 'evaluation_period_end'],
            'end too late' => [['evaluation_period_end' => '2100-01-01'], 'evaluation_period_end'],
            'strengths array' => [['strengths' => ['a']], 'strengths'],
            'strengths number' => [['strengths' => 5], 'strengths'],
            'remarks too long' => [['supervisor_remarks' => str_repeat('a', 5001)], 'supervisor_remarks'],
            'responses string' => [['responses' => 'all fives'], 'responses'],
            'responses too many' => [['responses' => array_fill(0, 201, ['evaluation_criteria_id' => 1, 'rating' => 3])], 'responses'],
            'response not an object' => [['responses' => [5]], 'responses.0'],
            'rating missing' => [['responses' => [['evaluation_criteria_id' => '@a']]], 'responses.0.rating'],
            'rating zero' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 0]]], 'responses.0.rating'],
            'rating six' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 6]]], 'responses.0.rating'],
            'rating fraction' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 4.5]]], 'responses.0.rating'],
            'rating text' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 'five']]], 'responses.0.rating'],
            'rating boolean' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => true]]], 'responses.0.rating'],
            'rating array' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => [5]]]], 'responses.0.rating'],
            'criterion missing' => [['responses' => [['rating' => 5]]], 'responses.0.evaluation_criteria_id'],
            'criterion unknown' => [['responses' => [['evaluation_criteria_id' => 999999, 'rating' => 5]]], 'responses.0.evaluation_criteria_id'],
            'criterion inactive' => [['responses' => [['evaluation_criteria_id' => '@inactive', 'rating' => 5]]], 'responses.0.evaluation_criteria_id'],
            'criterion text' => [['responses' => [['evaluation_criteria_id' => 'abc', 'rating' => 5]]], 'responses.0.evaluation_criteria_id'],
            'criterion boolean' => [['responses' => [['evaluation_criteria_id' => true, 'rating' => 5]]], 'responses.0.evaluation_criteria_id'],
            'criterion duplicate' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 5], ['evaluation_criteria_id' => '@a', 'rating' => 4]]], 'responses.1.evaluation_criteria_id'],
            'comment too long' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 5, 'comment' => str_repeat('a', 2001)]]], 'responses.0.comment'],
            'comment array' => [['responses' => [['evaluation_criteria_id' => '@a', 'rating' => 5, 'comment' => ['x']]]], 'responses.0.comment'],
        ];
    }

    /**
     * '@a' / '@inactive' in a provider body stand for real criterion ids.
     */
    private function resolveIds(array $body, EvaluationCriteria $active, EvaluationCriteria $inactive): array
    {
        array_walk_recursive($body, function (&$value) use ($active, $inactive) {
            if ($value === '@a') {
                $value = $active->id;
            } elseif ($value === '@inactive') {
                $value = $inactive->id;
            }
        });

        return $body;
    }

    #[DataProvider('badBodies')]
    public function test_bad_bodies_are_422_and_store_nothing(array $overrides, string $key): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $active = EvaluationCriteria::factory()->create();
        $inactive = EvaluationCriteria::factory()->inactive()->create();
        $overrides = $this->resolveIds($overrides, $active, $inactive);

        $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body([$active], [...$overrides, 'student_id' => $student->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
        $this->assertSame(0, Evaluation::count());

        $draft = app(EvaluationService::class)->createDraft($student, $this->body([$active]));
        $before = $draft->fresh()->toArray();

        $this->api('PATCH', "/api/v1/evaluations/{$draft->id}", $supervisor, $this->body([$active], $overrides))
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
        $this->assertSame($before, $draft->fresh()->toArray());
        $this->assertSame([4], $draft->responses()->pluck('rating')->all());
    }

    public function test_a_malformed_date_shows_one_error(): void
    {
        $supervisor = $this->user(3);

        $response = $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body([], [
            'student_id' => $this->student($supervisor)->id,
            'evaluation_period_start' => 'garbage',
        ]))->assertStatus(422);

        $this->assertCount(1, $response->json('errors.evaluation_period_start'));
    }

    public function test_bad_student_ids_are_422(): void
    {
        $supervisor = $this->user(3);

        foreach ([null, 'abc', true, [1], 1.5] as $studentId) {
            $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body([], ['student_id' => $studentId]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('student_id');
        }
    }

    public function test_invalid_utf8_and_undecodable_json_are_422_and_change_nothing(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $criteria = $this->criteria(1);
        $draft = app(EvaluationService::class)->createDraft($student, $this->body($criteria));
        $before = $draft->fresh()->toArray();

        $badUtf8 = '{"evaluation_period_start":"2026-08-01","evaluation_period_end":"2026-08-31","strengths":"ok'."\xC3\x28".'"}';

        foreach (['{"strengths": "unterminated', $badUtf8, '[1,2', '{"responses": [{"rating": 5}'] as $body) {
            $this->raw('PATCH', "/api/v1/evaluations/{$draft->id}", $supervisor, $body)->assertStatus(422);
            $this->raw('POST', '/api/v1/evaluations', $supervisor, $body)->assertStatus(422);
        }

        $this->raw('PATCH', "/api/v1/evaluations/{$draft->id}", $supervisor, $badUtf8)
            ->assertJsonValidationErrors('input');

        $this->assertSame($before, $draft->fresh()->toArray());
        $this->assertSame(1, Evaluation::count());
    }

    public function test_long_multibyte_text_is_stored_intact(): void
    {
        $supervisor = $this->user(3);
        $criteria = $this->criteria(1);
        $text = "Línea 1\n".str_repeat('ñ', 4992);
        $comment = str_repeat('é', 2000);

        $id = $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body($criteria, [
            'student_id' => $this->student($supervisor)->id,
            'strengths' => $text,
            'responses' => [['evaluation_criteria_id' => $criteria[0]->id, 'rating' => '5', 'comment' => $comment]],
        ]))->assertCreated()->json('evaluation.id');

        $evaluation = Evaluation::find($id);
        $this->assertSame($text, $evaluation->strengths);
        $this->assertSame($comment, $evaluation->responses->first()->comment);
        $this->assertSame(5, $evaluation->responses->first()->rating);
    }

    public function test_empty_text_fields_and_no_responses_are_a_valid_draft(): void
    {
        $supervisor = $this->user(3);

        $this->api('POST', '/api/v1/evaluations', $supervisor, [
            'student_id' => $this->student($supervisor)->id,
            'evaluation_period_start' => '2026-08-01',
            'evaluation_period_end' => '2026-08-01',
            'strengths' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('evaluation.strengths', null)
            ->assertJsonPath('evaluation.overall_rating', null)
            ->assertJsonPath('evaluation.responses', [])
            ->assertJsonPath('evaluation.categories', []);
    }

    // ---------------------------------------------------------- criteria changes

    public function test_criteria_changes_never_alter_saved_evaluations(): void
    {
        $admin = $this->user(4);
        $supervisor = $this->user(3);
        [$a, $b] = $this->criteria();
        $id = $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body([$a, $b], [
            'student_id' => $this->student($supervisor)->id,
            'responses' => [
                ['evaluation_criteria_id' => $a->id, 'rating' => 5],
                ['evaluation_criteria_id' => $b->id, 'rating' => 3],
            ],
        ]))->assertCreated()->json('evaluation.id');
        $this->api('POST', "/api/v1/evaluations/{$id}/submit", $supervisor)->assertOk();

        $this->api('POST', "/api/v1/evaluation-criteria/{$a->id}/deactivate", $admin)->assertOk();
        $this->api('PATCH', "/api/v1/evaluation-criteria/{$b->id}", $admin, ['label' => 'Renamed', 'category' => 'New category'])->assertOk();
        $this->api('DELETE', "/api/v1/evaluation-criteria/{$b->id}", $admin)->assertOk()->assertJsonPath('deleted', false);
        EvaluationCriteria::factory()->create(); // a new active criterion

        $detail = $this->api('GET', "/api/v1/evaluations/{$id}", $admin)->assertOk()->json('evaluation');

        $this->assertSame(4, $detail['overall_rating']);
        $this->assertEqualsCanonicalizing([5, 3], array_column($detail['responses'], 'rating'));
        $flat = collect($detail['categories'])->flatMap(fn ($group) => $group['criteria'])->keyBy('id');
        $this->assertFalse($flat[$a->id]['is_active']);
        $this->assertSame(5, $flat[$a->id]['rating']);
        // Submitted evaluations keep the wording they were submitted with
        // (owner decision 2026-10-07; EvaluationCriterionSnapshotTest).
        $this->assertSame($b->label, $flat[$b->id]['label']);
        $this->assertNotSame('Renamed', $flat[$b->id]['label']);
        $this->assertSame(3, $flat[$b->id]['rating']);
    }

    // ---------------------------------------------------------- website parity

    public function test_api_and_website_store_identical_drafts(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $criteria = $this->criteria(3);
        $body = $this->body($criteria, ['student_id' => $student->id]);

        $apiId = $this->api('POST', '/api/v1/evaluations', $supervisor, $body)->assertCreated()->json('evaluation.id');
        $this->actingAs($supervisor)->post('/supervisor-evaluations', $body)->assertRedirect();
        $webId = Evaluation::whereKeyNot($apiId)->value('id');

        $strip = fn (Evaluation $evaluation) => collect($evaluation->fresh()->toArray())
            ->except(['id', 'created_at', 'updated_at'])->all();
        $this->assertSame($strip(Evaluation::find($apiId)), $strip(Evaluation::find($webId)));

        $responses = fn (int $id) => EvaluationResponse::where('evaluation_id', $id)->orderBy('evaluation_criteria_id')
            ->get(['evaluation_criteria_id', 'rating', 'comment'])->toArray();
        $this->assertSame($responses($apiId), $responses($webId));
    }

    public function test_api_and_website_refuse_the_same_state_changes(): void
    {
        $admin = $this->user(4);
        $draft = $this->evaluation($this->student(null));

        $this->actingAs($admin)->patch("/supervisor-evaluations/{$draft->id}/lock")->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$draft->id}/lock", $admin)->assertStatus(422);

        $this->actingAs($admin)->patch("/supervisor-evaluations/{$draft->id}/reopen")->assertForbidden();
        $this->api('POST', "/api/v1/evaluations/{$draft->id}/reopen", $admin)->assertStatus(422);

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_the_website_answers_a_lost_race_with_the_policys_403(): void
    {
        $admin = $this->user(4);
        $evaluation = $this->evaluation($this->student(null), 'submitted');

        $assertLanded = $this->landBetweenCheckAndLock(
            fn () => app(EvaluationService::class)->lock(Evaluation::find($evaluation->id), $admin)
        );

        $this->actingAs($admin)->patch("/supervisor-evaluations/{$evaluation->id}/lock")->assertForbidden();

        $assertLanded();
        $this->assertSame('locked', $evaluation->fresh()->status);
    }

    public function test_an_evaluation_submitted_from_the_app_shows_on_my_feedback(): void
    {
        $supervisor = $this->user(3);
        $student = $this->student($supervisor);
        $criteria = $this->criteria(2);

        $id = $this->api('POST', '/api/v1/evaluations', $supervisor, $this->body($criteria, ['student_id' => $student->id]))
            ->assertCreated()->json('evaluation.id');

        $this->api('GET', '/api/v1/feedback', $student->user)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "/api/v1/feedback/{$id}", $student->user)->assertNotFound();

        $this->api('POST', "/api/v1/evaluations/{$id}/submit", $supervisor)->assertOk();

        $this->api('GET', '/api/v1/feedback', $student->user)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->api('GET', "/api/v1/feedback/{$id}", $student->user)
            ->assertOk()
            ->assertJsonPath('evaluation.strengths', 'Reliable')
            ->assertJsonPath('evaluation.overall_rating', 4);

        $this->actingAs($student->user)->get('/my-feedback')->assertInertia(
            fn (Assert $page) => $page->where('evaluations.0.id', $id)
        );
    }
}
