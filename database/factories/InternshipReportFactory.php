<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InternshipReport>
 */
class InternshipReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-2 weeks', 'now');

        return [
            'student_id' => Student::factory(),
            'type' => fake()->randomElement(['daily', 'weekly']),
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodStart->format('Y-m-d'),
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
