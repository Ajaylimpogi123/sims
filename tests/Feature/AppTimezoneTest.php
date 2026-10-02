<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The program runs in the Philippines, so attendance dates and time-in/out
 * strings must be written in Asia/Manila — not UTC, which would file an
 * early-morning time-in under the previous day and show it 8 hours early.
 */
class AppTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_app_timezone_is_asia_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function test_early_morning_time_in_is_recorded_with_the_manila_date_and_time(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        // 01:00 on Oct 3 in Manila is still 17:00 on Oct 2 in UTC.
        Carbon::setTestNow(Carbon::create(2026, 10, 3, 1, 0, 0, 'Asia/Manila'));

        $user = User::factory()->create(['role_id' => 1]);
        $student = Student::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/my-attendance/time-in', [
                'photo' => UploadedFile::fake()->image('capture.jpg', 640, 480),
                'latitude' => '10.6765432',
                'longitude' => '122.9509876',
                'accuracy' => '12.5',
            ])
            ->assertRedirect(route('attendance.index'))
            ->assertSessionHas('success');

        $attendance = Attendance::where('student_id', $student->id)->sole();

        $this->assertSame('2026-10-03', $attendance->date->toDateString());
        $this->assertSame('01:00:00', $attendance->time_in);
    }
}
