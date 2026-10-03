<?php

namespace Database\Factories;

use App\Models\InternshipReport;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InternshipReport>
 */
class InternshipReportFactory extends Factory
{
    /**
     * (student_id, type, period_start) combos already handed out in this
     * process, so a batch made before any of it is saved (count(3)) can't
     * collide either.
     *
     * @var array<string, true>
     */
    private static array $issued = [];

    /**
     * Define the model's default state.
     *
     * period_start / period_end are resolved after student_id and type, and
     * only when not given explicitly: the default never collides with the
     * internship_reports_student_period_type_unique index.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => fake()->randomElement(['daily', 'weekly']),
            'period_start' => fn (array $attributes) => self::freePeriodStart($attributes['student_id'], $attributes['type']),
            'period_end' => fn (array $attributes) => Carbon::parse($attributes['period_start'])->format('Y-m-d'),
            'content' => fake()->paragraph(),
            'attachment_path' => null,
            'attachment_original_name' => null,
            'status' => 'pending',
            'reviewer_comment' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * A random date in the last two weeks that the student has no report of
     * this type for yet (stepping further back once those are used up).
     */
    private static function freePeriodStart(mixed $studentId, string $type): string
    {
        $taken = InternshipReport::query()
            ->where('student_id', $studentId)
            ->where('type', $type)
            ->pluck('period_start')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
            ->flip();

        $isFree = fn (string $date) => ! isset($taken[$date]) && ! isset(self::$issued["{$studentId}|{$type}|{$date}"]);

        $candidates = collect(range(0, 14))
            ->map(fn (int $daysAgo) => today()->subDays($daysAgo)->format('Y-m-d'))
            ->filter($isFree)
            ->values();

        $date = $candidates->isNotEmpty() ? $candidates->random() : null;

        for ($daysAgo = 15; $date === null; $daysAgo++) {
            $candidate = today()->subDays($daysAgo)->format('Y-m-d');
            $date = $isFree($candidate) ? $candidate : null;
        }

        self::$issued["{$studentId}|{$type}|{$date}"] = true;

        return $date;
    }

    /**
     * Indicate that the report has been reviewed.
     */
    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reviewed',
            'reviewer_comment' => fake()->sentence(),
            'reviewed_at' => now(),
        ]);
    }
}
