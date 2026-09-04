<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => Role::SuperAdmin,
            'profile_completed_at' => now(),
        ]);
    }

    /*
     * The directory is HRD's to read and superadmin's to act on.
     *
     * v1's admin/hrd/collecting-officers page was exactly this — a read-only
     * list of the active field-office and HRD accounts, reachable by HRD —
     * and knowing which office has a collecting officer is how a payment gets
     * routed to the right desk. Only the administering half is superadmin's.
     */

    public function test_hrd_and_superadmins_reach_the_directory(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $this->actingAs($participant)->get('/admin/users')->assertForbidden();

        foreach ([Role::FieldOffice, Role::Management] as $role) {
            $staff = User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
            $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        }

        $admin = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($this->superAdmin())->get('/admin/users')->assertOk();
    }

    public function test_hrd_reads_the_directory_without_being_offered_the_controls(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                // No edit screen to link to — that route is superadmin's.
                ->where('users.data.0.edit_url', null)
                ->etc()
            );

        $this->actingAs($this->superAdmin())
            ->get('/admin/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', true)
                ->etc()
            );
    }

    public function test_administering_an_account_stays_superadmin_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
        $target = User::factory()->create(['role' => Role::FieldOffice, 'profile_completed_at' => now()]);

        $this->actingAs($admin)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($admin)->post('/admin/users', [])->assertForbidden();
        $this->actingAs($admin)->get("/admin/users/{$target->id}/edit")->assertForbidden();
        $this->actingAs($admin)->put("/admin/users/{$target->id}", [])->assertForbidden();
        $this->actingAs($admin)->post("/admin/users/{$target->id}/toggle")->assertForbidden();

        // The designation the directory reports is set on the edit screen, so
        // it stays out of HRD's reach along with it.
        $this->assertFalse($target->refresh()->is_collecting_officer);
    }

    public function test_the_list_shows_staff_only(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();
        User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($this->superAdmin())
            ->get('/admin/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users/Index')
                // The acting superadmin plus the admin — never the participant.
                ->has('users.data', 2)
            );
    }

    public function test_the_list_shows_last_sign_in(): void
    {
        // The stamp is written from now() and asserted against now() formatted
        // a few milliseconds later, so a minute rolling over between the two
        // fails a test that has nothing to do with clocks.
        $this->freezeTime();

        $user = User::factory()->create([
            'name' => 'Zoe Lastlogin',
            'role' => Role::Admin,
            'profile_completed_at' => now(),
            'last_login_at' => now(),
        ]);

        $this->actingAs($this->superAdmin())
            ->get('/admin/users?search=Zoe')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.last_login_at', now()->format('d M Y, g:i A'))
            );
    }

    public function test_superadmin_can_create_a_staff_account(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/admin/users', [
                'name' => 'Jane Cruz',
                'email' => 'jane@csc.gov.ph',
                'role' => Role::Admin->value,
                'password' => 'sikretokong123',
                'password_confirmation' => 'sikretokong123',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success');

        $user = User::where('email', 'jane@csc.gov.ph')->first();

        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('sikretokong123', $user->password));
        // Staff never fill in a participant profile, so the gate must not catch them.
        $this->assertTrue($user->hasCompletedProfile());
    }

    public function test_a_field_office_account_must_be_assigned_an_office(): void
    {
        $this->actingAs($this->superAdmin())
            ->from('/admin/users/create')
            ->post('/admin/users', [
                'name' => 'Office Staff',
                'email' => 'office@csc.gov.ph',
                'role' => Role::FieldOffice->value,
                'password' => 'sikretokong123',
                'password_confirmation' => 'sikretokong123',
            ])
            ->assertSessionHasErrors('field_office_id');
    }

    public function test_changing_away_from_field_office_clears_the_office(): void
    {
        $office = FieldOffice::where('code', 'lfoi')->first();
        $user = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($this->superAdmin())
            ->put("/admin/users/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'role' => Role::Admin->value,
                'is_active' => true,
            ])
            ->assertRedirect('/admin/users');

        $user->refresh();

        $this->assertSame(Role::Admin, $user->role);
        $this->assertNull($user->field_office_id);
    }

    public function test_password_is_only_changed_when_supplied(): void
    {
        $user = User::factory()->create([
            'role' => Role::Admin,
            'password' => 'originalpass123',
            'profile_completed_at' => now(),
        ]);
        $original = $user->password;

        $this->actingAs($this->superAdmin())->put("/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::Admin->value,
            'is_active' => true,
        ]);

        $this->assertSame($original, $user->fresh()->password);

        $this->actingAs($this->superAdmin())->put("/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => Role::Admin->value,
            'is_active' => true,
            'password' => 'brandnewpass123',
            'password_confirmation' => 'brandnewpass123',
        ]);

        $this->assertTrue(Hash::check('brandnewpass123', $user->fresh()->password));
    }

    public function test_a_superadmin_cannot_change_their_own_role_or_deactivate_themselves(): void
    {
        $me = $this->superAdmin();
        User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);

        $this->actingAs($me)
            ->from("/admin/users/{$me->id}/edit")
            ->put("/admin/users/{$me->id}", [
                'name' => $me->name,
                'email' => $me->email,
                'role' => Role::Admin->value,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($me)
            ->from('/admin/users')
            ->post("/admin/users/{$me->id}/toggle")
            ->assertSessionHasErrors('user');

        $this->assertSame(Role::SuperAdmin, $me->fresh()->role);
        $this->assertTrue($me->fresh()->is_active);
    }

    public function test_the_last_active_superadmin_cannot_be_demoted_or_deactivated(): void
    {
        $only = $this->superAdmin();
        $other = User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);

        // Demoting the second one is fine while another remains…
        $this->actingAs($only)->put("/admin/users/{$other->id}", [
            'name' => $other->name,
            'email' => $other->email,
            'role' => Role::Admin->value,
            'is_active' => true,
        ])->assertRedirect('/admin/users');

        // …but now `$only` is the last one, and an admin acting on them fails.
        $actor = User::factory()->create(['role' => Role::SuperAdmin, 'profile_completed_at' => now()]);
        $actor->update(['is_active' => false]);

        $this->actingAs($only)
            ->from('/admin/users')
            ->post("/admin/users/{$only->id}/toggle")
            ->assertSessionHasErrors('user');

        $this->assertTrue($only->fresh()->is_active);
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'off@csc.gov.ph',
            'password' => 'sikretokong123',
            'role' => Role::Admin,
            'is_active' => false,
            'profile_completed_at' => now(),
        ]);

        $this->from('/login')
            ->post('/login', ['email' => 'off@csc.gov.ph', 'password' => 'sikretokong123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('form');

        $this->assertGuest();
    }

    public function test_the_demo_seeder_assigns_roles_and_offices(): void
    {
        // Guards a real trap: role, field_office_id, and is_active are not
        // mass-assignable, so a seeder using fill() silently creates
        // participants instead of staff.
        $this->seed(DemoSeeder::class);

        $expected = [
            'admin@csc.gov.ph' => Role::Admin,
            'superadmin@csc.gov.ph' => Role::SuperAdmin,
            'fieldoffice@csc.gov.ph' => Role::FieldOffice,
            'management@csc.gov.ph' => Role::Management,
        ];

        foreach ($expected as $email => $role) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, "{$email} was not seeded.");
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->is_active);
        }

        $this->assertNotNull(
            User::where('email', 'fieldoffice@csc.gov.ph')->value('field_office_id'),
            'The field office account must be scoped to an office.'
        );

        /*
         * And it collects. A participant pays at the field office nearest them,
         * so the demo account has to be able to demonstrate the combination the
         * designation exists for: scoped to one office and holding the till.
         */
        $officer = User::where('email', 'fieldoffice@csc.gov.ph')->firstOrFail();

        $this->assertTrue($officer->collectsPayments());
        $this->assertTrue($officer->isScopedToFieldOffice());

        // Management is oversight, and must not pick the designation up.
        $this->assertFalse(
            User::where('email', 'management@csc.gov.ph')->firstOrFail()->collectsPayments()
        );
    }

    public function test_a_participant_account_cannot_be_edited_here(): void
    {
        $participant = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($participant)->create();

        $this->actingAs($this->superAdmin())
            ->get("/admin/users/{$participant->id}/edit")
            ->assertNotFound();
    }
}
