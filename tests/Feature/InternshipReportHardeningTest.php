<?php

namespace Tests\Feature;

use App\Models\InternshipReport;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Website side of the Module 7 InternshipReportService hardening (shared
 * with the API), and the report factory's unique period combos.
 */
class InternshipReportHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function studentUser(): User
    {
        $user = User::factory()->create(['role_id' => 1]);
        Student::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
            'content' => 'Worked on the reporting module today.',
        ], $overrides);
    }

    public function test_the_website_applies_the_tightened_rules(): void
    {
        $user = $this->studentUser();

        $this->actingAs($user)->post('/my-reports', $this->payload(['period_end' => '2026-01-09']))
            ->assertSessionHasErrors('period_end');
        $this->actingAs($user)->post('/my-reports', $this->payload(['period_start' => '01/05/2026', 'period_end' => '01/05/2026']))
            ->assertSessionHasErrors(['period_start', 'period_end']);
        $this->actingAs($user)->post('/my-reports', $this->payload(['content' => str_repeat('a', 16001)]))
            ->assertSessionHasErrors('content');
        $this->actingAs($user)->post('/my-reports', $this->payload(['attachment' => UploadedFile::fake()->image('huge.png', 8001, 1)]))
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, InternshipReport::count());

        // A weekly report may still span several days.
        $this->actingAs($user)->post('/my-reports', $this->payload(['type' => 'weekly', 'period_end' => '2026-01-09']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, InternshipReport::count());
    }

    public function test_a_website_edit_that_loses_the_race_to_a_review_flashes_an_error(): void
    {
        $user = $this->studentUser();
        $report = InternshipReport::factory()->create([
            'student_id' => $user->student->id,
            'type' => 'daily',
            'period_start' => '2026-01-05',
            'period_end' => '2026-01-05',
        ]);

        $landed = false;
        DB::listen(function ($query) use (&$landed, $report) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            DB::table('internship_reports')->where('id', $report->id)->update(['status' => 'reviewed']);
        });

        $this->actingAs($user)
            ->patch("/my-reports/{$report->id}", $this->payload(['content' => 'Too late']))
            ->assertRedirect(route('reports.index', absolute: false))
            ->assertSessionHas('error', 'This report has already been reviewed and can no longer be changed.');

        $this->assertTrue($landed);
        $this->assertNotSame('Too late', $report->fresh()->content);
    }

    public function test_a_website_delete_that_loses_the_race_to_a_review_flashes_an_error(): void
    {
        $user = $this->studentUser();
        $report = InternshipReport::factory()->create(['student_id' => $user->student->id]);

        $this->actingAs($user);

        $landed = false;
        DB::listen(function ($query) use (&$landed, $report) {
            if ($landed || ! str_contains($query->sql, 'from `internship_reports`')) {
                return;
            }

            $landed = true;
            DB::table('internship_reports')->where('id', $report->id)->update(['status' => 'reviewed']);
        });

        $this->delete("/my-reports/{$report->id}")
            ->assertRedirect(route('reports.index', absolute: false))
            ->assertSessionHas('error');

        $this->assertModelExists($report);
    }

    public function test_the_factory_never_repeats_a_student_type_and_period(): void
    {
        $student = Student::factory()->create();

        InternshipReport::factory()->count(40)->create(['student_id' => $student->id, 'type' => 'daily']);
        InternshipReport::factory()->count(5)->create(['student_id' => $student->id]);
        InternshipReport::factory()->reviewed()->create(['student_id' => $student->id]);

        $combos = InternshipReport::query()
            ->get(['type', 'period_start'])
            ->map(fn ($report) => $report->type.'|'.$report->period_start->format('Y-m-d'));

        $this->assertCount(46, $combos);
        $this->assertSame($combos->count(), $combos->unique()->count());
        $this->assertTrue(InternshipReport::get()->every(fn ($report) => $report->period_end->equalTo($report->period_start)));
    }
}
