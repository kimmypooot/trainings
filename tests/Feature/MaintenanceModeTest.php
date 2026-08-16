<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Certificate;
use App\Models\ScanLink;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private function close(): void
    {
        SiteSetting::current()->forceFill(['maintenance_mode' => true])->save();
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    public function test_public_pages_are_served_normally_when_it_is_off(): void
    {
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_public_pages_return_the_notice_when_it_is_on(): void
    {
        $this->close();

        // 503, not 200 or a redirect: search engines must treat this as
        // temporary rather than indexing the notice as the homepage.
        $this->get('/')
            ->assertStatus(503)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Maintenance'));

        $this->get('/register')->assertStatus(503);
        $this->get('/privacy-policy')->assertStatus(503);
        $this->get('/terms-of-service')->assertStatus(503);
    }

    public function test_signed_in_staff_keep_working_while_it_is_on(): void
    {
        $this->close();

        $superadmin = $this->user(Role::SuperAdmin);

        $this->actingAs($superadmin)->get('/admin')->assertOk();
        $this->actingAs($superadmin)->get('/admin/maintenance')->assertOk();
        // Including the public site, so they can check what visitors would see.
        $this->actingAs($superadmin)->get('/')->assertOk();
    }

    /** Every CSC staff role works through maintenance, not just Super Admin. */
    public function test_all_staff_roles_bypass_maintenance(): void
    {
        $this->close();

        foreach (Role::staff() as $role) {
            $staff = $this->user($role);

            $this->actingAs($staff)
                ->get('/')
                ->assertOk();
        }
    }

    /**
     * Participants are treated as the public: they may sign in, but the portal
     * shows the notice once they are through.
     */
    public function test_participants_are_shown_the_notice_even_when_signed_in(): void
    {
        $this->close();

        $participant = $this->user(Role::Participant);

        // They can still reach and use sign-in.
        $this->get('/login')->assertOk();

        $this->actingAs($participant)->get('/')->assertStatus(503);
        $this->actingAs($participant)->get('/dashboard')->assertStatus(503);
    }

    public function test_sign_in_stays_reachable_so_staff_can_switch_it_back_off(): void
    {
        $this->close();

        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    /** Operational, not promotional: closing these would strand people mid-task. */
    public function test_certificate_verification_stays_open(): void
    {
        $this->close();

        $certificate = Certificate::factory()->released()->create();

        $this->get("/verify/{$certificate->verification_code}")->assertOk();
    }

    public function test_the_attendance_station_stays_open(): void
    {
        $this->close();

        $link = ScanLink::factory()->create();

        $this->get("/station/{$link->token}")->assertOk();
    }

    public function test_emailed_verification_links_still_work(): void
    {
        $this->close();

        $user = $this->user(Role::Participant);
        $url = URL::signedRoute('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)
            ->assertRedirect('/login');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_staff_see_a_banner_so_it_is_not_left_on_by_accident(): void
    {
        $admin = $this->user(Role::SuperAdmin);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenanceMode', false));

        $this->close();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenanceMode', true));
    }

    /**
     * Staff pass through maintenance mode, so on the public site they see a
     * working page and reasonably conclude the switch is broken. The flag has
     * to reach public pages too, so the layout can explain why.
     */
    public function test_staff_viewing_the_public_site_are_told_why_they_can_see_it(): void
    {
        $this->close();

        // Guest first: actingAs persists for the rest of the test.
        $this->get('/')->assertStatus(503);

        $superadmin = $this->user(Role::SuperAdmin);

        $this->actingAs($superadmin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenanceMode', true));
    }

    /**
     * A signed-in participant meets the notice on every route, so the notice
     * itself must offer the way out. It cannot read `auth.user` — this
     * middleware runs before Inertia's shared props are assembled — hence the
     * explicit flag. Without it the participant is stranded: /login bounces
     * them back here via the guest redirect, and POST /logout is exempt but
     * unreachable from the page.
     */
    public function test_the_notice_tells_a_signed_in_participant_they_can_sign_out(): void
    {
        $this->close();

        $this->actingAs($this->user(Role::Participant))
            ->get('/dashboard')
            ->assertStatus(503)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Maintenance')
                ->where('authenticated', true));
    }

    public function test_the_notice_shown_to_a_guest_does_not_claim_they_are_signed_in(): void
    {
        $this->close();

        $this->get('/')
            ->assertStatus(503)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('authenticated', false));
    }

    /** The way out has to actually work, not merely be offered. */
    public function test_a_participant_can_sign_out_from_the_notice(): void
    {
        $this->close();

        $this->actingAs($this->user(Role::Participant))
            ->post('/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_a_superadmin_can_toggle_maintenance_on_and_off(): void
    {
        $superadmin = $this->user(Role::SuperAdmin);

        $this->actingAs($superadmin)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance', ['enabled' => true, 'message' => 'Scheduled downtime.'])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHas('success');

        $this->assertTrue(SiteSetting::current()->maintenance_mode);
        $this->assertSame('Scheduled downtime.', SiteSetting::current()->maintenance_message);

        // The toggle stays reachable for a superadmin once the site is down.
        $this->actingAs($superadmin)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance', ['enabled' => false])
            ->assertRedirect('/admin/maintenance')
            ->assertSessionHas('success');

        $this->assertFalse(SiteSetting::current()->maintenance_mode);
    }

    public function test_toggling_maintenance_is_written_to_the_audit_trail(): void
    {
        $superadmin = $this->user(Role::SuperAdmin);

        $this->actingAs($superadmin)
            ->from('/admin/maintenance')
            ->post('/admin/maintenance', ['enabled' => true])
            ->assertRedirect('/admin/maintenance');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'system.maintenance',
            'causer_id' => $superadmin->id,
            'causer_name' => $superadmin->name,
        ]);
        $this->assertSame(true, ActivityLog::latest('id')->first()?->properties['enabled']);
    }

    public function test_only_a_superadmin_can_reach_the_maintenance_switch(): void
    {
        $this->actingAs($this->user(Role::Admin))
            ->get('/admin/maintenance')
            ->assertForbidden();

        $this->actingAs($this->user(Role::Admin))
            ->post('/admin/maintenance', ['enabled' => true])
            ->assertForbidden();

        $this->assertFalse(SiteSetting::current()->maintenance_mode);
    }

    public function test_the_notice_renders_the_saved_message(): void
    {
        SiteSetting::current()->forceFill([
            'maintenance_mode' => true,
            'maintenance_message' => 'Please stand by while we fix things.',
        ])->save();

        $this->get('/')
            ->assertStatus(503)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('message', 'Please stand by while we fix things.'));
    }

    public function test_the_maintenance_screen_renders_for_the_superadmin(): void
    {
        $this->actingAs($this->user(Role::SuperAdmin))
            ->get('/admin/maintenance')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Maintenance')
                ->where('maintenance.enabled', false)
            );
    }
}
