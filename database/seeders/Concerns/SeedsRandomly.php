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

        // Seeding the generator rewinds the number stream but not Faker's
        // uniqueness tracker, which is a separate set of already-issued values
        // living on the shared generator. Any factory using unique() then draws
        // again on a collision, so a replay that started with a warm tracker
        // burns a different number of values and diverges from the run it was
        // meant to reproduce. Reset it too, or SAMPLE_*_SEED only half works.
        fake()->unique(true);

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
