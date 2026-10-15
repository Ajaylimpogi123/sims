<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Coordinators and Administrators set a student's required hours in the
 * Internship Assignment save (website + API), with the Monitoring rules,
 * and Ongoing is checked against the hours the same save leaves.
 */
class AssignmentRequiredHoursTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = 'Set the required hours before marking the internship Ongoing.';

    private const LOCKED = "Required hours can't be cleared while the internship is Ongoing.";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function user(int $roleId): User
    {
        return User::factory()->create(['role_id' => $roleId, 'status' => 'active']);
    }

    private function payload(Student $student, array $overrides = []): array
    {
        $student->refresh()->load('user');

        return array_merge([
            'name' => $student->user->name,
            'email' => $student->user->email,
            'student_number' => $student->student_number,
            'course' => $student->course,
            'section' => $student->section,
            'company_id' => $student->company_id,
            'supervisor_id' => $student->supervisor_id,
            'internship_status' => $student->internship_status,
            'internship_schedule' => $student->internship_schedule,
            'required_hours' => $student->required_hours,
        ], $overrides);
    }

    private function save(string $channel, Student $student, array $payload, ?User $actor = null): TestResponse
    {
        $actor ??= $this->user(User::ROLE_COORDINATOR);

        if ($channel === 'web') {
            return $this->actingAs($actor)->patch("/internship-assignment/{$student->id}", $payload);
        }

        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$actor->createToken('test')->plainTextToken,
        ])->json('PATCH', "/api/v1/assignments/{$student->id}", $payload);
    }

    private function assertSaved(string $channel, TestResponse $response): void
    {
        $channel === 'web'
            ? $response->assertSessionHasNoErrors()->assertRedirect()
            : $response->assertOk();
    }

    public static function channels(): array
    {
        return ['web' => ['web'], 'api' => ['api']];
    }

    public static function channelsAndRoles(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['coordinator' => User::ROLE_COORDINATOR, 'admin' => User::ROLE_ADMIN] as $label => $role) {
                $cases["{$channel} {$label}"] = [$channel, $role];
            }
        }

        return $cases;
    }

    // ------------------------------------------------------- set it

    #[DataProvider('channelsAndRoles')]
    public function test_coordinator_and_admin_set_required_hours(string $channel, int $role): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => null]);

        $response = $this->save($channel, $student, $this->payload($student, ['required_hours' => '486']), $this->user($role));

        $this->assertSaved($channel, $response);
        if ($channel === 'api') {
            $response->assertJsonPath('student.required_hours', 486);
        }
        $this->assertSame(486, $student->fresh()->required_hours);
    }

    public static function forbiddenRoles(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['student' => User::ROLE_STUDENT, 'supervisor' => User::ROLE_SUPERVISOR] as $label => $role) {
                $cases["{$channel} {$label}"] = [$channel, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('forbiddenRoles')]
    public function test_students_and_supervisors_cannot_use_the_assignment_save(string $channel, int $role): void
    {
        $student = Student::factory()->create(['required_hours' => 486]);

        $this->save($channel, $student, $this->payload($student, ['required_hours' => 100]), $this->user($role))
            ->assertForbidden();

        $this->assertSame(486, $student->fresh()->required_hours);
    }

    public static function storedValues(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach ([
                'number' => [300, 300],
                'numeric string' => ['250', 250],
                'column max' => [4294967295, 4294967295],
                'zero' => [0, null],
                'zero string' => ['0', null],
                'minus zero' => ['-0', null],
                'plus zero' => ['+0', null],
                'empty' => ['', null],
                'null' => [null, null],
            ] as $label => [$value, $stored]) {
                $cases["{$channel} {$label}"] = [$channel, $value, $stored];
            }
        }

        return $cases;
    }

    #[DataProvider('storedValues')]
    public function test_values_are_stored_like_monitoring(string $channel, mixed $value, ?int $stored): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => 486]);

        $this->assertSaved($channel, $this->save($channel, $student, $this->payload($student, ['required_hours' => $value])));

        $this->assertSame($stored, $student->fresh()->required_hours);
    }

    public static function badValues(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach ([
                'negative' => -1,
                'fraction' => 1.5,
                'text' => 'abc',
                'bool' => true,
                'array' => [100],
                'over the column' => 4294967296,
                'huge' => '99999999999999999999',
            ] as $label => $value) {
                $cases["{$channel} {$label}"] = [$channel, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('badValues')]
    public function test_bad_values_are_refused(string $channel, mixed $value): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => 486]);

        $response = $this->save($channel, $student, $this->payload($student, ['required_hours' => $value, 'course' => 'CHANGED']));

        $channel === 'web'
            ? $response->assertSessionHasErrors('required_hours')
            : $response->assertStatus(422)->assertJsonValidationErrors('required_hours')->assertJsonMissingPath('code');

        $this->assertSame(486, $student->fresh()->required_hours);
        $this->assertNotSame('CHANGED', $student->fresh()->course);
    }

    #[DataProvider('channels')]
    public function test_an_omitted_field_keeps_the_stored_value(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => 486]);
        $payload = $this->payload($student, ['course' => 'CHANGED']);
        unset($payload['required_hours']);

        $this->assertSaved($channel, $this->save($channel, $student, $payload));

        $this->assertSame(486, $student->fresh()->required_hours);
        $this->assertSame('CHANGED', $student->fresh()->course);
    }

    // ------------------------------------------------------ ongoing

    #[DataProvider('channels')]
    public function test_hours_and_ongoing_can_be_set_in_one_save(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => null]);

        $this->assertSaved($channel, $this->save($channel, $student, $this->payload($student, [
            'internship_status' => 'ongoing',
            'required_hours' => '486',
        ])));

        $fresh = $student->fresh();
        $this->assertSame('ongoing', $fresh->internship_status);
        $this->assertSame(486, $fresh->required_hours);
    }

    public static function emptyHours(): array
    {
        $cases = [];

        foreach (['web', 'api'] as $channel) {
            foreach (['null' => null, 'empty' => '', 'zero' => 0, 'minus zero' => '-0', 'omitted' => 'omit'] as $label => $value) {
                $cases["{$channel} {$label}"] = [$channel, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('emptyHours')]
    public function test_ongoing_without_hours_in_the_same_save_is_refused(string $channel, mixed $value): void
    {
        // Stored hours being cleared in the same save don't count either.
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => $value === 'omit' ? null : 486]);
        $payload = $this->payload($student, ['internship_status' => 'ongoing', 'required_hours' => $value]);

        if ($value === 'omit') {
            unset($payload['required_hours']);
        }

        $response = $this->save($channel, $student, $payload);

        if ($channel === 'web') {
            $response->assertSessionHasErrors(['internship_status' => self::MISSING]);
        } else {
            $response->assertStatus(422)->assertExactJson([
                'message' => self::MISSING,
                'code' => 'required_hours_missing',
                'errors' => ['internship_status' => [self::MISSING]],
            ]);
        }

        $fresh = $student->fresh();
        $this->assertSame('not_started', $fresh->internship_status);
        $this->assertSame($value === 'omit' ? null : 486, $fresh->required_hours);
    }

    #[DataProvider('channels')]
    public function test_omitted_hours_fall_back_to_the_stored_value_for_ongoing(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => 300]);
        $payload = $this->payload($student, ['internship_status' => 'ongoing']);
        unset($payload['required_hours']);

        $this->assertSaved($channel, $this->save($channel, $student, $payload));

        $this->assertSame('ongoing', $student->fresh()->internship_status);
        $this->assertSame(300, $student->fresh()->required_hours);
    }

    #[DataProvider('channels')]
    public function test_an_ongoing_students_hours_cannot_be_cleared_here(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => 486]);

        $response = $this->save($channel, $student, $this->payload($student, ['required_hours' => '', 'course' => 'CHANGED']));

        if ($channel === 'web') {
            $response->assertSessionHasErrors(['required_hours' => self::LOCKED]);
        } else {
            $response->assertStatus(422)->assertExactJson([
                'message' => self::LOCKED,
                'code' => 'required_hours_locked',
                'errors' => ['required_hours' => [self::LOCKED]],
            ]);
        }

        $this->assertSame(486, $student->fresh()->required_hours);
        $this->assertNotSame('CHANGED', $student->fresh()->course);
    }

    #[DataProvider('channels')]
    public function test_an_ongoing_students_hours_can_be_changed_or_cleared_when_leaving_ongoing(string $channel): void
    {
        $changed = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => 486]);
        $leaving = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => 486]);

        $this->assertSaved($channel, $this->save($channel, $changed, $this->payload($changed, ['required_hours' => 600])));
        $this->assertSaved($channel, $this->save($channel, $leaving, $this->payload($leaving, [
            'internship_status' => 'completed',
            'required_hours' => '',
        ])));

        $this->assertSame(600, $changed->fresh()->required_hours);
        $this->assertNull($leaving->fresh()->required_hours);
    }

    #[DataProvider('channels')]
    public function test_a_legacy_ongoing_student_without_hours_can_still_be_saved(string $channel): void
    {
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => null]);

        $this->assertSaved($channel, $this->save($channel, $student, $this->payload($student, ['required_hours' => '', 'course' => 'CHANGED'])));

        $this->assertSame('CHANGED', $student->fresh()->course);
        $this->assertNull($student->fresh()->required_hours);
    }

    public function test_website_and_api_store_the_same_values(): void
    {
        $web = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => null]);
        $api = Student::factory()->create(['internship_status' => 'not_started', 'required_hours' => null]);

        foreach (['+300', '0', '486'] as $value) {
            $this->assertSaved('web', $this->save('web', $web, $this->payload($web, ['required_hours' => $value])));
            $this->assertSaved('api', $this->save('api', $api, $this->payload($api, ['required_hours' => $value])));

            $this->assertSame($web->fresh()->required_hours, $api->fresh()->required_hours, "value {$value}");
        }

        $this->assertSame(486, $api->fresh()->required_hours);
    }

    public function test_monitoring_keeps_setting_required_hours_for_supervisors(): void
    {
        $supervisor = $this->user(User::ROLE_SUPERVISOR);
        $student = Student::factory()->create(['internship_status' => 'ongoing', 'required_hours' => 486, 'supervisor_id' => $supervisor->id]);

        $this->actingAs($supervisor)
            ->patch("/attendance-monitoring/{$student->id}/required-hours", ['required_hours' => '500'])
            ->assertSessionHasNoErrors();
        $this->actingAs($supervisor)
            ->patch("/attendance-monitoring/{$student->id}/required-hours", ['required_hours' => ''])
            ->assertSessionHasErrors(['required_hours' => self::LOCKED]);

        $this->assertSame(500, $student->fresh()->required_hours);
    }
}
