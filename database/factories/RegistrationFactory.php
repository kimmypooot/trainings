<?php

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'training_id' => Training::factory(),
            'status' => RegistrationStatus::Pending,
            'registered_at' => now(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RegistrationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => RegistrationStatus::Approved]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => RegistrationStatus::Completed,
            'attended_at' => now(),
        ]);
    }
}
