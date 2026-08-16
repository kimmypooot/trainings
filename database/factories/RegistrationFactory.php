<?php

namespace Database\Factories;

use App\Enums\ChargeTo;
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
            // The registration form requires this of every participant, so a
            // factory registration without one is a shape the app never
            // actually produces — and anything reporting on who is billed
            // reads empty against it.
            'charge_to' => self::chargeTo(),
        ];
    }

    /**
     * Who the fee is billed to, weighted the way a real cohort falls.
     *
     * Most participants are government staff whose agency settles the fee
     * through a disbursement voucher; paying personally is the minority case.
     * An even split would make the analytics cut look manufactured.
     */
    public static function chargeTo(): ChargeTo
    {
        return fake()->boolean(70) ? ChargeTo::Agency : ChargeTo::Personal;
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
