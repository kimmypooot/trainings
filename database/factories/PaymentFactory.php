<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory()->approved(),
            'user_id' => fn (array $attributes) => Registration::find($attributes['registration_id'])->user_id,
            'training_id' => fn (array $attributes) => Registration::find($attributes['registration_id'])->training_id,
            'amount' => 1500.00,
            'payment_method' => PaymentMethod::Online,
            'reference_number' => fake()->unique()->numerify('REF#########'),
            'payment_date' => now()->subDay()->toDateString(),
            'status' => PaymentStatus::Pending,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Rejected,
            'verified_at' => now(),
            'rejection_reason' => 'Reference number does not match our records.',
        ]);
    }
}
