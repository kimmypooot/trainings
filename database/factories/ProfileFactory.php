<?php

namespace Database\Factories;

use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\User;
use App\Support\ProfileOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => mb_strtoupper(fake()->firstName()),
            'middle_name' => mb_strtoupper(fake()->lastName()),
            'last_name' => mb_strtoupper(fake()->lastName()),
            'suffix' => null,
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-22 years'),
            'sex' => fake()->randomElement(ProfileOptions::sexes()),
            'is_pwd' => false,
            'civil_status' => fake()->randomElement(ProfileOptions::civilStatuses()),
            'mobile_number' => '09171234567',
            'position_title' => mb_strtoupper(fake()->jobTitle()),
            'salary_grade' => fake()->randomElement(ProfileOptions::salaryGrades()),
            'organization_name' => mb_strtoupper(fake()->company()),
            'sector' => fake()->randomElement(ProfileOptions::sectors()),
            'field_office_id' => FieldOffice::query()->inRandomOrder()->value('id') ?? FieldOffice::factory(),
            'position_level' => fake()->randomElement(ProfileOptions::positionLevels()),
            'employment_status' => fake()->randomElement(ProfileOptions::employmentStatuses()),
            'agency_unit' => null,
            'region' => 'REGION VIII',
            'province' => mb_strtoupper(fake()->word()),
            'city_municipality' => mb_strtoupper(fake()->city()),
            'organization_address' => mb_strtoupper(fake()->city()),
            'home_address' => null,
            'food_restrictions_details' => null,
            'consented_at' => now(),
        ];
    }
}
