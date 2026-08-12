<?php

namespace Database\Factories;

use App\Enums\RequestStatus;
use App\Models\TrainingRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingRequest>
 */
class TrainingRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'justification' => fake()->paragraph(),
            'category' => 'Leadership',
            'expected_participants' => 25,
            'preferred_start' => now()->addMonths(2)->toDateString(),
            'preferred_end' => now()->addMonths(2)->addDays(2)->toDateString(),
            'status' => RequestStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }
}
