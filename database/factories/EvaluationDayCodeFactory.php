<?php

namespace Database\Factories;

use App\Models\EvaluationDayCode;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EvaluationDayCode>
 */
class EvaluationDayCodeFactory extends Factory
{
    protected $model = EvaluationDayCode::class;

    public function definition(): array
    {
        return [
            'training_id' => Training::factory(),
            'day_number' => 1,
            'token' => Str::random(40),
            'issued_by' => User::factory(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => CarbonImmutable::now()->subHour()]);
    }

    public function forDay(int $day): static
    {
        return $this->state(fn () => ['day_number' => $day]);
    }
}
