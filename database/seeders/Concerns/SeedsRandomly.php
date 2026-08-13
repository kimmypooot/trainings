<?php

namespace Database\Seeders\Concerns;

/**
 * Randomised seed data that can be replayed.
 *
 * Random data is only useful if you can get back to the dataset that surfaced
 * a bug, so the seed is chosen at random but always reported.
 */
trait SeedsRandomly
{
    /**
     * Seed Faker and hand back the value used.
     *
     * @param  string  $env  Environment variable that pins the seed for a replay.
     */
    protected function applySeed(string $env): int
    {
        $seed = (int) (env($env) ?: random_int(1, 999999));

        fake()->seed($seed);

        return $seed;
    }

    protected function reportSeed(int $seed, string $env): void
    {
        $this->command->info("Faker seed: {$seed} — replay with {$env}={$seed}");
    }

    /**
     * Guard seeders that create known credentials or fabricated records.
     */
    protected function blockedInProduction(string $seeder): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        $this->command->error("{$seeder} is blocked in production.");

        return true;
    }
}
