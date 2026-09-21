<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Evaluation>
 */
class EvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-2 months', '-1 month');
        $periodEnd = fake()->dateTimeBetween($periodStart, 'now');

        return [
            'student_id' => Student::factory(),
            'company_id' => null,
            'supervisor_id' => null,
            'evaluation_period_start' => $periodStart->format('Y-m-d'),
            'evaluation_period_end' => $periodEnd->format('Y-m-d'),
            'overall_rating' => null,
            'strengths' => null,
            'areas_for_improvement' => null,
            'recommendations' => null,
            'supervisor_remarks' => null,
            'status' => 'draft',
            'submitted_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }
}
