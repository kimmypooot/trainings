<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\OfficeSetting;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Providers\OfficeSettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office's identity, edited in the app rather than in .env.
 *
 * These were environment settings, which put a clerical fact — the office
 * telephone number — behind server access and a `config:cache` clear. The row
 * overlays config/office.php, which stays as the fallback so an install that
 * has never opened the screen behaves exactly as it did before.
 */
class OfficeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        return User::factory()->create([
            'role' => Role::SuperAdmin,
            'profile_completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Civil Service Commission Regional Office V',
            'short_name' => 'CSC RO V',
            'region' => 'Bicol',
            'psgc_region' => 'Region V (Bicol Region)',
            'address' => 'Rawis, Legazpi City',
            'phone' => '(052) 000-0000',
            'email' => 'ro05.hrd@csc.gov.ph',
            'certificate_prefix' => 'CSC5',
            ...$overrides,
        ];
    }

    // --- The screen -------------------------------------------------------

    public function test_a_superadmin_can_change_the_office_identity(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $setting = OfficeSetting::current();

        $this->assertNotNull($setting);
        $this->assertSame('Civil Service Commission Regional Office V', $setting->name);
        $this->assertSame('Region V (Bicol Region)', $setting->psgc_region);
    }

    /**
     * The screen shows what the site is actually using, not the empty row.
     *
     * Open it on a fresh install and the boxes hold the configured defaults —
     * otherwise a superadmin sees blank fields beside a footer that plainly has
     * an address in it, and cannot tell whether saving will change anything.
     */
    public function test_the_form_is_prefilled_from_the_effective_configuration(): void
    {
        config(['office.name' => 'Configured Office', 'office.phone' => '(053) 111-1111']);

        $this->actingAs($this->superadmin())
            ->get('/admin/office')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/OfficeSettings')
                ->where('office.name', 'Configured Office')
                ->where('office.phone', '(053) 111-1111')
                ->where('updated', null));
    }

    // --- The overlay ------------------------------------------------------

    /** With no row saved, the app is exactly as configured. */
    public function test_configuration_stands_until_something_is_saved(): void
    {
        config(['office.name' => 'Configured Office']);

        OfficeSettingsProvider::apply();

        $this->assertSame('Configured Office', config('office.name'));
    }

    public function test_a_saved_row_overrides_the_configuration(): void
    {
        config(['office.name' => 'Configured Office']);

        OfficeSetting::create(['name' => 'Saved Office']);
        OfficeSettingsProvider::apply();

        $this->assertSame('Saved Office', config('office.name'));
    }

    /**
     * An optional field the office emptied stays empty.
     *
     * This is the case that decides the fallback rule. Laravel converts an
     * empty form field to null before it reaches the controller, so "cleared"
     * and "never set" arrive identically — and a naive `filled()` fallback
     * would spring the old telephone number back every single time, leaving no
     * way to remove a number that is no longer in service.
     */
    public function test_an_emptied_optional_field_can_actually_be_cleared(): void
    {
        config(['office.phone' => '(053) 111-1111']);

        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload(['phone' => null]))
            ->assertSessionHasNoErrors();

        OfficeSettingsProvider::apply();

        $this->assertNull(config('office.phone'));
    }

    /**
     * A required field that is null has never been set here, so configuration
     * still answers for it — which is what keeps a locked-prefix first save
     * from blanking the prefix.
     */
    public function test_a_required_field_falls_back_when_the_column_is_null(): void
    {
        config(['office.certificate_prefix' => 'CSC8']);

        OfficeSetting::create(['name' => 'Saved Office', 'certificate_prefix' => null]);
        OfficeSettingsProvider::apply();

        $this->assertSame('CSC8', config('office.certificate_prefix'));
    }

    /** The site must boot before its own settings table exists. */
    public function test_the_overlay_survives_a_missing_table(): void
    {
        config(['office.name' => 'Configured Office']);

        $this->app['db']->getSchemaBuilder()->drop('office_settings');

        OfficeSettingsProvider::apply();

        $this->assertSame('Configured Office', config('office.name'));
    }

    // --- The region, which changes behaviour ------------------------------

    /** Chosen from the PSA's list, so the misspelling doctor checks for cannot happen. */
    public function test_the_region_must_be_a_real_psgc_region(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload(['psgc_region' => 'Region 5']))
            ->assertSessionHasErrors('psgc_region');

        $this->assertNull(OfficeSetting::current());
    }

    // --- The prefix, which outlives the edit ------------------------------

    public function test_the_certificate_prefix_is_free_until_one_is_issued(): void
    {
        $this->actingAs($this->superadmin())
            ->get('/admin/office')
            ->assertInertia(fn ($page) => $page->where('certificatePrefixLocked', false));

        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload(['certificate_prefix' => 'CSC5']))
            ->assertSessionHasNoErrors();

        $this->assertSame('CSC5', OfficeSetting::current()->certificate_prefix);
    }

    /**
     * Once certificates exist the prefix is fixed, and the server is what fixes
     * it.
     *
     * The screen disables the field, but a disabled input is a courtesy rather
     * than a control — the form still posts, and a tab opened before the first
     * certificate was issued would post the old value quite innocently.
     */
    public function test_the_certificate_prefix_is_ignored_once_certificates_exist(): void
    {
        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload(['certificate_prefix' => 'CSC5']))
            ->assertSessionHasNoErrors();

        $this->issueCertificate();

        $this->flushSession();

        $this->actingAs($this->superadmin())
            ->get('/admin/office')
            ->assertInertia(fn ($page) => $page->where('certificatePrefixLocked', true));

        $this->flushSession();

        $this->actingAs($this->superadmin())
            ->post('/admin/office', $this->payload(['certificate_prefix' => 'CHANGED']))
            ->assertSessionHasNoErrors();

        $this->assertSame('CSC5', OfficeSetting::current()->certificate_prefix);
    }

    // --- The trail --------------------------------------------------------

    /**
     * Only what moved, with from/to — the same shape as `user.updated`, because
     * the form posts every field whether or not it changed.
     */
    public function test_only_the_changed_fields_are_recorded(): void
    {
        $superadmin = $this->superadmin();

        $this->actingAs($superadmin)->post('/admin/office', $this->payload());

        ActivityLog::query()->delete();

        $this->actingAs($superadmin)->post('/admin/office', $this->payload(['phone' => '(052) 999-9999']));

        $entry = ActivityLog::where('action', 'office.settings_updated')->sole();

        $this->assertSame(['phone'], $entry->properties['changed']);
        $this->assertSame('(052) 000-0000', $entry->properties['from']['phone']);
        $this->assertSame('(052) 999-9999', $entry->properties['to']['phone']);
    }

    public function test_saving_nothing_new_records_nothing(): void
    {
        $superadmin = $this->superadmin();

        $this->actingAs($superadmin)->post('/admin/office', $this->payload());

        ActivityLog::query()->delete();

        $this->actingAs($superadmin)->post('/admin/office', $this->payload());

        $this->assertSame(0, ActivityLog::where('action', 'office.settings_updated')->count());
    }

    // --- Who may reach it -------------------------------------------------

    public function test_the_screen_is_superadmin_only(): void
    {
        foreach ([Role::Admin, Role::FieldOffice, Role::Management, Role::CollectingOfficer] as $role) {
            $this->flushSession();

            $staff = User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);

            // 403 rather than 404: EnsureUserIsStaff refuses a role that is not
            // on the list, and the screen's existence is not a secret from
            // other staff — only its use is.
            $this->actingAs($staff)->get('/admin/office')->assertForbidden();
            $this->actingAs($staff)->post('/admin/office', $this->payload())->assertForbidden();
        }

        $this->assertNull(OfficeSetting::current());
    }

    private function issueCertificate(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);

        Certificate::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => ($training = Training::factory()->create())->getKey(),
            'registration_id' => Registration::factory()->completed()->create([
                'user_id' => $participant->getKey(),
                'training_id' => $training->getKey(),
            ])->getKey(),
        ]);
    }
}
