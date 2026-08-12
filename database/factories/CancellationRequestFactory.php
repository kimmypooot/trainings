<?php

namespace Database\Factories;

use App\Enums\RequestStatus;
use App\Models\CancellationRequest;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationRequest>
 */
class CancellationRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory()->approved(),
            'reason' => 'Conflicting field assignment for that week.',
            'status' => RequestStatus::Pending,
        ];
    }
}
