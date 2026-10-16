<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Term>
 */
class TermFactory extends Factory
{
    /**
     * A non-current term in a school year no other term uses yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2040, 2099);

        return [
            'school_year_start' => $year,
            'semester' => fake()->randomElement(array_keys(Term::SEMESTERS)),
            'starts_on' => null,
            'ends_on' => null,
            'is_current' => false,
        ];
    }

    /**
     * Marks the term current (and unmarks the others).
     */
    public function current(): static
    {
        return $this->state(['is_current' => true])->afterMaking(function (Term $term) {
            Term::query()->where('is_current', true)->update(['is_current' => false]);
            Term::flushCurrent();
        });
    }
}
