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
     * Define the model's default state. Ongoing by default: the only status
     * in which a student may submit attendance and reports (the column's
     * own default for a new registration is not_started).
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
            'internship_status' => 'ongoing',
            'required_hours' => 486,
        ];
    }

    public function notStarted(): static
    {
        return $this->state(['internship_status' => 'not_started']);
    }

    public function ongoing(): static
    {
        return $this->state(['internship_status' => 'ongoing']);
    }

    public function completed(): static
    {
        return $this->state(['internship_status' => 'completed']);
    }
}
