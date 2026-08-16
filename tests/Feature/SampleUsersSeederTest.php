<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\SampleUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SampleUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_at_least_one_account_for_every_role(): void
    {
        // The same trap DemoSeeder guards: role, field_office_id and is_active
        // are not mass-assignable, so a seeder using fill() would silently
        // produce a system full of participants.
        $this->seed(SampleUsersSeeder::class);

        // Collecting officer is excluded: it is retired as a role and seeded as
        // a designation on field-office staff instead — asserted below.
        $seeded = array_filter(
            Role::cases(),
            fn (Role $role) => $role !== Role::CollectingOfficer
        );

        foreach ($seeded as $role) {
            $this->assertGreaterThan(
                0,
                User::where('role', $role)->count(),
                "No {$role->label()} account was seeded."
            );
        }
    }

    public function test_it_designates_field_office_staff_as_collecting_officers(): void
    {
        $this->seed(SampleUsersSeeder::class);

        $collectors = User::where('is_collecting_officer', true)->get();

        $this->assertGreaterThan(0, $collectors->count(), 'No collecting officer was designated.');

        // Drawn from field-office staff on purpose: "scoped to one office and
        // able to take money" is the combination the app has to get right, and
        // a standalone cashier account would not exercise it.
        foreach ($collectors as $collector) {
            $this->assertSame(Role::FieldOffice, $collector->role);
            $this->assertNotNull($collector->field_office_id);
        }
    }

    public function test_every_participant_has_a_completed_profile(): void
    {
        $this->seed(SampleUsersSeeder::class);

        $participants = User::where('role', Role::Participant)->get();

        foreach ($participants as $participant) {
            $profile = Profile::where('user_id', $participant->getKey())->first();

            $this->assertNotNull($profile, "{$participant->email} has no profile.");
            $this->assertNotNull($participant->profile_completed_at);
            // users.name is composed from the profile, as ProfileController does.
            $this->assertSame($profile->fullName(), $participant->name);
        }
    }

    public function test_only_field_office_staff_are_scoped_to_an_office(): void
    {
        $this->seed(SampleUsersSeeder::class);

        $this->assertSame(
            0,
            User::where('role', Role::FieldOffice)->whereNull('field_office_id')->count(),
            'A field office account without an office would see nothing.'
        );

        foreach ([Role::Admin, Role::Management, Role::SuperAdmin] as $role) {
            $this->assertSame(
                0,
                User::where('role', $role)->whereNotNull('field_office_id')->count(),
                "{$role->label()} accounts must not be office-scoped."
            );
        }
    }

    public function test_seeded_accounts_can_actually_sign_in(): void
    {
        $this->seed(SampleUsersSeeder::class);

        $user = User::where('role', Role::Participant)->where('is_active', true)->firstOrFail();

        $this->assertTrue(Hash::check(SampleUsersSeeder::PASSWORD, $user->password));

        $this->post('/login', [
            'email' => $user->email,
            'password' => SampleUsersSeeder::PASSWORD,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_faker_seed_makes_a_run_reproducible(): void
    {
        putenv('SAMPLE_USERS_SEED=4242');

        try {
            $this->seed(SampleUsersSeeder::class);
            $first = User::orderBy('id')->pluck('email')->all();

            // Clear the accounts and replay with the same seed. Profiles go
            // with them via the cascade on profiles.user_id.
            User::query()->delete();

            $this->seed(SampleUsersSeeder::class);
            $second = User::orderBy('id')->pluck('email')->all();

            $this->assertNotEmpty($first);
            $this->assertSame($first, $second, 'The same seed must reproduce the same dataset.');
        } finally {
            putenv('SAMPLE_USERS_SEED');
        }
    }

    public function test_running_twice_adds_more_accounts_rather_than_failing(): void
    {
        $this->seed(SampleUsersSeeder::class);
        $first = User::count();

        $this->seed(SampleUsersSeeder::class);

        $this->assertGreaterThan($first, User::count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // --force is what gets us past Laravel's own production confirmation
        // prompt, so the seeder's internal guard is the thing under test here
        // rather than the framework's.
        $this->artisan('db:seed', [
            '--class' => SampleUsersSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(0, User::count(), 'Known credentials must never be seeded in production.');
    }
}
