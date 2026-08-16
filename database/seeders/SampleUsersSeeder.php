<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\Concerns\SeedsRandomly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A randomised population of accounts covering every role.
 *
 * Distinct from DemoSeeder, which creates four fixed accounts at known
 * addresses for logging in. This one fills the system out — enough people,
 * spread across enough offices, that listings paginate, office scoping has
 * something to actually scope, and the role guards can be exercised against
 * more than one account each.
 *
 * Additive by design: every run mints new accounts rather than replacing what
 * is there, so it can be run repeatedly to grow a test dataset.
 */
class SampleUsersSeeder extends Seeder
{
    use SeedsRandomly;

    /** Shared across every seeded account, since these are all throwaway logins. */
    public const PASSWORD = 'Password123';

    /** Set this to replay a previous run's dataset exactly. */
    public const SEED_ENV = 'SAMPLE_USERS_SEED';

    /**
     * How many of each role to create, as [min, max].
     *
     * Participants dominate deliberately: that is the shape of the real system,
     * and a directory with three people in it does not surface pagination or
     * scoping bugs.
     */
    private const COUNTS = [
        'participant' => [18, 30],
        'field-office' => [3, 6],
        'admin' => [1, 3],
        'management' => [1, 2],
        'superadmin' => [1, 1],
    ];

    /**
     * Roughly how many field-office accounts also collect money.
     *
     * Collecting officer is a designation, not a role — so seeded data has to
     * express it the way the office does: some of the field-office staff are
     * designated, and they keep their own office's scoping while taking
     * payments. A separate "collecting officer" account would not exercise
     * that combination at all, which is the one worth testing against.
     */
    private const DESIGNATED_COLLECTORS = [1, 3];

    public function run(): void
    {
        if ($this->blockedInProduction('SampleUsersSeeder')) {
            return;
        }

        $seed = $this->applySeed(self::SEED_ENV);

        $offices = FieldOffice::active()->pluck('id');

        if ($offices->isEmpty()) {
            $this->command->warn('No active field offices found — seeding them first.');
            $this->call(FieldOfficeSeeder::class);
            $offices = FieldOffice::active()->pluck('id');
        }

        $created = [];

        foreach (self::COUNTS as $roleValue => [$min, $max]) {
            $role = Role::from($roleValue);
            $count = fake()->numberBetween($min, $max);

            for ($i = 0; $i < $count; $i++) {
                $role === Role::Participant
                    ? $this->participant($offices)
                    : $this->staff($role, $offices);
            }

            $created[$role->label()] = $count;
        }

        $created['Collecting Officer (designated)'] = $this->designateCollectors();

        $this->report($created, $seed);
    }

    /**
     * Designate some of the seeded field-office staff as collecting officers.
     *
     * Deliberately drawn from field-office accounts rather than admins: the
     * combination the app has to get right is "scoped to one office *and* able
     * to take money", and only these exercise it.
     */
    private function designateCollectors(): int
    {
        $collectors = User::where('role', Role::FieldOffice)
            ->inRandomOrder()
            ->limit(fake()->numberBetween(...self::DESIGNATED_COLLECTORS))
            ->get();

        $collectors->each(fn (User $user) => $user->forceFill(['is_collecting_officer' => true])->save());

        return $collectors->count();
    }

    /**
     * A participant, with the completed profile the gate requires.
     */
    private function participant($offices): void
    {
        $user = $this->account(Role::Participant, null);

        $profile = Profile::factory()->for($user)->create([
            'field_office_id' => $offices->isNotEmpty() ? fake()->randomElement($offices->all()) : null,
            // Roughly a fifth of any real cohort has something catering needs
            // to know about, and the roster summary counts them.
            'food_restrictions_details' => fake()->boolean(20)
                ? mb_strtoupper(fake()->randomElement([
                    'no pork', 'vegetarian', 'no seafood', 'lactose intolerant', 'halal',
                ]))
                : null,
            'is_pwd' => fake()->boolean(8),
        ]);

        // users.name is composed from the profile, exactly as ProfileController does.
        $user->forceFill(['name' => $profile->fullName()])->save();
    }

    /**
     * A staff account. Only field-office staff carry an office — that
     * assignment is what scopes everything they can see.
     */
    private function staff(Role $role, $offices): void
    {
        $officeId = $role === Role::FieldOffice && $offices->isNotEmpty()
            ? fake()->randomElement($offices->all())
            : null;

        $this->account($role, $officeId);
    }

    /**
     * `role`, `field_office_id` and `is_active` decide access and are not
     * mass-assignable, so they are forced rather than filled — passing them to
     * fill() drops them silently and every account lands as a participant.
     */
    private function account(Role $role, ?int $officeId): User
    {
        $name = mb_strtoupper(fake()->name());

        $user = new User([
            'name' => $name,
            'email' => $this->uniqueEmail($role, $name),
            'password' => self::PASSWORD,
        ]);

        $user->forceFill([
            'role' => $role,
            'field_office_id' => $officeId,
            // A few deactivated accounts, so the login guard and the user
            // listing have something real to show.
            'is_active' => fake()->boolean(92),
            'email_verified_at' => now(),
            // Staff never fill in a participant profile; participants get one
            // written immediately after this.
            'profile_completed_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * Readable addresses that say what the account is for, kept unique with a
     * short suffix rather than a bare faker address.
     */
    private function uniqueEmail(Role $role, string $name): string
    {
        $slug = Str::slug(Str::of($name)->explode(' ')->first() ?: 'user');
        $domain = $role === Role::Participant ? 'example.com' : 'csc.gov.ph';

        // The suffix comes from Faker rather than Str::random(), which draws on
        // random_bytes and would ignore the seed — leaving a "reproducible" run
        // that quietly produced different addresses every time.
        do {
            $email = sprintf('%s.%s@%s', $slug, Str::lower(fake()->bothify('??##')), $domain);
        } while (User::where('email', $email)->exists());

        return $email;
    }

    /**
     * @param  array<string, int>  $created
     */
    private function report(array $created, int $seed): void
    {
        foreach ($created as $label => $count) {
            $this->command->line("  {$label}: {$count}");
        }

        $this->command->info('Sample users seeded. Password for every account: '.self::PASSWORD);
        $this->reportSeed($seed, self::SEED_ENV);
    }
}
