<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use App\Support\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FieldOfficeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    public function test_the_region_viii_offices_exist_after_migrating(): void
    {
        // The create-table migration seeds these, because the migration that
        // links profiles to them runs immediately after and would otherwise
        // match nothing.
        $this->assertSame(9, FieldOffice::count());

        foreach (['bfo', 'lfoi', 'lfoii', 'slfo', 'wlso', 'sfo', 'esfo', 'nsfo', 'hrd'] as $code) {
            $this->assertDatabaseHas('field_offices', ['code' => $code]);
        }

        $this->assertSame(
            ['Leyte'],
            FieldOffice::where('code', 'lfoi')->value('jurisdiction')
        );
    }

    public function test_only_active_offices_are_offered_as_options(): void
    {
        FieldOffice::where('code', 'nsfo')->update(['is_active' => false]);

        $labels = collect(FieldOffice::options())->pluck('label');

        $this->assertCount(8, $labels);
        $this->assertFalse($labels->contains('CSC Field Office - Northern Samar'));
    }

    public function test_profile_form_offers_offices_from_the_table(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile/complete')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('options.fieldOffices', 9)
                ->where('options.fieldOffices.0.label', 'CSC Field Office - Biliran')
            );
    }

    public function test_index_is_restricted_to_admins(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $this->actingAs($participant)->get('/admin/field-offices')->assertForbidden();
        $this->actingAs($this->staff(Role::FieldOffice))->get('/admin/field-offices')->assertForbidden();
        $this->actingAs($this->staff(Role::Management))->get('/admin/field-offices')->assertForbidden();
        $this->actingAs($this->staff())->get('/admin/field-offices')->assertOk();
        $this->actingAs($this->staff(Role::SuperAdmin))->get('/admin/field-offices')->assertOk();
    }

    public function test_show_is_restricted_to_admins(): void
    {
        $office = FieldOffice::where('code', 'nsfo')->first();

        $this->actingAs(User::factory()->create())->get("/admin/field-offices/{$office->id}")->assertForbidden();
        $this->actingAs($this->staff(Role::FieldOffice))->get("/admin/field-offices/{$office->id}")->assertForbidden();
        $this->actingAs($this->staff(Role::Management))->get("/admin/field-offices/{$office->id}")->assertForbidden();
        $this->actingAs($this->staff())->get("/admin/field-offices/{$office->id}")->assertOk();
        $this->actingAs($this->staff(Role::SuperAdmin))->get("/admin/field-offices/{$office->id}")->assertOk();
    }

    public function test_the_index_carries_both_counts_the_row_renders(): void
    {
        $office = FieldOffice::where('code', 'nsfo')->first();

        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create(['field_office_id' => $office->id]);

        User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($this->staff())
            ->get('/admin/field-offices')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($office) {
                $row = collect($page->toArray()['props']['offices'])->firstWhere('id', $office->id);

                $this->assertSame(1, $row['participants']);
                // Read from the payload but never loaded, so it arrived as
                // null and the Staff column rendered blank.
                $this->assertSame(1, $row['staff']);
            });
    }

    public function test_show_reports_office_stats(): void
    {
        $office = FieldOffice::where('code', 'nsfo')->first();
        $office->update(['email' => 'nsfo@csc.gov.ph', 'remarks' => 'Covers Northern Samar.']);

        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create(['field_office_id' => $office->id]);

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office->id,
            'profile_completed_at' => now(),
        ]);

        $training = Training::factory()->create(['payment_required' => false]);
        RegistrationService::register($participant, $training);

        $this->actingAs($this->staff())
            ->get("/admin/field-offices/{$office->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/FieldOffices/Show')
                ->where('stats.participants', 1)
                ->where('stats.staff', 1)
                ->where('stats.registrations', 1)
                ->where('stats.settled', 1)
                ->where('office.email', 'nsfo@csc.gov.ph')
                ->where('office.remarks', 'Covers Northern Samar.')
                ->has('recent', 1)
            );
    }

    public function test_admin_can_add_an_office(): void
    {
        $this->actingAs($this->staff())
            ->post('/admin/field-offices', [
                'code' => 'NFO',
                'name' => 'CSC Field Office - New',
                'type' => 'field_office',
                'province' => 'Leyte',
                'jurisdiction' => 'Leyte, Biliran',
                'email' => 'new@csc.gov.ph',
                'is_active' => true,
            ])
            ->assertRedirect('/admin/field-offices')
            ->assertSessionHas('success');

        $office = FieldOffice::where('name', 'CSC Field Office - New')->first();

        $this->assertNotNull($office);
        // Codes are normalised to lowercase, jurisdiction split into a list.
        $this->assertSame('nfo', $office->code);
        $this->assertSame(['Leyte', 'Biliran'], $office->jurisdiction);
    }

    public function test_office_codes_must_be_unique(): void
    {
        $this->actingAs($this->staff())
            ->from('/admin/field-offices/create')
            ->post('/admin/field-offices', [
                'code' => 'lfoi',
                'name' => 'Duplicate',
                'type' => 'field_office',
                'province' => 'Leyte',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_admin_can_deactivate_an_office_without_deleting_it(): void
    {
        $office = FieldOffice::where('code', 'nsfo')->first();

        $this->actingAs($this->staff())
            ->from('/admin/field-offices')
            ->post("/admin/field-offices/{$office->id}/toggle")
            ->assertRedirect('/admin/field-offices');

        $this->assertFalse($office->fresh()->is_active);
        $this->assertDatabaseHas('field_offices', ['id' => $office->id]);
    }

    public function test_an_inactive_office_is_refused_on_the_profile_form(): void
    {
        $office = FieldOffice::where('code', 'nsfo')->first();
        $office->update(['is_active' => false]);

        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)
            ->from('/profile/complete')
            ->post('/profile/complete', [
                'first_name' => 'Juan',
                'last_name' => 'dela Cruz',
                'date_of_birth' => '1990-05-04',
                'sex' => 'Male',
                'is_pwd' => 'No',
                'civil_status' => 'Single',
                'mobile_number' => '09171234567',
                'position_title' => 'Officer',
                'salary_grade' => 'SG 14',
                'organization_name' => 'DepEd',
                'sector' => 'National Government Agency',
                'region' => 'Region VIII',
                'province' => 'Leyte',
                'city_municipality' => 'Palo',
                'field_office_id' => $office->id,
                'position_level' => '1st Level',
                'employment_status' => 'Permanent',
                'organization_address' => 'Palo, Leyte',
                'consent' => true,
            ])
            ->assertSessionHasErrors('field_office_id');
    }

    public function test_participant_profile_links_to_its_office(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        $office = FieldOffice::where('code', 'esfo')->first();
        Profile::factory()->for($participant)->create(['field_office_id' => $office->id]);

        $this->actingAs($this->staff())
            ->get("/admin/participants/{$participant->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('participant.profile.csc_field_office', 'CSC Field Office - Eastern Samar')
            );
    }
}
