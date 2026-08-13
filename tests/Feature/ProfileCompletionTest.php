<?php

namespace Tests\Feature;

use App\Models\FieldOffice;
use App\Models\User;
use App\Support\ProfileOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'sector' => 'National Government Agency',
            'region' => 'Region VIII',
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
        $this->post('/register', [
            'email' => 'juan@example.com',
            'password' => 'sikreto123',
            'password_confirmation' => 'sikreto123',
            'consent' => true,
        ])->assertRedirect('/profile/complete');

        // Registration counts as verification — there is no separate email
        // verification step in this system, and the badge depends on it.
        $this->assertNotNull(DB::table('users')->where('email', 'juan@example.com')->value('email_verified_at'));
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

    public function test_optional_v2_fields_are_stored(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->post('/profile/complete', $this->validProfile([
                'agency_unit' => 'human resource division',
                'home_address' => 'tacloban city',
            ]))
            ->assertRedirect('/dashboard');

        $profile = $user->refresh()->profile;

        $this->assertSame('HUMAN RESOURCE DIVISION', $profile->agency_unit);
        $this->assertSame('TACLOBAN CITY', $profile->home_address);
        $this->assertSame('REGION VIII', $profile->region);
        $this->assertSame('LEYTE', $profile->province);
        $this->assertSame('PALO', $profile->city_municipality);
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
            'password' => 'sikreto123',
            'profile_completed_at' => now(),
        ]);

        $this->post('/login', ['email' => 'done@example.com', 'password' => 'sikreto123'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_incomplete_user_is_sent_back_to_the_form_on_login(): void
    {
        User::factory()->create([
            'email' => 'todo@example.com',
            'password' => 'sikreto123',
            'profile_completed_at' => null,
        ]);

        $this->post('/login', ['email' => 'todo@example.com', 'password' => 'sikreto123'])
            ->assertRedirect('/profile/complete');
    }
}
