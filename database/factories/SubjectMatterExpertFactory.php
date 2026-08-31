<?php

namespace Database\Factories;

use App\Models\SubjectMatterExpert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubjectMatterExpert>
 */
class SubjectMatterExpertFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Unique because the column is: two experts drawn with the same
            // faker name would fail the save rather than the assertion, which
            // is a confusing way for an unrelated test to go red.
            'name' => mb_strtoupper(fake()->unique()->name()),
            'position' => fake()->randomElement([
                'Chief HR Specialist',
                'Supervising HR Specialist',
                'HR Specialist II',
                'Director II',
            ]),
            'organization' => 'Civil Service Commission RO VIII',
            'email' => fake()->unique()->safeEmail(),
            'contact_number' => '09'.fake()->numerify('#########'),
            'expertise' => fake()->sentence(8),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
