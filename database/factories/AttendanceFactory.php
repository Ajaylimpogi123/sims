<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'date' => fake()->unique()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'time_in' => '08:00:00',
            'time_out' => '17:00:00',
            'rendered_hours' => 8,
            'recorded_by' => null,
            'time_in_status' => 'approved',
            'time_in_rejection_reason' => null,
            'time_out_status' => 'approved',
            'time_out_rejection_reason' => null,
            'is_emergency' => false,
            'note' => null,
        ];
    }

    /**
     * Indicate a pending time-in request.
     */
    public function pendingTimeIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_in_status' => 'pending',
            'time_out' => null,
            'time_out_status' => null,
            'rendered_hours' => null,
        ]);
    }

    /**
     * Indicate a pending emergency time-out request.
     */
    public function emergencyTimeOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'time_out_status' => 'pending',
            'is_emergency' => true,
            'note' => fake()->sentence(),
        ]);
    }
}
