<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Time In / Time Out / Emergency Time-Out require a live photo + GPS
 * coordinates; the photo is served only through the scoped attendance.photo
 * route.
 */
class AttendanceCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function studentUser(array $studentOverrides = []): User
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(array_merge(['user_id' => $user->id], $studentOverrides));

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'photo' => UploadedFile::fake()->image('capture.jpg', 640, 480),
            'latitude' => '10.6765432',
            'longitude' => '122.9509876',
            'accuracy' => '12.5',
        ], $overrides);
    }

    /**
     * Put today's record into the state each action needs to succeed, and
     * return the URL + any extra fields that action requires.
     *
     * @return array{0: string, 1: array}
     */
    private function prepareAction(string $action, Student $student): array
    {
        return match ($action) {
            'time_in' => ['/my-attendance/time-in', []],
            'time_out' => [
                '/my-attendance/time-out',
                tap([], fn () => Attendance::factory()->create([
                    'student_id' => $student->id,
                    'date' => today()->toDateString(),
                    'time_in' => '08:00:00',
                    'time_in_status' => 'approved',
                    'time_out' => null,
                    'time_out_status' => null,
                    'rendered_hours' => null,
                ])),
            ],
            'emergency' => [
                '/my-attendance/emergency-time-out',
                tap(['note' => 'Family emergency.'], fn () => Attendance::factory()->create([
                    'student_id' => $student->id,
                    'date' => today()->toDateString(),
                    'time_in' => '08:00:00',
                    'time_in_status' => 'pending',
                    'time_out' => null,
                    'time_out_status' => null,
                    'rendered_hours' => null,
                ])),
            ],
        };
    }

    public static function actions(): array
    {
        return [
            'time in' => ['time_in', 'time_in'],
            'time out' => ['time_out', 'time_out'],
            'emergency time-out' => ['emergency', 'time_out'],
        ];
    }

    public static function invalidCaptures(): array
    {
        $cases = [];

        foreach (self::actions() as $label => [$action]) {
            $cases["{$label}: missing photo"] = [$action, ['photo' => null], 'photo'];
            $cases["{$label}: non-image photo"] = [$action, ['photo' => 'pdf'], 'photo'];
            $cases["{$label}: missing latitude"] = [$action, ['latitude' => null], 'latitude'];
            $cases["{$label}: latitude out of range"] = [$action, ['latitude' => '90.5'], 'latitude'];
            $cases["{$label}: non-numeric latitude"] = [$action, ['latitude' => 'north'], 'latitude'];
            $cases["{$label}: missing longitude"] = [$action, ['longitude' => null], 'longitude'];
            $cases["{$label}: longitude out of range"] = [$action, ['longitude' => '-180.5'], 'longitude'];
            $cases["{$label}: negative accuracy"] = [$action, ['accuracy' => '-1'], 'accuracy'];
        }

        return $cases;
    }

    #[DataProvider('invalidCaptures')]
    public function test_capture_actions_reject_invalid_photo_or_location(string $action, array $override, string $errorKey): void
    {
        $user = $this->studentUser();
        [$url, $extra] = $this->prepareAction($action, $user->student);

        if (($override['photo'] ?? null) === 'pdf') {
            $override['photo'] = UploadedFile::fake()->create('capture.pdf', 10, 'application/pdf');
        }

        $payload = array_filter(
            array_merge($this->validPayload(), $extra, $override),
            fn ($value) => $value !== null
        );

        $before = Attendance::query()->get()->toArray();

        $this->actingAs($user)
            ->from('/my-attendance')
            ->post($url, $payload)
            ->assertRedirect('/my-attendance')
            ->assertSessionHasErrors($errorKey);

        $this->assertSame($before, Attendance::query()->get()->toArray());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_emergency_time_out_still_requires_a_note(): void
    {
        $user = $this->studentUser();
        [$url] = $this->prepareAction('emergency', $user->student);

        $this->actingAs($user)
            ->post($url, $this->validPayload())
            ->assertSessionHasErrors('note');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('actions')]
    public function test_valid_capture_stores_the_photo_and_coordinates_on_the_matching_leg(string $action, string $leg): void
    {
        $user = $this->studentUser();
        $student = $user->student;
        [$url, $extra] = $this->prepareAction($action, $student);

        $this->actingAs($user)
            ->post($url, array_merge($this->validPayload(), $extra))
            ->assertRedirect(route('attendance.index', absolute: false))
            ->assertSessionHas('success');

        $record = Attendance::where('student_id', $student->id)
            ->where('date', today()->toDateString())
            ->firstOrFail();

        $path = $record->{"{$leg}_photo_path"};
        $this->assertNotNull($path);
        $this->assertStringStartsWith("attendance-photos/{$student->id}/", $path);
        Storage::disk('local')->assertExists($path);

        $this->assertEquals(10.6765432, (float) $record->{"{$leg}_latitude"});
        $this->assertEquals(122.9509876, (float) $record->{"{$leg}_longitude"});
        $this->assertEquals(12.5, (float) $record->{"{$leg}_accuracy"});
        $this->assertSame('pending', $record->{"{$leg}_status"});

        $otherLeg = $leg === 'time_in' ? 'time_out' : 'time_in';
        $this->assertNull($record->{"{$otherLeg}_photo_path"});
        $this->assertNull($record->{"{$otherLeg}_latitude"});
    }

    public static function oversizedAccuracies(): array
    {
        return [
            'one million metres' => ['1000000'],
            'rounds up past the column max' => ['999999.995'],
            'exponent notation' => ['1e30'],
        ];
    }

    #[DataProvider('oversizedAccuracies')]
    public function test_accuracy_above_the_column_max_is_clamped_instead_of_failing(string $accuracy): void
    {
        // Regression (QA BUG-B1): decimal(8,2) overflowed with a 500, and
        // the browser would resend the same value on every retry.
        $user = $this->studentUser();

        $this->actingAs($user)
            ->post('/my-attendance/time-in', $this->validPayload(['accuracy' => $accuracy]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $record = Attendance::where('student_id', $user->student->id)->firstOrFail();
        $this->assertSame('999999.99', $record->time_in_accuracy);
        Storage::disk('local')->assertExists($record->time_in_photo_path);
    }

    public function test_accuracy_is_optional(): void
    {
        $user = $this->studentUser();
        $payload = $this->validPayload();
        unset($payload['accuracy']);

        $this->actingAs($user)
            ->post('/my-attendance/time-in', $payload)
            ->assertSessionHas('success');

        $record = Attendance::where('student_id', $user->student->id)->firstOrFail();
        $this->assertNull($record->time_in_accuracy);
        Storage::disk('local')->assertExists($record->time_in_photo_path);
    }

    public function test_empty_string_accuracy_is_stored_as_null(): void
    {
        // The capture dialog sends accuracy: '' when the browser reports none.
        $user = $this->studentUser();

        $this->actingAs($user)
            ->post('/my-attendance/time-in', $this->validPayload(['accuracy' => '']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Attendance::where('student_id', $user->student->id)->value('time_in_accuracy'));
    }

    public function test_state_check_failure_does_not_store_a_photo(): void
    {
        $user = $this->studentUser();

        // No approved time-in today -> time-out is refused after validation.
        $this->actingAs($user)
            ->post('/my-attendance/time-out', $this->validPayload())
            ->assertSessionHas('error');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_resubmitting_a_rejected_time_in_replaces_the_old_photo(): void
    {
        $user = $this->studentUser();
        $student = $user->student;
        $oldPath = "attendance-photos/{$student->id}/old-in.jpg";
        Storage::disk('local')->put($oldPath, 'old');

        Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'rejected',
            'time_in_rejection_reason' => 'Blurry photo.',
            'time_in_photo_path' => $oldPath,
            'time_in_latitude' => 1,
            'time_in_longitude' => 1,
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ]);

        $this->actingAs($user)
            ->post('/my-attendance/time-in', $this->validPayload())
            ->assertSessionHas('success');

        $record = Attendance::where('student_id', $student->id)->firstOrFail();

        Storage::disk('local')->assertMissing($oldPath);
        $this->assertNotSame($oldPath, $record->time_in_photo_path);
        Storage::disk('local')->assertExists($record->time_in_photo_path);
        $this->assertEquals(10.6765432, (float) $record->time_in_latitude);
        $this->assertSame('pending', $record->time_in_status);
        $this->assertNull($record->time_in_rejection_reason);
        $this->assertSame(1, Attendance::where('student_id', $student->id)->count());
    }

    public function test_resubmitting_a_rejected_time_out_replaces_only_the_time_out_photo(): void
    {
        $user = $this->studentUser();
        $student = $user->student;
        $inPath = "attendance-photos/{$student->id}/in.jpg";
        $oldOutPath = "attendance-photos/{$student->id}/old-out.jpg";
        Storage::disk('local')->put($inPath, 'in');
        Storage::disk('local')->put($oldOutPath, 'old-out');

        Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in' => '08:00:00',
            'time_in_status' => 'approved',
            'time_in_photo_path' => $inPath,
            'time_out' => '17:00:00',
            'time_out_status' => 'rejected',
            'time_out_photo_path' => $oldOutPath,
            'rendered_hours' => null,
        ]);

        $this->actingAs($user)
            ->post('/my-attendance/emergency-time-out', $this->validPayload(['note' => 'Retry.']))
            ->assertSessionHas('success');

        $record = Attendance::where('student_id', $student->id)->firstOrFail();

        Storage::disk('local')->assertMissing($oldOutPath);
        Storage::disk('local')->assertExists($record->time_out_photo_path);
        Storage::disk('local')->assertExists($inPath);
        $this->assertSame($inPath, $record->time_in_photo_path);
    }

    public function test_deleting_an_attendance_entry_deletes_both_photos(): void
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Storage::disk('local')->put('attendance-photos/x/in.jpg', 'in');
        Storage::disk('local')->put('attendance-photos/x/out.jpg', 'out');

        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'time_in_photo_path' => 'attendance-photos/x/in.jpg',
            'time_out_photo_path' => 'attendance-photos/x/out.jpg',
        ]);

        $this->actingAs($supervisor)
            ->delete("/attendance-monitoring/attendances/{$attendance->id}")
            ->assertSessionHas('success');

        $this->assertModelMissing($attendance);
        Storage::disk('local')->assertMissing('attendance-photos/x/in.jpg');
        Storage::disk('local')->assertMissing('attendance-photos/x/out.jpg');
    }

    public static function clearedLegs(): array
    {
        return [
            'time out cleared' => ['time_out', ['time_in' => '08:00', 'time_out' => '']],
            'time in cleared' => ['time_in', ['time_in' => '', 'time_out' => '']],
        ];
    }

    #[DataProvider('clearedLegs')]
    public function test_clearing_a_leg_in_monitoring_removes_its_evidence(string $clearedLeg, array $times): void
    {
        // Regression (QA BUG-B2): clearing a leg left its photo + GPS behind,
        // still served and shown next to a "-" time.
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Storage::disk('local')->put('attendance-photos/x/in.jpg', 'in');
        Storage::disk('local')->put('attendance-photos/x/out.jpg', 'out');

        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_in_photo_path' => 'attendance-photos/x/in.jpg',
            'time_in_latitude' => 10.5,
            'time_in_longitude' => 122.9,
            'time_in_accuracy' => 5,
            'time_out_photo_path' => 'attendance-photos/x/out.jpg',
            'time_out_latitude' => 10.5,
            'time_out_longitude' => 122.9,
            'time_out_accuracy' => 5,
        ]);

        $this->actingAs($supervisor)
            ->patch("/attendance-monitoring/attendances/{$attendance->id}", array_merge([
                'date' => today()->toDateString(),
            ], $times))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $attendance->refresh();

        foreach (['photo_path', 'latitude', 'longitude', 'accuracy'] as $field) {
            $this->assertNull($attendance->{"{$clearedLeg}_{$field}"}, "{$clearedLeg}_{$field} should be cleared");
        }
        Storage::disk('local')->assertMissing("attendance-photos/x/{$this->shortLeg($clearedLeg)}.jpg");

        if ($clearedLeg === 'time_out') {
            $this->assertSame('attendance-photos/x/in.jpg', $attendance->time_in_photo_path);
            Storage::disk('local')->assertExists('attendance-photos/x/in.jpg');
        }

        $this->actingAs($supervisor)
            ->get($this->photoUrl($attendance, $clearedLeg))
            ->assertNotFound();
    }

    public function test_overriding_a_time_in_monitoring_keeps_that_legs_evidence(): void
    {
        // Deliberate: the photo/GPS document what the student submitted for
        // that leg; a staff time correction doesn't invalidate it.
        $supervisor = User::factory()->create(['role_id' => 3]);
        $student = Student::factory()->create(['supervisor_id' => $supervisor->id]);
        Storage::disk('local')->put('attendance-photos/x/out.jpg', 'out');

        $attendance = Attendance::factory()->create([
            'student_id' => $student->id,
            'date' => today()->toDateString(),
            'time_out_photo_path' => 'attendance-photos/x/out.jpg',
            'time_out_latitude' => 10.5,
        ]);

        $this->actingAs($supervisor)
            ->patch("/attendance-monitoring/attendances/{$attendance->id}", [
                'date' => today()->toDateString(),
                'time_in' => '08:00',
                'time_out' => '16:30',
            ])
            ->assertSessionHas('success');

        $this->assertSame('attendance-photos/x/out.jpg', $attendance->fresh()->time_out_photo_path);
        Storage::disk('local')->assertExists('attendance-photos/x/out.jpg');
    }

    private function shortLeg(string $leg): string
    {
        return $leg === 'time_in' ? 'in' : 'out';
    }

    public function test_deleting_own_account_removes_the_students_attendance_photos(): void
    {
        // Regression (QA BUG-B3): the FK cascade removed the rows but left
        // the images on disk.
        $user = $this->studentUser();
        $other = $this->studentUser();
        $ownDir = "attendance-photos/{$user->student->id}";
        $otherDir = "attendance-photos/{$other->student->id}";
        Storage::disk('local')->put("{$ownDir}/in.jpg", 'in');
        Storage::disk('local')->put("{$otherDir}/in.jpg", 'in');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        Storage::disk('local')->assertMissing("{$ownDir}/in.jpg");
        $this->assertFalse(Storage::disk('local')->directoryExists($ownDir));
        Storage::disk('local')->assertExists("{$otherDir}/in.jpg");
    }

    public function test_failed_account_deletion_keeps_the_photos(): void
    {
        $user = $this->studentUser();
        $path = "attendance-photos/{$user->student->id}/in.jpg";
        Storage::disk('local')->put($path, 'in');

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        Storage::disk('local')->assertExists($path);
    }

    // ---- attendance.photo access matrix ---------------------------------

    /**
     * @return array{0: Attendance, 1: User, 2: User} attendance, owning student user, its supervisor
     */
    private function attendanceWithPhoto(): array
    {
        $supervisor = User::factory()->create(['role_id' => 3]);
        $owner = $this->studentUser(['supervisor_id' => $supervisor->id]);
        $path = "attendance-photos/{$owner->student->id}/in.jpg";
        Storage::disk('local')->put($path, 'jpeg-bytes');

        $attendance = Attendance::factory()->create([
            'student_id' => $owner->student->id,
            'time_in_photo_path' => $path,
            'time_out_photo_path' => null,
        ]);

        return [$attendance, $owner, $supervisor];
    }

    private function photoUrl(Attendance $attendance, string $leg = 'time_in'): string
    {
        return route('attendance.photo', [$attendance->id, $leg], absolute: false);
    }

    public function test_owning_student_can_view_their_photo(): void
    {
        [$attendance, $owner] = $this->attendanceWithPhoto();

        $response = $this->actingAs($owner)->get($this->photoUrl($attendance));

        $response->assertOk();
        $this->assertSame('jpeg-bytes', $response->streamedContent());
    }

    public function test_cache_busting_query_param_is_ignored(): void
    {
        // The frontend appends ?v={updated_at} so a replaced photo is refetched.
        [$attendance, $owner] = $this->attendanceWithPhoto();

        $url = $this->photoUrl($attendance).'?v='.urlencode($attendance->updated_at->toJSON());

        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_other_student_cannot_view_the_photo(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs($this->studentUser())
            ->get($this->photoUrl($attendance))
            ->assertForbidden();
    }

    public function test_student_role_user_without_profile_cannot_view_the_photo(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs(User::factory()->create(['role_id' => 1]))
            ->get($this->photoUrl($attendance))
            ->assertForbidden();
    }

    public function test_assigned_supervisor_can_view_the_photo(): void
    {
        [$attendance, , $supervisor] = $this->attendanceWithPhoto();

        $this->actingAs($supervisor)
            ->get($this->photoUrl($attendance))
            ->assertOk();
    }

    public function test_other_supervisor_cannot_view_the_photo(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs(User::factory()->create(['role_id' => 3]))
            ->get($this->photoUrl($attendance))
            ->assertForbidden();
    }

    public function test_coordinator_can_view_the_photo(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs(User::factory()->create(['role_id' => 2]))
            ->get($this->photoUrl($attendance))
            ->assertOk();
    }

    public function test_admin_can_view_the_photo(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs(User::factory()->create(['role_id' => 4]))
            ->get($this->photoUrl($attendance))
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        [$attendance] = $this->attendanceWithPhoto();

        $this->get($this->photoUrl($attendance))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_leg_without_a_photo_returns_404(): void
    {
        [$attendance, $owner] = $this->attendanceWithPhoto();

        $this->actingAs($owner)
            ->get($this->photoUrl($attendance, 'time_out'))
            ->assertNotFound();
    }

    public function test_other_supervisor_gets_403_not_404_on_a_leg_without_a_photo(): void
    {
        // Authorization runs before the existence check, so an outsider
        // cannot probe which legs have photos.
        [$attendance] = $this->attendanceWithPhoto();

        $this->actingAs(User::factory()->create(['role_id' => 3]))
            ->get($this->photoUrl($attendance, 'time_out'))
            ->assertForbidden();
    }

    public function test_invalid_leg_returns_404(): void
    {
        [$attendance, $owner] = $this->attendanceWithPhoto();

        $this->actingAs($owner)
            ->get("/attendance/{$attendance->id}/photo/note")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/attendance/{$attendance->id}/photo/time_in_photo_path")
            ->assertNotFound();
    }
}
