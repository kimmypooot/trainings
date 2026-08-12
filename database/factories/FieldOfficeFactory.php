<?php

namespace Database\Factories;

use App\Models\FieldOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldOffice>
 */
class FieldOfficeFactory extends Factory
{
    public function definition(): array
    {
        $province = fake()->unique()->city();

        return [
            'code' => fake()->unique()->lexify('???'),
            'name' => "CSC Field Office - {$province}",
            'type' => 'field_office',
            'province' => $province,
            'jurisdiction' => [$province],
            'email' => fake()->safeEmail(),
            'head_name' => fake()->name(),
            'head_position' => 'Director II',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
