<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        $registration = Registration::factory()->completed();

        return [
            'registration_id' => $registration,
            'user_id' => fn (array $attributes) => Registration::find($attributes['registration_id'])->user_id,
            'training_id' => fn (array $attributes) => Registration::find($attributes['registration_id'])->training_id,
            'certificate_number' => 'CSC8-'.now()->format('Y').'-'.fake()->unique()->numberBetween(100000, 999999),
            'verification_code' => Str::random(32),
        ];
    }

    /** Already generated, with a file behind it. */
    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => "certificates/{$attributes['verification_code']}.pdf",
            'generated_at' => now(),
        ]);
    }
}
