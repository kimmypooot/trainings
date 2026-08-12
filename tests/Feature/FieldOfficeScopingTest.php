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

class FieldOfficeScopingTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $leyte;

    private FieldOffice $samar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $this->samar = FieldOffice::where('code', 'sfo')->firstOrFail();
    }

    private function participantIn(FieldOffice $office, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->id]);

        return $user->refresh();
    }

    private function fieldOfficeStaff(?FieldOffice $office): User
    {
        return User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $office?->id,
            'profile_completed_at' => now(),
        ]);
    }

    public function test_directory_shows_only_the_staff_members_own_office(): void
    {
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $this->participantIn($this->samar, 'samar@example.com');

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 1)
                ->where('participants.data.0.email', $mine->email)
                ->where('scopedTo', 'CSC Field Office - Leyte I')
            );
    }

    public function test_hrd_sees_every_office(): void
    {
        $this->participantIn($this->leyte, 'leyte@example.com');
        $this->participantIn($this->samar, 'samar@example.com');

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('participants.data', 2)
                ->where('scopedTo', null)
            );
    }

    public function test_another_offices_participant_is_not_found(): void
    {
        $theirs = $this->participantIn($this->samar, 'samar@example.com');
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $staff = $this->fieldOfficeStaff($this->leyte);

        $this->actingAs($staff)->get("/admin/participants/{$theirs->id}")->assertNotFound();
        $this->actingAs($staff)->get("/admin/participants/{$mine->id}")->assertOk();
    }

    public function test_roster_is_filtered_but_the_training_stays_visible(): void
    {
        $training = Training::factory()->create();
        $mine = $this->participantIn($this->leyte, 'leyte@example.com');
        $theirs = $this->participantIn($this->samar, 'samar@example.com');

        RegistrationService::register($mine, $training);
        RegistrationService::register($theirs, $training);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations', 1)
                ->where('registrations.0.email', $mine->email)
                ->where('summary.active', 1)
                ->where('scopedTo', 'CSC Field Office - Leyte I')
            );

        // HRD sees both.
        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('registrations', 2));
    }

    public function test_dashboard_counts_are_scoped(): void
    {
        $training = Training::factory()->create();
        RegistrationService::register($this->participantIn($this->leyte, 'leyte@example.com'), $training);
        RegistrationService::register($this->participantIn($this->samar, 'samar@example.com'), $training);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.participants', 1)
                ->where('stats.registrations', 1)
                // The training catalogue is regional, so it is not scoped.
                ->where('stats.published', 1)
            );
    }

    public function test_staff_with_no_office_assigned_see_nothing(): void
    {
        $this->participantIn($this->leyte, 'leyte@example.com');

        // Fails closed: an unassigned field-office account must not fall back
        // to seeing every participant in the region.
        $this->actingAs($this->fieldOfficeStaff(null))
            ->get('/admin/participants')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('participants.data', 0));
    }

    public function test_participants_with_no_office_are_hidden_from_scoped_staff(): void
    {
        $user = User::factory()->create(['email' => 'orphan@example.com', 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => null]);

        $this->actingAs($this->fieldOfficeStaff($this->leyte))
            ->get('/admin/participants')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('participants.data', 0));
    }
}
