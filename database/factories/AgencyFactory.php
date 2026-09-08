<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\FieldOffice;
use App\Support\ProfileOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    public function definition(): array
    {
        $name = mb_strtoupper(fake()->unique()->company());

        return [
            'name' => $name,
            'acronym' => mb_strtoupper(fake()->unique()->lexify('???')),
            'sector' => fake()->randomElement(ProfileOptions::sectors()),
            'field_office_id' => FieldOffice::query()->inRandomOrder()->value('id')
                ?? FieldOffice::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** An agency whose serving office has not been decided. */
    public function withoutFieldOffice(): static
    {
        return $this->state(fn () => ['field_office_id' => null]);
    }
}
