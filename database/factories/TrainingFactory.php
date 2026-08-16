<?php

namespace Database\Factories;

use App\Enums\Curriculum;
use App\Enums\TrainingMode;
use App\Enums\TrainingStatus;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Training>
 */
class TrainingFactory extends Factory
{
    public function definition(): array
    {
        $starts = fake()->dateTimeBetween('+1 week', '+3 months');
        $title = fake()->unique()->sentence(4);

        return [
            'title' => rtrim($title, '.'),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'training_code' => 'TRN-'.fake()->unique()->numberBetween(100000, 999999),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(Curriculum::cases())->value,
            'venue' => fake()->city().' Convention Center',
            'mode' => TrainingMode::FaceToFace,
            'starts_at' => $starts,
            /*
             * Derived lazily from whatever starts_at ends up being, not from
             * the $starts drawn above. A test that overrides only starts_at
             * used to keep a random ends_at from a different date entirely —
             * harmless while nothing read ends_at, but wrong the moment a query
             * filters on it (Training::notEnded()), and wrong in a way that
             * made the test flaky rather than red.
             */
            'ends_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->addHours(6),
            'duration_days' => 1,
            'registration_closes_at' => fn (array $attributes) => Carbon::parse($attributes['starts_at'])->subDays(2),
            'capacity' => 30,
            'payment_required' => false,
            'status' => TrainingStatus::Published,
        ];
    }

    /** A multi-day run, for exercising per-day attendance. */
    public function runningFor(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_days' => $days,
            'ends_at' => (clone $attributes['starts_at'])->modify('+'.($days - 1).' days +6 hours'),
        ]);
    }

    /**
     * Starting today, so a scan can check someone in right now.
     *
     * Registration is deliberately left open — tests that check someone in
     * usually have to register them first, and the `closed()` state is there
     * for the cases that genuinely need a shut door.
     */
    public function startingToday(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->startOfDay()->addHours(8),
            'ends_at' => now()->startOfDay()->addHours(17),
            /*
             * Stated outright rather than left to the default. The default
             * deadline is two days before the run, which for a run starting
             * today is two days ago — a shut door, and the exact opposite of
             * what this state promises above.
             */
            'registration_closes_at' => now()->endOfDay(),
        ]);
    }

    public function full(): static
    {
        return $this->state(fn () => ['capacity' => 0]);
    }

    /**
     * A run that charges a fee.
     *
     * Worth naming because it decides where a registration lands: a free
     * training is confirmed on registration — first come, first served — while
     * a charged one waits at pending until the fee is settled. Anything
     * exercising the review queue needs this state, or there is nothing to
     * review.
     */
    public function paid(float $amount = 1500): static
    {
        return $this->state(fn () => [
            'payment_required' => true,
            'payment_amount' => $amount,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TrainingStatus::Draft]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['capacity' => null]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['registration_closes_at' => now()->subDay()]);
    }
}
