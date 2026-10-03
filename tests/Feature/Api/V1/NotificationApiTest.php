<?php

namespace Tests\Feature\Api\V1;

use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function userWithToken(int $roleId): array
    {
        $user = User::factory()->create(['role_id' => $roleId, 'status' => 'active']);

        if ($roleId === 1) {
            Student::factory()->create(['user_id' => $user->id]);
        }

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function api(string $method, string $uri, ?string $token, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->call($method, $uri, [], [], [], $this->transformHeadersToServerVars($headers));
    }

    private function notificationFor(User $user, array $attributes = []): Notification
    {
        return Notification::factory()->create(array_merge(['user_id' => $user->id], $attributes));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function roles(): array
    {
        return [
            'student' => [1],
            'coordinator' => [2],
            'supervisor' => [3],
            'admin' => [4],
        ];
    }

    // ---------------------------------------------------------------- auth

    public function test_every_endpoint_requires_a_token(): void
    {
        $note = Notification::factory()->create();

        $this->api('GET', '/api/v1/notifications', null)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->api('GET', '/api/v1/notifications/unread-count', null)->assertUnauthorized();
        $this->api('POST', "/api/v1/notifications/{$note->id}/read", null)->assertUnauthorized();
        $this->api('POST', '/api/v1/notifications/read-all', null)->assertUnauthorized();

        $this->assertNull($note->fresh()->read_at);
    }

    public function test_inactive_account_is_refused(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $user->update(['status' => 'inactive']);

        $this->api('GET', '/api/v1/notifications', $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    #[DataProvider('roles')]
    public function test_every_role_can_use_the_inbox(int $roleId): void
    {
        [$user, $token] = $this->userWithToken($roleId);
        $note = $this->notificationFor($user);

        $this->api('GET', '/api/v1/notifications', $token)->assertOk()->assertJsonPath('data.0.id', $note->id);
        $this->api('GET', '/api/v1/notifications/unread-count', $token)->assertOk()->assertExactJson(['count' => 1]);
        $this->api('POST', "/api/v1/notifications/{$note->id}/read", $token)->assertOk()->assertJsonPath('unread_count', 0);
        $this->api('POST', '/api/v1/notifications/read-all', $token)->assertOk()->assertExactJson(['marked' => 0, 'unread_count' => 0]);
    }

    // ---------------------------------------------------------------- list

    public function test_lists_only_the_callers_notifications_newest_first(): void
    {
        [$user, $token] = $this->userWithToken(4);
        [$other] = $this->userWithToken(4);

        $old = $this->notificationFor($user, ['created_at' => now()->subDays(2)]);
        $new = $this->notificationFor($user, ['created_at' => now()->subHour()]);
        $this->notificationFor($other);

        $response = $this->api('GET', '/api/v1/notifications', $token)->assertOk();

        $this->assertSame([$new->id, $old->id], array_column($response->json('data'), 'id'));
    }

    public function test_item_shape(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 14:03:00', 'Asia/Manila'));

        [$user, $token] = $this->userWithToken(3);
        $note = $this->notificationFor($user, [
            'type' => 'attendance_pending',
            'title' => 'New attendance approval request',
            'body' => 'Juan submitted a time-in.',
            'data' => ['attendance_id' => 55],
            'read_at' => null,
        ]);

        $this->api('GET', '/api/v1/notifications', $token)
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $note->id,
                    'type' => 'attendance_pending',
                    'title' => 'New attendance approval request',
                    'message' => 'Juan submitted a time-in.',
                    'read_at' => null,
                    'created_at' => '2026-10-02T14:03:00+08:00',
                    'target' => ['screen' => 'approvals', 'params' => ['attendance_id' => 55]],
                ]],
                'meta' => ['per_page' => 20, 'next_cursor' => null, 'has_more' => false],
            ]);

        Carbon::setTestNow();
    }

    public function test_target_is_null_and_message_may_be_null(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $this->notificationFor($user, ['type' => 'report_submitted', 'body' => null, 'data' => ['report_id' => 3]]);

        $this->api('GET', '/api/v1/notifications', $token)
            ->assertOk()
            ->assertJsonPath('data.0.target', null)
            ->assertJsonPath('data.0.message', null);
    }

    public function test_target_is_resolved_for_the_viewers_role(): void
    {
        [$student, $studentToken] = $this->userWithToken(1);
        $this->notificationFor($student, ['type' => 'report_reviewed', 'data' => ['report_id' => 8]]);

        $this->api('GET', '/api/v1/notifications', $studentToken)
            ->assertJsonPath('data.0.target', ['screen' => 'my-reports', 'params' => ['report_id' => 8]]);

        [$admin, $adminToken] = $this->userWithToken(4);
        $this->notificationFor($admin, ['type' => 'student_registered', 'data' => ['user_id' => $student->id]]);

        $this->api('GET', '/api/v1/notifications', $adminToken)
            ->assertJsonPath('data.0.target', ['screen' => 'students', 'params' => []]);
    }

    public function test_cursor_pagination_walks_every_item_once(): void
    {
        [$user, $token] = $this->userWithToken(2);

        // Several share a created_at second, so the id tie-break matters.
        $ids = [];
        foreach (range(1, 7) as $i) {
            $ids[] = $this->notificationFor($user, ['created_at' => now()->subMinutes(intdiv($i, 3))])->id;
        }

        $expected = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->api('GET', '/api/v1/notifications', $token, array_filter([
                'per_page' => 3,
                'cursor' => $cursor,
            ]))->assertOk()->assertJsonPath('meta.per_page', 3);

            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
            $this->assertSame($cursor !== null, $response->json('meta.has_more'));
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertSame($expected, $seen);
    }

    public function test_new_items_arriving_mid_scroll_do_not_repeat_on_the_next_page(): void
    {
        [$user, $token] = $this->userWithToken(1);

        foreach (range(1, 4) as $i) {
            $this->notificationFor($user, ['created_at' => now()->subHours($i)]);
        }

        $first = $this->api('GET', '/api/v1/notifications', $token, ['per_page' => 2]);
        $this->notificationFor($user, ['created_at' => now()]);

        $second = $this->api('GET', '/api/v1/notifications', $token, [
            'per_page' => 2,
            'cursor' => $first->json('meta.next_cursor'),
        ]);

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
        $this->assertCount(2, $second->json('data'));
    }

    public function test_unread_filter(): void
    {
        [$user, $token] = $this->userWithToken(3);
        $unread = $this->notificationFor($user);
        $read = Notification::factory()->read()->create(['user_id' => $user->id]);

        foreach (['1', 'true'] as $value) {
            $ids = array_column($this->api('GET', '/api/v1/notifications', $token, ['unread' => $value])->assertOk()->json('data'), 'id');
            $this->assertSame([$unread->id], $ids, "unread={$value}");
        }

        foreach (['0', 'false'] as $value) {
            $ids = array_column($this->api('GET', '/api/v1/notifications', $token, ['unread' => $value])->assertOk()->json('data'), 'id');
            $this->assertEqualsCanonicalizing([$unread->id, $read->id], $ids, "unread={$value}");
        }

        $readItem = collect($this->api('GET', '/api/v1/notifications', $token)->json('data'))->firstWhere('id', $read->id);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $readItem['read_at']);
    }

    public function test_invalid_query_parameters_are_422(): void
    {
        [, $token] = $this->userWithToken(1);

        $this->api('GET', '/api/v1/notifications', $token, ['unread' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('unread');
        $this->api('GET', '/api/v1/notifications', $token, ['unread' => ['1']])->assertUnprocessable()->assertJsonValidationErrors('unread');
        $this->api('GET', '/api/v1/notifications', $token, ['per_page' => 0])->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->api('GET', '/api/v1/notifications', $token, ['per_page' => 51])->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->api('GET', '/api/v1/notifications', $token, ['per_page' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->api('GET', '/api/v1/notifications', $token, ['cursor' => 'garbage'])->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->api('GET', '/api/v1/notifications', $token, ['cursor' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('cursor');

        $tampered = [
            new Cursor(['id' => 5]),
            new Cursor(['created_at' => '2026-10-02 10:00:00', 'id' => 'abc']),
            new Cursor(['created_at' => ['x'], 'id' => 5]),
            new Cursor(['created_at' => "2026-10-02' OR 1=1", 'id' => 5]),
            new Cursor(['created_at' => '2026-10-02 10:00:00', 'id' => 5, 'user_id' => 9]),
        ];

        foreach ($tampered as $cursor) {
            $this->api('GET', '/api/v1/notifications', $token, ['cursor' => $cursor->encode()])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cursor');
        }

        $this->api('GET', '/api/v1/notifications', $token, ['per_page' => 50])->assertOk();
    }

    /**
     * Raw cursor payloads (JSON before base64url) that are not cursors this
     * endpoint issues. Each used to reach Cursor::fromEncoded() and 500.
     *
     * @return array<string, array{0: string}>
     */
    public static function forgedCursorPayloads(): array
    {
        return [
            'int' => ['1'],
            'string' => ['"abc"'],
            'empty list' => ['[]'],
            'empty object' => ['{}'],
            'list of values' => ['["2026-10-03 07:57:44", 222, true]'],
            'missing points flag' => ['{"created_at":"2026-10-03 07:57:44","id":222}'],
            'extra key' => ['{"created_at":"2026-10-03 07:57:44","id":222,"foo":true}'],
            'extra key with flag' => ['{"created_at":"2026-10-03 07:57:44","id":222,"_pointsToNextItems":true,"foo":1}'],
            'previous-page cursor' => ['{"created_at":"2026-10-03 07:57:44","id":222,"_pointsToNextItems":false}'],
            'null points flag' => ['{"created_at":"2026-10-03 07:57:44","id":222,"_pointsToNextItems":null}'],
            'string points flag' => ['{"created_at":"2026-10-03 07:57:44","id":222,"_pointsToNextItems":"x"}'],
            'impossible date' => ['{"created_at":"2026-02-31 25:61:00","id":222,"_pointsToNextItems":true}'],
            'string id' => ['{"created_at":"2026-10-03 07:57:44","id":"222","_pointsToNextItems":true}'],
            'zero id' => ['{"created_at":"2026-10-03 07:57:44","id":0,"_pointsToNextItems":true}'],
            'nested created_at' => ['{"created_at":{"a":1},"id":222,"_pointsToNextItems":true}'],
            'not json' => ['not json at all'],
        ];
    }

    #[DataProvider('forgedCursorPayloads')]
    public function test_forged_cursor_payloads_are_422_not_500(string $json): void
    {
        [, $token] = $this->userWithToken(2);

        $encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($json));

        $this->api('GET', '/api/v1/notifications', $token, ['cursor' => $encoded])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The cursor is invalid.',
                'errors' => ['cursor' => ['The cursor is invalid.']],
            ]);
    }

    public function test_a_well_formed_next_cursor_is_accepted(): void
    {
        [$user, $token] = $this->userWithToken(2);
        $note = $this->notificationFor($user, ['created_at' => now()->subDay()]);

        $cursor = (new Cursor(['created_at' => now()->format('Y-m-d H:i:s'), 'id' => 999999], true))->encode();

        $this->api('GET', '/api/v1/notifications', $token, ['cursor' => $cursor])
            ->assertOk()
            ->assertJsonPath('data.0.id', $note->id)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_empty_target_params_are_a_json_object(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $note = $this->notificationFor($user, ['type' => 'assignment_updated', 'data' => ['student_id' => 3]]);

        $this->api('GET', '/api/v1/notifications', $token)
            ->assertOk()
            ->assertSee('"target":{"screen":"home","params":{}}', false);

        $this->api('POST', "/api/v1/notifications/{$note->id}/read", $token)
            ->assertOk()
            ->assertSee('"target":{"screen":"home","params":{}}', false);
    }

    public function test_filled_target_params_are_a_json_object(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $this->notificationFor($user, ['type' => 'report_reviewed', 'data' => ['report_id' => 8]]);

        $this->api('GET', '/api/v1/notifications', $token)
            ->assertOk()
            ->assertSee('"target":{"screen":"my-reports","params":{"report_id":8}}', false);
    }

    public function test_a_cursor_cannot_reveal_another_users_notifications(): void
    {
        [$user, $token] = $this->userWithToken(1);
        [$other, $otherToken] = $this->userWithToken(1);
        $this->notificationFor($user);
        $this->notificationFor($user);
        $this->notificationFor($other);
        $this->notificationFor($other);

        $cursor = $this->api('GET', '/api/v1/notifications', $otherToken, ['per_page' => 1])->json('meta.next_cursor');

        $ids = array_column($this->api('GET', '/api/v1/notifications', $token, ['cursor' => $cursor])->assertOk()->json('data'), 'id');

        $this->assertSame([], Notification::whereIn('id', $ids)->where('user_id', '!=', $user->id)->pluck('id')->all());
    }

    // ---------------------------------------------------------------- count

    public function test_unread_count_counts_only_the_callers_unread(): void
    {
        [$user, $token] = $this->userWithToken(2);
        [$other] = $this->userWithToken(2);

        $this->notificationFor($user);
        $this->notificationFor($user);
        Notification::factory()->read()->create(['user_id' => $user->id]);
        $this->notificationFor($other);

        $this->api('GET', '/api/v1/notifications/unread-count', $token)
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    // ---------------------------------------------------------------- mark read

    public function test_mark_read_marks_own_notification_and_returns_it(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $note = $this->notificationFor($user, ['type' => 'attendance_reviewed', 'data' => ['attendance_id' => 4]]);
        $this->notificationFor($user);

        $response = $this->api('POST', "/api/v1/notifications/{$note->id}/read", $token)
            ->assertOk()
            ->assertJsonPath('notification.id', $note->id)
            ->assertJsonPath('notification.target', ['screen' => 'attendance', 'params' => ['attendance_id' => 4]])
            ->assertJsonPath('unread_count', 1);

        $this->assertNotNull($note->fresh()->read_at);
        $this->assertNotNull($response->json('notification.read_at'));
    }

    public function test_mark_read_is_idempotent_and_keeps_the_original_read_at(): void
    {
        [$user, $token] = $this->userWithToken(1);
        $readAt = now()->subDay()->startOfSecond();
        $note = $this->notificationFor($user, ['read_at' => $readAt]);

        $this->api('POST', "/api/v1/notifications/{$note->id}/read", $token)
            ->assertOk()
            ->assertJsonPath('notification.read_at', $readAt->toIso8601String());

        $this->assertTrue($note->fresh()->read_at->equalTo($readAt));
    }

    public function test_mark_read_on_someone_elses_notification_is_404(): void
    {
        [$supervisor, $token] = $this->userWithToken(3);
        [$studentUser] = $this->userWithToken(1);
        Student::where('user_id', $studentUser->id)->update(['supervisor_id' => $supervisor->id]);

        // Even the student's own supervisor can't mark it read for them.
        $note = $this->notificationFor($studentUser);

        $this->api('POST', "/api/v1/notifications/{$note->id}/read", $token)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);

        $this->assertNull($note->fresh()->read_at);
    }

    public function test_mark_read_unknown_or_non_numeric_id_is_404(): void
    {
        [, $token] = $this->userWithToken(4);

        $this->api('POST', '/api/v1/notifications/999999/read', $token)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->api('POST', '/api/v1/notifications/abc/read', $token)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->api('POST', '/api/v1/notifications/99999999999999999999999/read', $token)->assertNotFound();
    }

    public function test_mark_read_requires_post(): void
    {
        [$user, $token] = $this->userWithToken(4);
        $note = $this->notificationFor($user);

        $this->api('GET', "/api/v1/notifications/{$note->id}/read", $token)->assertStatus(405);
        $this->assertNull($note->fresh()->read_at);
    }

    // ---------------------------------------------------------------- read all

    public function test_read_all_affects_only_the_caller(): void
    {
        [$user, $token] = $this->userWithToken(4);
        [$other] = $this->userWithToken(4);

        $mine = [$this->notificationFor($user), $this->notificationFor($user)];
        $alreadyReadAt = now()->subDay()->startOfSecond();
        $alreadyRead = $this->notificationFor($user, ['read_at' => $alreadyReadAt]);
        $theirs = $this->notificationFor($other);

        $this->api('POST', '/api/v1/notifications/read-all', $token)
            ->assertOk()
            ->assertExactJson(['marked' => 2, 'unread_count' => 0]);

        foreach ($mine as $note) {
            $this->assertNotNull($note->fresh()->read_at);
        }
        $this->assertTrue($alreadyRead->fresh()->read_at->equalTo($alreadyReadAt));
        $this->assertNull($theirs->fresh()->read_at);
    }
}
