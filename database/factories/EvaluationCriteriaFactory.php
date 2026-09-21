<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EvaluationCriteria>
 */
class EvaluationCriteriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->sentence(6),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement([
                'Attendance & Punctuality',
                'Work Quality',
                'Productivity',
                'Communication',
                'Teamwork',
                'Professionalism',
                'Initiative',
                'Technical Skills',
                'Adaptability',
                'Responsibility',
            ]),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
