<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationLinksTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT = 1;

    private const COORDINATOR = 2;

    private const SUPERVISOR = 3;

    private const ADMIN = 4;

    /**
     * The data id every matrix notification carries. It deliberately points
     * at no existing record: destinations are list pages, so a deleted
     * record must still open fine (just with nothing highlighted).
     */
    private const DATA_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function viewer(int $roleId): User
    {
        $user = User::factory()->create(['role_id' => $roleId]);

        if ($roleId === self::STUDENT) {
            Student::factory()->create(['user_id' => $user->id]);
        }

        return $user;
    }

    private function resolve(string $type, array $data, User $viewer): string
    {
        $notification = Notification::factory()->create([
            'user_id' => $viewer->id,
            'type' => $type,
            'data' => $data,
        ]);

        return app(NotificationService::class)->urlFor($notification, $viewer);
    }

    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private static function dataKeyFor(string $type): string
    {
        return match ($type) {
            'attendance_pending', 'attendance_reviewed' => 'attendance_id',
            'report_submitted', 'report_reviewed' => 'report_id',
            'assignment_updated' => 'student_id',
            'student_registered' => 'user_id',
        };
    }

    /**
     * Every notification type x every role => expected destination path.
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function matrix(): array
    {
        $h = '?highlight='.self::DATA_ID;

        $expected = [
            'attendance_pending' => [
                self::STUDENT => '/notifications',
                self::COORDINATOR => '/attendance-monitoring',
                self::SUPERVISOR => '/attendance-approvals'.$h,
                self::ADMIN => '/attendance-approvals'.$h,
            ],
            'attendance_reviewed' => [
                self::STUDENT => '/my-attendance'.$h,
                self::COORDINATOR => '/attendance-monitoring',
                self::SUPERVISOR => '/attendance-monitoring',
                self::ADMIN => '/attendance-monitoring',
            ],
            'report_submitted' => [
                self::STUDENT => '/notifications',
                self::COORDINATOR => '/report-reviews'.$h,
                self::SUPERVISOR => '/report-reviews'.$h,
                self::ADMIN => '/report-reviews'.$h,
            ],
            'report_reviewed' => [
                self::STUDENT => '/my-reports'.$h,
                self::COORDINATOR => '/report-reviews'.$h,
                self::SUPERVISOR => '/report-reviews'.$h,
                self::ADMIN => '/report-reviews'.$h,
            ],
            'assignment_updated' => [
                self::STUDENT => '/dashboard',
                self::COORDINATOR => '/internship-assignment',
                self::SUPERVISOR => '/progress-monitoring',
                self::ADMIN => '/internship-assignment',
            ],
            'student_registered' => [
                self::STUDENT => '/notifications',
                self::COORDINATOR => '/internship-assignment',
                self::SUPERVISOR => '/notifications',
                self::ADMIN => '/internship-assignment',
            ],
        ];

        $cases = [];

        foreach ($expected as $type => $byRole) {
            foreach ($byRole as $roleId => $path) {
                $cases["{$type} as role {$roleId}"] = [$type, $roleId, $path];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_resolver_maps_type_and_viewer_role_to_the_expected_path(string $type, int $roleId, string $expectedPath): void
    {
        $viewer = $this->viewer($roleId);

        $url = $this->resolve($type, [self::dataKeyFor($type) => self::DATA_ID], $viewer);

        $this->assertSame($expectedPath, $this->pathOf($url));
    }

    #[DataProvider('matrix')]
    public function test_every_resolved_url_is_reachable_by_the_viewer(string $type, int $roleId, string $expectedPath): void
    {
        $viewer = $this->viewer($roleId);

        $url = $this->resolve($type, [self::dataKeyFor($type) => self::DATA_ID], $viewer);

        $this->actingAs($viewer)->get($url)->assertOk();
    }

    public function test_unknown_type_falls_back_to_the_inbox(): void
    {
        $viewer = $this->viewer(self::ADMIN);

        $url = $this->resolve('something_new', ['report_id' => 5], $viewer);

        $this->assertSame('/notifications', $this->pathOf($url));
    }

    public function test_missing_or_invalid_data_id_falls_back_to_the_inbox(): void
    {
        $admin = $this->viewer(self::ADMIN);
        $student = $this->viewer(self::STUDENT);

        $this->assertSame('/notifications', $this->pathOf($this->resolve('report_submitted', [], $admin)));
        $this->assertSame('/notifications', $this->pathOf($this->resolve('attendance_pending', ['attendance_id' => null], $admin)));
        $this->assertSame('/notifications', $this->pathOf($this->resolve('report_reviewed', ['report_id' => 'abc'], $student)));
        $this->assertSame('/notifications', $this->pathOf($this->resolve('attendance_reviewed', ['attendance_id' => 0], $student)));
        $this->assertSame('/notifications', $this->pathOf($this->resolve('assignment_updated', ['user_id' => 3], $student)));
    }

    public function test_url_is_resolved_for_the_viewers_current_role_not_the_recipients(): void
    {
        $supervisor = $this->viewer(self::SUPERVISOR);
        $studentUser = $this->viewer(self::STUDENT);

        $notification = Notification::factory()->create([
            'user_id' => $studentUser->id,
            'type' => 'report_reviewed',
            'data' => ['report_id' => 9],
        ]);

        $service = app(NotificationService::class);

        $this->assertSame('/my-reports?highlight=9', $this->pathOf($service->urlFor($notification, $studentUser)));
        $this->assertSame('/report-reviews?highlight=9', $this->pathOf($service->urlFor($notification, $supervisor)));
    }

    public function test_open_marks_an_owned_notification_read_and_redirects_to_its_url(): void
    {
        $supervisor = $this->viewer(self::SUPERVISOR);
        $notification = Notification::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'attendance_pending',
            'data' => ['attendance_id' => 15],
        ]);

        $this->actingAs($supervisor)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('attendance-approvals.index', ['highlight' => 15]));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_open_keeps_an_already_read_notification_read(): void
    {
        $student = $this->viewer(self::STUDENT);
        $readAt = now()->subDay()->startOfSecond();
        $notification = Notification::factory()->create([
            'user_id' => $student->id,
            'type' => 'report_reviewed',
            'data' => ['report_id' => 3],
            'read_at' => $readAt,
        ]);

        $this->actingAs($student)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('reports.index', ['highlight' => 3]));

        $this->assertTrue($notification->fresh()->read_at->equalTo($readAt));
    }

    public function test_open_forbids_another_users_notification(): void
    {
        $supervisor = $this->viewer(self::SUPERVISOR);
        $studentUser = $this->viewer(self::STUDENT);
        Student::where('user_id', $studentUser->id)->update(['supervisor_id' => $supervisor->id]);

        $notification = Notification::factory()->create([
            'user_id' => $studentUser->id,
            'type' => 'report_reviewed',
            'data' => ['report_id' => 3],
        ]);

        // Even the student's own supervisor (who sees it in Recent Activity)
        // must not be able to mark it read on the student's behalf.
        $this->actingAs($supervisor)
            ->get(route('notifications.open', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_open_redirects_guests_to_login(): void
    {
        $notification = Notification::factory()->create();

        $this->get(route('notifications.open', $notification))
            ->assertRedirect(route('login'));

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_shared_recent_notifications_include_url(): void
    {
        $admin = $this->viewer(self::ADMIN);
        Notification::factory()->create([
            'user_id' => $admin->id,
            'type' => 'report_submitted',
            'data' => ['report_id' => 11],
        ]);

        $this->actingAs($admin)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentNotifications', 1)
                ->where('recentNotifications.0.url', route('report-reviews.index', ['highlight' => 11]))
            );
    }

    public function test_notifications_index_items_include_url_and_keep_paginator_shape(): void
    {
        $student = $this->viewer(self::STUDENT);
        Notification::factory()->create([
            'user_id' => $student->id,
            'type' => 'attendance_reviewed',
            'data' => ['attendance_id' => 8],
        ]);

        $this->actingAs($student)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 1)
                ->has('notifications.current_page')
                ->has('notifications.last_page')
                ->has('notifications.links')
                ->where('notifications.data.0.url', route('attendance.index', ['highlight' => 8]))
                ->where('notifications.data.0.user_id', $student->id)
                ->where('notifications.data.0.type', 'attendance_reviewed')
            );
    }

    public function test_supervisor_recent_activity_resolves_student_items_for_the_supervisor(): void
    {
        $supervisor = $this->viewer(self::SUPERVISOR);
        $studentUser = $this->viewer(self::STUDENT);
        Student::where('user_id', $studentUser->id)->update(['supervisor_id' => $supervisor->id]);

        Notification::factory()->create([
            'user_id' => $studentUser->id,
            'type' => 'report_reviewed',
            'data' => ['report_id' => 21],
            'created_at' => now()->subMinute(),
        ]);
        Notification::factory()->create([
            'user_id' => $supervisor->id,
            'type' => 'attendance_pending',
            'data' => ['attendance_id' => 31],
            'created_at' => now(),
        ]);

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentActivity', 2)
                ->where('recentActivity.0.user_id', $supervisor->id)
                ->where('recentActivity.0.url', route('attendance-approvals.index', ['highlight' => 31]))
                ->where('recentActivity.1.user_id', $studentUser->id)
                ->where('recentActivity.1.url', route('report-reviews.index', ['highlight' => 21]))
            );
    }
}
