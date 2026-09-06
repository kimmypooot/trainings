<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Support\AgencyReference;
use App\Support\FieldOfficeReference;
use App\Support\ProfileOptions;
use Database\Seeders\AgencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function validProfile(array $overrides = []): array
    {
        return [
            'first_name' => 'Juan',
            'middle_name' => 'D',
            'last_name' => 'dela Cruz',
            'suffix' => 'JR.',
            'date_of_birth' => '1990-05-04',
            'sex' => 'Male',
            'is_pwd' => 'No',
            'civil_status' => 'Single',
            'mobile_number' => '09171234567',

            'position_title' => 'Administrative Officer III',
            'salary_grade' => 'SG 14',
            'organization_name' => 'Department of Education',
            'sector' => 'National Government Agency (NGA)',
            'region' => 'Region VIII (Eastern Visayas)',
            'province' => 'Leyte',
            'city_municipality' => 'Palo',
            'field_office_id' => FieldOffice::where('code', 'lfoi')->value('id'),
            'position_level' => '2nd Level (Rank and File)',
            'employment_status' => 'Permanent',
            'organization_address' => 'Palo, Leyte',
            'food_restrictions_details' => 'no pork',

            'consent' => true,
            ...$overrides,
        ];
    }

    public function test_registration_sends_the_new_user_to_the_profile_form(): void
    {
        Notification::fake();

        $this->post('/register', [
            'email' => 'juan@example.com',
            'password' => 'sikretokong123',
            'password_confirmation' => 'sikretokong123',
            'consent' => true,
        ])->assertRedirect('/profile/complete');

        // The account starts unverified: the system stays locked until the
        // emailed link is clicked, and the link itself goes out when the
        // profile completes the registration — not at account creation.
        $user = User::where('email', 'juan@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_profile_form_renders_with_its_option_lists(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile/complete')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Complete')
                ->has('options.sectors')
                ->has('options.fieldOffices')
                ->has('geography')
                ->where('options.yesNo', ProfileOptions::yesNo())
            );
    }

    public function test_dashboard_is_gated_until_the_profile_is_complete(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/profile/complete');
    }

    public function test_profile_can_be_completed_and_opens_the_dashboard(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile())
            ->assertRedirect('/dashboard');

        $user->refresh();

        $this->assertTrue($user->hasCompletedProfile());
        $this->assertSame('JUAN D. DELA CRUZ JR.', $user->name);

        $profile = $user->profile;
        $this->assertNotNull($profile);
        $this->assertFalse($profile->is_pwd);
        $this->assertTrue($profile->hasFoodRestrictions());
        $this->assertNotNull($profile->consented_at);

        // Free-text fields are stored uppercase.
        $this->assertSame('JUAN', $profile->first_name);
        $this->assertSame('DELA CRUZ', $profile->last_name);
        $this->assertSame('DEPARTMENT OF EDUCATION', $profile->organization_name);
        $this->assertSame('ADMINISTRATIVE OFFICER III', $profile->position_title);
        $this->assertSame('PALO, LEYTE', $profile->organization_address);
        $this->assertSame('NO PORK', $profile->food_restrictions_details);

        // Place names keep the canonical proper-case PSGC spelling.
        $this->assertSame('Region VIII (Eastern Visayas)', $profile->region);
        $this->assertSame('Leyte', $profile->province);
        $this->assertSame('Palo', $profile->city_municipality);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_profile_requires_every_mandatory_field(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', [])
            ->assertRedirect('/profile/complete')
            ->assertSessionHasErrors([
                'first_name', 'last_name', 'date_of_birth', 'sex', 'is_pwd', 'civil_status',
                'mobile_number', 'position_title', 'salary_grade', 'organization_name', 'sector',
                'region', 'province', 'city_municipality', 'field_office_id', 'position_level',
                'employment_status', 'organization_address', 'consent',
            ]);

        $this->assertFalse($user->refresh()->hasCompletedProfile());
    }

    public function test_profile_rejects_values_outside_the_option_lists(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile([
                'sector' => 'Made Up Sector',
                'field_office_id' => 99999,
            ]))
            ->assertRedirect('/profile/complete')
            ->assertSessionHasErrors(['sector', 'field_office_id']);
    }

    public function test_geography_reflects_the_latest_psgc_with_the_negros_island_region(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile/complete')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('geography', 18)
                // Regions are sorted alphabetically in the committed dataset;
                // NIR is the newest PSGC region (RA 12000) and must be there.
                ->where('geography.4.name', 'Negros Island Region (NIR)')
                ->where('geography.4.provinces.0.name', 'City of Bacolod')
                ->where('geography.4.provinces.1.name', 'Negros Occidental')
                ->where('geography.4.provinces.2.name', 'Negros Oriental')
                ->where('geography.4.provinces.3.name', 'Siquijor')
            );
    }

    public function test_place_names_must_come_from_the_psgc_reference(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        // A province that does not belong to the chosen region, and a made-up
        // city, must both be rejected — the pickers and the validation share
        // the same PSGC reference.
        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile([
                'region' => 'Region VIII (Eastern Visayas)',
                'province' => 'Cebu',
                'city_municipality' => 'Not A Place',
            ]))
            ->assertRedirect('/profile/complete')
            ->assertSessionHasErrors(['province', 'city_municipality']);

        $this->assertFalse($user->refresh()->hasCompletedProfile());
    }

    public function test_food_restrictions_are_optional_free_text(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        // Blank is accepted — as in v2, no text means no restrictions.
        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile(['food_restrictions_details' => '']))
            ->assertRedirect('/dashboard');

        $profile = $user->refresh()->profile;

        $this->assertNull($profile->food_restrictions_details);
        $this->assertFalse($profile->hasFoodRestrictions());
    }

    public function test_full_middle_name_is_stored_but_rendered_as_an_initial(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile(['middle_name' => 'dizon']))
            ->assertRedirect('/dashboard');

        $user->refresh();

        // Stored in full, as v2 does — no data loss on migration from v2.
        $this->assertSame('DIZON', $user->profile->middle_name);
        $this->assertSame('D.', $user->profile->middleInitial());
        // Rendered as an initial for certificates and event lists.
        $this->assertSame('JUAN D. DELA CRUZ JR.', $user->name);
    }

    public function test_mobile_number_must_be_a_valid_ph_format(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile(['mobile_number' => '12345']))
            ->assertRedirect('/profile/complete')
            ->assertSessionHasErrors('mobile_number');

        $this->assertFalse($user->refresh()->hasCompletedProfile());
    }

    public function test_consent_is_mandatory(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', $this->validProfile(['consent' => false]))
            ->assertRedirect('/profile/complete')
            ->assertSessionHasErrors('consent');

        $this->assertFalse($user->refresh()->hasCompletedProfile());
    }

    public function test_completed_user_reaches_the_dashboard_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'done@example.com',
            'password' => 'sikretokong123',
            'profile_completed_at' => now(),
        ]);

        $this->post('/login', ['email' => 'done@example.com', 'password' => 'sikretokong123'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_incomplete_user_is_sent_back_to_the_form_on_login(): void
    {
        User::factory()->create([
            'email' => 'todo@example.com',
            'password' => 'sikretokong123',
            'profile_completed_at' => null,
        ]);

        $this->post('/login', ['email' => 'todo@example.com', 'password' => 'sikretokong123'])
            ->assertRedirect('/profile/complete');
    }

    /*
     * The agency reference.
     *
     * The employer field is a picker over `agencies`, with a typed fallback for
     * an employer that is not on the list. What makes it worth having is that a
     * pick carries the sector and the serving field office with it, so those
     * two stop being three independent guesses — which is why the tests below
     * are mostly about the server refusing to take the client's word for them.
     */

    public function test_a_picked_agency_supplies_the_name_sector_and_field_office(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);
        $office = FieldOffice::where('code', 'lfoii')->first();

        $agency = Agency::create([
            'name' => 'DEPARTMENT OF EDUCATION',
            'acronym' => 'DEPED',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $office->id,
        ]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile([
                'agency_id' => $agency->id,
                // Everything the pick decides, posted wrong on purpose: a
                // client that can restate the pairing is a client that can
                // contradict it, and this is the assertion that it cannot.
                'organization_name' => 'SOMETHING ELSE ENTIRELY',
                'sector' => 'Water District (WD)',
                'field_office_id' => FieldOffice::where('code', 'lfoi')->value('id'),
            ]))
            ->assertRedirect('/dashboard');

        $profile = $user->refresh()->profile;

        $this->assertSame($agency->id, $profile->agency_id);
        $this->assertSame('DEPARTMENT OF EDUCATION', $profile->organization_name);
        $this->assertSame('National Government Agency (NGA)', $profile->sector);
        $this->assertSame($office->id, $profile->field_office_id);
    }

    public function test_an_agency_that_names_no_field_office_leaves_the_posted_one_standing(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);
        $office = FieldOffice::where('code', 'lfoi')->first();

        $agency = Agency::create([
            'name' => 'A NEWLY KNOWN EMPLOYER',
            'sector' => 'Private Sector',
            'field_office_id' => null,
        ]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile([
                'agency_id' => $agency->id,
                'field_office_id' => $office->id,
            ]))
            ->assertRedirect('/dashboard');

        $profile = $user->refresh()->profile;

        // Half-known is allowed: the name and sector still come from the row,
        // and the office question stays open rather than being answered blank.
        $this->assertSame('A NEWLY KNOWN EMPLOYER', $profile->organization_name);
        $this->assertSame('Private Sector', $profile->sector);
        $this->assertSame($office->id, $profile->field_office_id);
    }

    public function test_a_retired_agency_cannot_be_newly_picked(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $agency = Agency::create([
            'name' => 'AN ABOLISHED BUREAU',
            'sector' => 'National Government Agency (NGA)',
            'is_active' => false,
        ]);

        // The picker never offers it; an id posted past the picker is the only
        // way it could arrive, and that is the case being closed.
        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile(['agency_id' => $agency->id]))
            ->assertSessionHasErrors('agency_id');

        $this->assertNull($user->refresh()->profile);
    }

    public function test_an_unlisted_employer_is_still_typed_in_full(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        // No agency_id at all: the form exactly as it was before the reference
        // list existed, which every deployment with an empty list still uses.
        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile())
            ->assertRedirect('/dashboard');

        $profile = $user->refresh()->profile;

        $this->assertNull($profile->agency_id);
        $this->assertSame('DEPARTMENT OF EDUCATION', $profile->organization_name);
        $this->assertSame('National Government Agency (NGA)', $profile->sector);
    }

    public function test_the_typed_path_still_requires_a_sector_and_a_field_office(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile([
                'sector' => '',
                'field_office_id' => '',
            ]))
            ->assertSessionHasErrors(['sector', 'field_office_id']);
    }

    public function test_moving_to_a_typed_employer_clears_the_agency_link(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $agency = Agency::create([
            'name' => 'DEPARTMENT OF EDUCATION',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => FieldOffice::where('code', 'lfoi')->value('id'),
        ]);

        $this->actingAs($user)->post('/profile/complete', $this->validProfile(['agency_id' => $agency->id]));
        $this->assertNotNull($user->refresh()->profile->agency_id);

        // The participant ticks "not on the list" and corrects it by hand. The
        // link has to go, or the profile keeps claiming an employer it no
        // longer names.
        $this->actingAs($user)->put('/profile', $this->validProfile([
            'organization_name' => 'A DIVISION OFFICE THE LIST DOES NOT HOLD',
        ]))->assertSessionHasNoErrors();

        $profile = $user->refresh()->profile;

        $this->assertNull($profile->agency_id);
        $this->assertSame('A DIVISION OFFICE THE LIST DOES NOT HOLD', $profile->organization_name);
    }

    public function test_the_form_is_offered_the_active_agencies_with_their_pairings(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);
        $office = FieldOffice::where('code', 'lfoi')->first();

        Agency::create([
            'name' => 'DEPARTMENT OF EDUCATION',
            'acronym' => 'DEPED',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $office->id,
        ]);
        Agency::create([
            'name' => 'AN ABOLISHED BUREAU',
            'sector' => 'National Government Agency (NGA)',
            'is_active' => false,
        ]);

        $this->actingAs($user)->get('/profile/complete')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('options.agencies', 1)
                ->where('options.agencies.0.name', 'DEPARTMENT OF EDUCATION')
                ->where('options.agencies.0.label', 'DEPARTMENT OF EDUCATION (DEPED)')
                ->where('options.agencies.0.sector', 'National Government Agency (NGA)')
                ->where('options.agencies.0.field_office_id', $office->id)
                // The acronym is in the haystack: most people know their
                // employer by it, and typing DEPED must find a row whose name
                // spells it out.
                ->where('options.agencies.0.search', 'department of education deped')
        );
    }

    /*
     * The shipped agency list, checked as data.
     *
     * Every failure this catches is a silent one. A sector misspelled by a
     * character passes the seeder, lands on a profile, and then bounces that
     * participant's *next* save on a field they never touched — because
     * ProfileService validates the sector against ProfileOptions and the
     * picker wrote something not on that list. An office code that matches no
     * office seeds a null instead, so the agency quietly stops answering the
     * one question the reference exists to answer. Neither shows up as an
     * error at the time.
     */

    public function test_the_shipped_agency_list_is_valid_reference_data(): void
    {
        $this->assertFileExists(AgencyReference::path());

        $agencies = AgencyReference::all();
        $codes = array_column(FieldOfficeReference::all(), 'code');

        $badSectors = [];
        $badOffices = [];
        $names = [];

        foreach ($agencies as $agency) {
            $this->assertArrayHasKey('name', $agency);
            $this->assertArrayHasKey('sector', $agency);

            if (! in_array($agency['sector'], ProfileOptions::sectors(), true)) {
                $badSectors[] = "{$agency['name']}: {$agency['sector']}";
            }

            $code = $agency['field_office_code'] ?? null;

            if ($code !== null && ! in_array($code, $codes, true)) {
                $badOffices[] = "{$agency['name']}: {$code}";
            }

            $names[] = mb_strtolower(trim($agency['name']));
        }

        $this->assertSame([], $badSectors, "Sectors not in ProfileOptions::sectors():\n".implode("\n", $badSectors));
        $this->assertSame([], $badOffices, "Field office codes not in field-offices.json:\n".implode("\n", $badOffices));

        // `agencies.name` is unique in the database, so a duplicate is a failed
        // seed rather than a duplicated row — and the seeder matches on name,
        // so the second spelling would silently overwrite the first.
        $duplicates = array_keys(array_filter(array_count_values($names), fn (int $count) => $count > 1));
        $this->assertSame([], $duplicates, 'Duplicate agency names: '.implode(', ', $duplicates));
    }

    public function test_the_agency_seeder_is_additive_and_re_runnable(): void
    {
        // The file this deployment ships. An office that has not built its list
        // yet has none, and that is a legitimate state — the profile form falls
        // back to the typed path — so the seeder must cope either way.
        (new AgencySeeder)->run();
        $seeded = Agency::count();

        $this->assertSame(count(AgencyReference::all()), $seeded);

        // A row that has left the file is left alone rather than deleted:
        // profiles point at these, and a name dropped by an editing slip must
        // not take somebody's employer with it.
        $local = Agency::create([
            'name' => 'AN AGENCY ADDED BY HAND',
            'sector' => 'Other',
        ]);

        (new AgencySeeder)->run();

        $this->assertSame($seeded + 1, Agency::count());
        $this->assertModelExists($local);
    }

    /*
     * The denormalized copy, and the two ways it went wrong.
     *
     * `profiles.organization_name`, `.sector` and `.field_office_id` hold what
     * the agency said at the moment it was picked. That duplication is
     * deliberate — those columns are searched with LIKE, grouped, sorted,
     * scoped on and written into ten exports, and the typed-employer path has
     * no agency row to join to at all — but a copy is a promise to keep it
     * current, and the first cut of this made no such promise.
     */

    public function test_correcting_an_agency_carries_out_to_the_profiles_already_linked(): void
    {
        $office = FieldOffice::where('code', 'lfoi')->first();
        $moved = FieldOffice::where('code', 'sfo')->first();

        $agency = Agency::create([
            'code' => 'x-1',
            'name' => 'Bureau of Fire Protecton',
            'sector' => 'Other',
            'field_office_id' => $office->id,
        ]);

        $user = User::factory()->create(['profile_completed_at' => null]);
        $this->actingAs($user)->post('/profile/complete', $this->validProfile(['agency_id' => $agency->id]));

        $this->assertSame('Bureau of Fire Protecton', $user->refresh()->profile->organization_name);

        // The office spots the typo, fixes the sector it had guessed, and moves
        // the agency to the field office that actually serves it.
        $agency->update([
            'name' => 'Bureau of Fire Protection',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $moved->id,
        ]);

        $profile = $user->refresh()->profile;

        // Without this the reference would be right and the record still wrong
        // — one employer under two spellings, which is the whole thing the
        // table exists to prevent.
        $this->assertSame('Bureau of Fire Protection', $profile->organization_name);
        $this->assertSame('National Government Agency (NGA)', $profile->sector);
        $this->assertSame($moved->id, $profile->field_office_id);
    }

    public function test_an_agency_that_names_no_office_does_not_blank_the_profiles_own(): void
    {
        $office = FieldOffice::where('code', 'lfoi')->first();

        $agency = Agency::create(['code' => 'x-2', 'name' => 'A Half-Known Employer', 'sector' => 'Other']);

        $user = User::factory()->create(['profile_completed_at' => null]);
        $this->actingAs($user)->post('/profile/complete', $this->validProfile([
            'agency_id' => $agency->id,
            'field_office_id' => $office->id,
        ]));

        $agency->update(['name' => 'A Half-Known Employer, Renamed']);

        // Propagation must not drag a null office across: that would drop the
        // participant out of their field office's sight entirely.
        $this->assertSame($office->id, $user->refresh()->profile->field_office_id);
    }

    /**
     * A picked employer keeps the reference's spelling; a typed one is shouted.
     *
     * The asymmetry is the whole rule, so both halves are asserted together —
     * either one alone passes with the other broken. Picking is no longer
     * typing: the name belongs to the `agencies` row, exactly as a province
     * belongs to the PSGC reference, and upper-casing it made a participant's
     * own record disagree with the picker they chose it in.
     *
     * Free text still shouts, because it has no owner to be canonical for it
     * and the convention matches the name and position title beside it.
     */
    public function test_a_picked_employer_keeps_its_casing_and_a_typed_one_does_not(): void
    {
        $agency = Agency::factory()->create(['name' => 'Department of Education']);

        $picked = User::factory()->create(['profile_completed_at' => null]);
        $this->actingAs($picked)->post('/profile/complete', $this->validProfile([
            'agency_id' => $agency->id,
        ]));

        $this->assertSame(
            'Department of Education',
            $picked->refresh()->profile->organization_name,
            'A picked agency keeps the spelling the reference maintains.'
        );

        $this->flushSession();

        $typed = User::factory()->create(['profile_completed_at' => null]);
        $this->actingAs($typed)->post('/profile/complete', $this->validProfile([
            'agency_id' => null,
            'organization_name' => 'Palo Water District',
        ]));

        $this->assertSame(
            'PALO WATER DISTRICT',
            $typed->refresh()->profile->organization_name,
            'A typed employer is still stored upper-cased.'
        );
    }

    /**
     * The rest of the record still shouts. Only the reference-owned fields are
     * exempt, and this is the guard on the exemption staying narrow — a change
     * that dropped the upper-casing wholesale would pass every other case here.
     */
    public function test_picking_an_agency_does_not_stop_the_rest_of_the_record_being_upper_cased(): void
    {
        $agency = Agency::factory()->create(['name' => 'Department of Education']);
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->post('/profile/complete', $this->validProfile([
            'agency_id' => $agency->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'position_title' => 'Administrative Officer',
        ]));

        $profile = $user->refresh()->profile;

        $this->assertSame('JUAN', $profile->first_name);
        $this->assertSame('DELA CRUZ', $profile->last_name);
        $this->assertSame('ADMINISTRATIVE OFFICER', $profile->position_title);
    }

    public function test_renaming_an_agency_in_the_data_file_does_not_fork_it(): void
    {
        $reference = [[
            'code' => 'x-3',
            'name' => 'Municipal Government of Abuyog',
            'sector' => 'Local Government Unit (LGU)',
            'field_office_code' => 'lfoi',
        ]];

        $this->withAgencyFile($reference, fn () => (new AgencySeeder)->run());

        $user = User::factory()->create(['profile_completed_at' => null]);
        $this->actingAs($user)->post('/profile/complete', $this->validProfile([
            'agency_id' => Agency::where('code', 'x-3')->value('id'),
        ]));

        // The same agency, spelled properly, re-seeded. Matched on `name` this
        // creates a *second* row: the participant stays on the old spelling,
        // everyone after gets the new one, and the list now offers both.
        $reference[0]['name'] = 'Municipal Government of Abuyog, Leyte';

        $this->withAgencyFile($reference, fn () => (new AgencySeeder)->run());

        $this->assertSame(1, Agency::count());
        $this->assertSame('Municipal Government of Abuyog, Leyte', Agency::first()->name);
        $this->assertSame('Municipal Government of Abuyog, Leyte', $user->refresh()->profile->organization_name);
    }

    /**
     * Run something with the agency data file swapped for a fixture.
     *
     * AgencyReference caches the decoded file for the life of the process, so
     * the flush on both sides is what stops one test's fixture leaking into the
     * next — and stops the committed 285 loading into a test that wanted three.
     */
    private function withAgencyFile(array $agencies, callable $callback): void
    {
        $path = AgencyReference::path();
        $original = file_get_contents($path);

        try {
            file_put_contents($path, json_encode($agencies));
            AgencyReference::flush();
            $callback();
        } finally {
            file_put_contents($path, $original);
            AgencyReference::flush();
        }
    }
}
