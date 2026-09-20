<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role_id' => 1]),
            'student_number' => fake()->unique()->numerify('####-#####'),
            'course' => fake()->randomElement(['BSIT', 'BSCS', 'BSCE', 'BSA']),
            'section' => fake()->randomElement(['A', 'B', 'C']),
            'company_id' => null,
            'internship_schedule' => null,
            'supervisor_id' => null,
            'internship_status' => 'not_started',
            'required_hours' => 486,
        ];
    }
}
