<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Evaluation;
use App\Models\EvaluationCriteria;
use App\Models\EvaluationResponse;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module 8: the student's own submitted / locked evaluations ("My
 * Feedback") through /api/v1, sharing the visibility rule with the website.
 */
class FeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-03 08:15:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------- helpers

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function student(): array
    {
        $user = $this->user(1);

        return [$user, Student::factory()->create(['user_id' => $user->id])];
    }

    private function api(string $uri, ?User $user, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json'];

        if ($user !== null) {
            $headers['Authorization'] = 'Bearer '.$user->createToken('test')->plainTextToken;
        }

        $this->defaultHeaders = [];

        return $this->withHeaders($headers)->get($uri.($query ? '?'.http_build_query($query) : ''));
    }

    /**
     * An evaluation with one response per given [criterion, rating].
     *
     * @param  list<array{0: EvaluationCriteria, 1: int}>  $ratings
     */
    private function evaluation(Student $student, string $status, string $start, array $ratings = [], array $attributes = []): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'student_id' => $student->id,
            'status' => $status,
            'evaluation_period_start' => $start,
            'evaluation_period_end' => Carbon::parse($start)->addDays(13)->format('Y-m-d'),
            'submitted_at' => $status === 'draft' ? null : now(),
            'locked_at' => $status === 'locked' ? now() : null,
            ...$attributes,
        ]);

        foreach ($ratings as [$criterion, $rating]) {
            EvaluationResponse::create([
                'evaluation_id' => $evaluation->id,
                'evaluation_criteria_id' => $criterion->id,
                'rating' => $rating,
                'comment' => "Comment {$rating}",
            ]);
        }

        if ($ratings !== []) {
            $evaluation->update(['overall_rating' => round(collect($ratings)->avg(1), 2)]);
        }

        return $evaluation->refresh();
    }

    private function webEvaluations(User $user): array
    {
        $this->app['auth']->forgetGuards();

        $props = $this->actingAs($user)->get('/my-feedback')->assertOk()->viewData('page')['props'];

        $this->app['auth']->forgetGuards();

        return $props['evaluations'];
    }

    // ---------------------------------------------------------- access

    public function test_requires_a_token(): void
    {
        $this->api('/api/v1/feedback', null)->assertStatus(401);
        $this->api('/api/v1/feedback/1', null)->assertStatus(401);
    }

    public function test_other_roles_are_forbidden(): void
    {
        [, $student] = $this->student();
        $evaluation = $this->evaluation($student, 'submitted', '2026-09-01');

        foreach ([2, 3, 4] as $role) {
            $user = $this->user($role);
            $this->api('/api/v1/feedback', $user)->assertStatus(403);
            $this->api("/api/v1/feedback/{$evaluation->id}", $user)->assertStatus(403);
        }
    }

    public function test_student_without_profile_gets_409(): void
    {
        $user = $this->user(1);

        foreach (['/api/v1/feedback', '/api/v1/feedback/1'] as $uri) {
            $this->api($uri, $user)
                ->assertStatus(409)
                ->assertExactJson([
                    'message' => 'No student profile is linked to your account yet. Please contact your coordinator.',
                    'code' => 'no_student_profile',
                ]);
        }
    }

    // ---------------------------------------------------------- list

    public function test_list_shows_only_own_submitted_and_locked_newest_first(): void
    {
        [$user, $student] = $this->student();
        [, $other] = $this->student();

        $older = $this->evaluation($student, 'locked', '2026-08-01');
        $this->evaluation($student, 'draft', '2026-09-15');
        $newer = $this->evaluation($student, 'submitted', '2026-09-01');
        $this->evaluation($other, 'submitted', '2026-09-20');

        $response = $this->api('/api/v1/feedback', $user)->assertOk();

        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['submitted', 'locked'], array_column($response->json('data'), 'status'));
        $response->assertJsonPath('meta', ['per_page' => 20, 'next_cursor' => null, 'has_more' => false]);
    }

    public function test_list_item_shape(): void
    {
        [$user, $student] = $this->student();
        $supervisor = User::factory()->create(['role_id' => 3, 'name' => 'Maria Santos']);
        $company = Company::factory()->create(['company_name' => 'Acme Corp']);
        $criterion = EvaluationCriteria::factory()->create();
        $evaluation = $this->evaluation($student, 'submitted', '2026-09-01', [[$criterion, 4]], [
            'supervisor_id' => $supervisor->id,
            'company_id' => $company->id,
        ]);

        $this->api('/api/v1/feedback', $user)
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $evaluation->id,
                    'period_start' => '2026-09-01',
                    'period_end' => '2026-09-14',
                    'status' => 'submitted',
                    'overall_rating' => 4,
                    'rating_max' => 5,
                    'supervisor' => ['id' => $supervisor->id, 'name' => 'Maria Santos'],
                    'company' => ['id' => $company->id, 'name' => 'Acme Corp'],
                    'submitted_at' => '2026-10-03T08:15:00+08:00',
                    'locked_at' => null,
                ]],
                'meta' => ['per_page' => 20, 'next_cursor' => null, 'has_more' => false],
            ]);
    }

    public function test_list_paginates_with_cursor_and_rejects_bad_cursor(): void
    {
        [$user, $student] = $this->student();

        foreach (['2026-07-01', '2026-08-01', '2026-08-01', '2026-09-01'] as $start) {
            $this->evaluation($student, 'submitted', $start);
        }

        $first = $this->api('/api/v1/feedback', $user, ['per_page' => 3])->assertOk();
        $first->assertJsonPath('meta.has_more', true);
        $second = $this->api('/api/v1/feedback', $user, ['per_page' => 3, 'cursor' => $first->json('meta.next_cursor')])->assertOk();
        $second->assertJsonPath('meta.has_more', false);

        $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
        $expected = Evaluation::orderByDesc('evaluation_period_start')->orderByDesc('id')->pluck('id')->all();
        $this->assertSame($expected, $ids);

        $this->api('/api/v1/feedback', $user, ['cursor' => 'garbage'])
            ->assertStatus(422)->assertJsonValidationErrors(['cursor' => 'The cursor is invalid.']);
        $this->api('/api/v1/feedback', $user, ['per_page' => 51])
            ->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    // ---------------------------------------------------------- detail

    public function test_detail_groups_saved_ratings_by_category(): void
    {
        [$user, $student] = $this->student();
        $a = EvaluationCriteria::factory()->create(['category' => 'Work Quality', 'label' => 'Accuracy', 'description' => 'Few errors']);
        $b = EvaluationCriteria::factory()->create(['category' => 'Teamwork', 'label' => 'Cooperation', 'description' => null]);
        $c = EvaluationCriteria::factory()->create(['category' => 'Work Quality', 'label' => 'Neatness']);
        $evaluation = $this->evaluation($student, 'locked', '2026-09-01', [[$a, 5], [$b, 3], [$c, 4]], [
            'strengths' => 'Fast learner',
            'areas_for_improvement' => 'Documentation',
            'recommendations' => 'Keep going',
            'supervisor_remarks' => null,
        ]);

        $this->api("/api/v1/feedback/{$evaluation->id}", $user)
            ->assertOk()
            ->assertJsonPath('evaluation.id', $evaluation->id)
            ->assertJsonPath('evaluation.status', 'locked')
            ->assertJsonPath('evaluation.overall_rating', 4)
            ->assertJsonPath('evaluation.locked_at', '2026-10-03T08:15:00+08:00')
            ->assertJsonPath('evaluation.strengths', 'Fast learner')
            ->assertJsonPath('evaluation.areas_for_improvement', 'Documentation')
            ->assertJsonPath('evaluation.recommendations', 'Keep going')
            ->assertJsonPath('evaluation.supervisor_remarks', null)
            ->assertJsonPath('evaluation.categories', [
                ['category' => 'Work Quality', 'criteria' => [
                    ['id' => $a->id, 'label' => 'Accuracy', 'description' => 'Few errors', 'rating' => 5, 'max' => 5, 'comment' => 'Comment 5'],
                    ['id' => $c->id, 'label' => 'Neatness', 'description' => $c->description, 'rating' => 4, 'max' => 5, 'comment' => 'Comment 4'],
                ]],
                ['category' => 'Teamwork', 'criteria' => [
                    ['id' => $b->id, 'label' => 'Cooperation', 'description' => null, 'rating' => 3, 'max' => 5, 'comment' => 'Comment 3'],
                ]],
            ]);
    }

    public function test_detail_is_404_for_drafts_others_and_unknown_ids(): void
    {
        [$user, $student] = $this->student();
        [, $other] = $this->student();

        $draft = $this->evaluation($student, 'draft', '2026-09-01');
        $others = $this->evaluation($other, 'submitted', '2026-09-01');

        foreach ([$draft->id, $others->id, 999999] as $id) {
            $this->api("/api/v1/feedback/{$id}", $user)->assertStatus(404);
        }
    }

    public function test_criteria_changed_after_submission_still_show_saved_ratings(): void
    {
        [$user, $student] = $this->student();
        $kept = EvaluationCriteria::factory()->create(['category' => 'Teamwork']);
        $deactivated = EvaluationCriteria::factory()->create(['category' => 'Teamwork']);
        $evaluation = $this->evaluation($student, 'submitted', '2026-09-01', [[$kept, 2], [$deactivated, 5]]);

        // Changed afterwards: one deactivated, a brand-new criterion added.
        $deactivated->update(['is_active' => false]);
        EvaluationCriteria::factory()->create(['category' => 'Teamwork']);

        $response = $this->api("/api/v1/feedback/{$evaluation->id}", $user)->assertOk();

        $criteria = $response->json('evaluation.categories.0.criteria');
        $this->assertSame([$kept->id, $deactivated->id], array_column($criteria, 'id'));
        $this->assertSame([2, 5], array_column($criteria, 'rating'));
        $response->assertJsonPath('evaluation.overall_rating', 3.5);
    }

    // ---------------------------------------------------------- web parity

    public function test_matches_the_website_my_feedback_props(): void
    {
        [$user, $student] = $this->student();
        [, $other] = $this->student();
        $criteria = EvaluationCriteria::factory()->count(4)->create();
        $retired = EvaluationCriteria::factory()->create();

        $this->evaluation($student, 'submitted', '2026-09-01', [[$criteria[0], 4], [$criteria[1], 3], [$retired, 5]]);
        $this->evaluation($student, 'locked', '2026-08-01', [[$criteria[2], 2], [$criteria[3], 5]]);
        $this->evaluation($student, 'draft', '2026-09-15', [[$criteria[0], 1]]);
        $this->evaluation($other, 'submitted', '2026-09-10', [[$criteria[0], 5]]);
        $retired->update(['is_active' => false]);

        $web = $this->webEvaluations($user);
        $list = $this->api('/api/v1/feedback', $user)->assertOk()->json('data');

        $this->assertSame(array_column($web, 'id'), array_column($list, 'id'));

        foreach ($web as $index => $webEvaluation) {
            $this->assertSame($webEvaluation['status'], $list[$index]['status']);
            $this->assertEquals((float) $webEvaluation['overall_rating'], $list[$index]['overall_rating']);

            $detail = $this->api("/api/v1/feedback/{$webEvaluation['id']}", $user)->assertOk()->json('evaluation');

            foreach (['strengths', 'areas_for_improvement', 'recommendations', 'supervisor_remarks'] as $field) {
                $this->assertSame($webEvaluation[$field], $detail[$field]);
            }
            $this->assertSame($webEvaluation['evaluation_period_start'], $detail['period_start']);
            $this->assertSame($webEvaluation['evaluation_period_end'], $detail['period_end']);

            // Same criteria, ratings and order the website renders.
            $webRatings = collect($webEvaluation['responses'])
                ->filter(fn ($response) => $response['criteria'] !== null)
                ->groupBy(fn ($response) => $response['criteria']['category'])
                ->map(fn ($group) => $group->map(fn ($r) => [$r['criteria']['id'], $r['rating']])->values()->all())
                ->all();
            $apiRatings = collect($detail['categories'])
                ->mapWithKeys(fn ($group) => [$group['category'] => array_map(fn ($c) => [$c['id'], $c['rating']], $group['criteria'])])
                ->all();
            $this->assertSame($webRatings, $apiRatings);
        }
    }
}
