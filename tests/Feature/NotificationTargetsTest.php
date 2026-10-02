<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NotificationService::targetFor() — the mobile app's tap destination —
 * for every notification type x role, and its parity with the web's
 * urlFor().
 */
class NotificationTargetsTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENT = 1;

    private const COORDINATOR = 2;

    private const SUPERVISOR = 3;

    private const ADMIN = 4;

    private const DATA_ID = 42;

    /**
     * Mobile screen key => the web path it is equivalent to.
     */
    private const SCREEN_PATHS = [
        'approvals' => '/attendance-approvals',
        'attendance-monitoring' => '/attendance-monitoring',
        'attendance' => '/my-attendance',
        'report-reviews' => '/report-reviews',
        'my-reports' => '/my-reports',
        'home' => '/dashboard',
        'progress' => '/progress-monitoring',
        'students' => '/internship-assignment',
    ];

    /**
     * Screens each role can open in the app (docs/MOBILE-APP-ROADMAP.md
     * access table). A target must never point a role elsewhere.
     */
    private const ALLOWED_SCREENS = [
        self::STUDENT => ['home', 'attendance', 'my-reports'],
        self::SUPERVISOR => ['home', 'approvals', 'attendance-monitoring', 'progress', 'report-reviews'],
        self::COORDINATOR => ['home', 'attendance-monitoring', 'progress', 'report-reviews', 'students'],
        self::ADMIN => ['home', 'approvals', 'attendance-monitoring', 'progress', 'report-reviews', 'students'],
    ];

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

    private function notification(string $type, array $data, User $owner): Notification
    {
        return Notification::factory()->create([
            'user_id' => $owner->id,
            'type' => $type,
            'data' => $data,
        ]);
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
     * Every type x role => expected target (null = no screen; web shows the inbox).
     *
     * @return array<string, array{0: string, 1: int, 2: array{screen: string, params: array<string, int>}|null}>
     */
    public static function matrix(): array
    {
        $att = ['attendance_id' => self::DATA_ID];
        $rep = ['report_id' => self::DATA_ID];

        $expected = [
            'attendance_pending' => [
                self::STUDENT => null,
                self::COORDINATOR => ['screen' => 'attendance-monitoring', 'params' => []],
                self::SUPERVISOR => ['screen' => 'approvals', 'params' => $att],
                self::ADMIN => ['screen' => 'approvals', 'params' => $att],
            ],
            'attendance_reviewed' => [
                self::STUDENT => ['screen' => 'attendance', 'params' => $att],
                self::COORDINATOR => ['screen' => 'attendance-monitoring', 'params' => []],
                self::SUPERVISOR => ['screen' => 'attendance-monitoring', 'params' => []],
                self::ADMIN => ['screen' => 'attendance-monitoring', 'params' => []],
            ],
            'report_submitted' => [
                self::STUDENT => null,
                self::COORDINATOR => ['screen' => 'report-reviews', 'params' => $rep],
                self::SUPERVISOR => ['screen' => 'report-reviews', 'params' => $rep],
                self::ADMIN => ['screen' => 'report-reviews', 'params' => $rep],
            ],
            'report_reviewed' => [
                self::STUDENT => ['screen' => 'my-reports', 'params' => $rep],
                self::COORDINATOR => ['screen' => 'report-reviews', 'params' => $rep],
                self::SUPERVISOR => ['screen' => 'report-reviews', 'params' => $rep],
                self::ADMIN => ['screen' => 'report-reviews', 'params' => $rep],
            ],
            'assignment_updated' => [
                self::STUDENT => ['screen' => 'home', 'params' => []],
                self::COORDINATOR => ['screen' => 'students', 'params' => []],
                self::SUPERVISOR => ['screen' => 'progress', 'params' => []],
                self::ADMIN => ['screen' => 'students', 'params' => []],
            ],
            'student_registered' => [
                self::STUDENT => null,
                self::COORDINATOR => ['screen' => 'students', 'params' => []],
                self::SUPERVISOR => null,
                self::ADMIN => ['screen' => 'students', 'params' => []],
            ],
        ];

        $cases = [];

        foreach ($expected as $type => $byRole) {
            foreach ($byRole as $roleId => $target) {
                $cases["{$type} as role {$roleId}"] = [$type, $roleId, $target];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_target_for_every_type_and_role(string $type, int $roleId, ?array $expected): void
    {
        $viewer = $this->viewer($roleId);
        $notification = $this->notification($type, [self::dataKeyFor($type) => self::DATA_ID], $viewer);

        $this->assertSame($expected, app(NotificationService::class)->targetFor($notification, $viewer));
    }

    #[DataProvider('matrix')]
    public function test_target_matches_the_web_url(string $type, int $roleId): void
    {
        $viewer = $this->viewer($roleId);
        $notification = $this->notification($type, [self::dataKeyFor($type) => self::DATA_ID], $viewer);
        $service = app(NotificationService::class);

        $target = $service->targetFor($notification, $viewer);
        $url = parse_url($service->urlFor($notification, $viewer));
        parse_str($url['query'] ?? '', $query);

        if ($target === null) {
            $this->assertSame('/notifications', $url['path']);
            $this->assertSame([], $query);

            return;
        }

        $this->assertSame(self::SCREEN_PATHS[$target['screen']], $url['path']);

        // The app highlights exactly where the web does, with the same id.
        if (isset($query['highlight'])) {
            $this->assertSame([self::dataKeyFor($type) => (int) $query['highlight']], $target['params']);
        } else {
            $this->assertSame([], $target['params']);
        }
    }

    #[DataProvider('matrix')]
    public function test_target_is_a_screen_the_role_can_open(string $type, int $roleId, ?array $expected): void
    {
        if ($expected === null) {
            $this->addToAssertionCount(1); // no screen at all is always allowed

            return;
        }

        $this->assertContains($expected['screen'], self::ALLOWED_SCREENS[$roleId]);
    }

    public function test_every_screen_key_maps_to_a_web_route_the_matrix_covers(): void
    {
        $screens = collect(self::matrix())->pluck(2)->filter()->pluck('screen')->unique()->sort()->values()->all();

        $this->assertSame(collect(array_keys(self::SCREEN_PATHS))->sort()->values()->all(), $screens);
    }

    public function test_unknown_type_has_no_target(): void
    {
        $admin = $this->viewer(self::ADMIN);

        $this->assertNull(app(NotificationService::class)->targetFor(
            $this->notification('something_new', ['report_id' => 5], $admin),
            $admin,
        ));
    }

    public function test_missing_or_invalid_data_id_has_no_target(): void
    {
        $admin = $this->viewer(self::ADMIN);
        $student = $this->viewer(self::STUDENT);
        $service = app(NotificationService::class);

        $cases = [
            ['report_submitted', [], $admin],
            ['attendance_pending', ['attendance_id' => null], $admin],
            ['report_reviewed', ['report_id' => 'abc'], $student],
            ['attendance_reviewed', ['attendance_id' => 0], $student],
            ['attendance_reviewed', ['attendance_id' => -3], $student],
            ['assignment_updated', ['user_id' => 3], $student],
        ];

        foreach ($cases as [$type, $data, $viewer]) {
            $this->assertNull($service->targetFor($this->notification($type, $data, $viewer), $viewer), $type);
        }
    }

    public function test_numeric_string_id_is_returned_as_an_int(): void
    {
        $student = $this->viewer(self::STUDENT);

        $target = app(NotificationService::class)->targetFor(
            $this->notification('report_reviewed', ['report_id' => '17'], $student),
            $student,
        );

        $this->assertSame(['screen' => 'my-reports', 'params' => ['report_id' => 17]], $target);
    }

    public function test_target_is_resolved_for_the_viewers_current_role(): void
    {
        $studentUser = $this->viewer(self::STUDENT);
        $notification = $this->notification('report_reviewed', ['report_id' => 9], $studentUser);
        $service = app(NotificationService::class);

        $this->assertSame('my-reports', $service->targetFor($notification, $studentUser)['screen']);

        // Same row, viewer now holds another role (e.g. promoted).
        $studentUser->update(['role_id' => self::COORDINATOR]);
        $this->assertSame('report-reviews', $service->targetFor($notification, $studentUser->fresh())['screen']);
    }

    public function test_viewer_without_a_role_has_no_target(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->assertNull(app(NotificationService::class)->targetFor(
            $this->notification('report_reviewed', ['report_id' => 9], $user),
            $user,
        ));
    }
}
