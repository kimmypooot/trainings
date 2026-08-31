<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header search box.
 *
 * The scoping cases here duplicate the spirit of FieldOfficeScopingTest on
 * purpose: a search endpoint is the classic place for a second participant
 * query to appear that nobody remembers to narrow, and the guard has to sit on
 * this route specifically rather than on the directory it shortcuts.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The directory renders `Profile::directoryName()`, not `users.name`, so a
     * fixture that only sets the account name gets a label out of the factory's
     * random profile. Both are set here, and the middle name and suffix are
     * cleared so the expected string is exactly "LAST, FIRST".
     */
    private function participantIn(?FieldOffice $office, string $last, string $first, string $email): User
    {
        $user = User::factory()->create([
            'name' => "{$last}, {$first}",
            'email' => $email,
            'profile_completed_at' => now(),
        ]);

        Profile::factory()->for($user)->create([
            'field_office_id' => $office?->id,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => null,
            'suffix' => null,
        ]);

        return $user->refresh();
    }

    public function test_it_finds_participants_and_trainings(): void
    {
        $this->participantIn(null, 'MAGSAYSAY', 'RAMON', 'ramon@example.com');
        Training::factory()->create(['title' => 'Records Management Seminar']);

        $staff = User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);

        $this->actingAs($staff)
            ->getJson('/admin/search?q=magsaysay')
            ->assertOk()
            ->assertJsonPath('participants.0.label', 'MAGSAYSAY, RAMON')
            ->assertJsonCount(0, 'trainings');

        $this->actingAs($staff)
            ->getJson('/admin/search?q=records')
            ->assertOk()
            ->assertJsonPath('trainings.0.label', 'Records Management Seminar')
            ->assertJsonCount(0, 'participants');
    }

    /**
     * A one-character term matches most of the table and helps nobody, so it is
     * answered with nothing rather than with the first five rows in the
     * database.
     */
    public function test_a_term_below_the_minimum_returns_nothing(): void
    {
        $this->participantIn(null, 'ABAD', 'JUAN', 'juan@example.com');

        $this->actingAs(User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]))
            ->getJson('/admin/search?q=a')
            ->assertOk()
            ->assertJsonCount(0, 'participants')
            ->assertJsonPath('more.participants', null);
    }

    public function test_field_office_staff_search_only_their_own_office(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $samar = FieldOffice::where('code', 'sfo')->firstOrFail();

        $mine = $this->participantIn($leyte, 'SANTOS', 'MARIA', 'maria.leyte@example.com');
        $this->participantIn($samar, 'SANTOS', 'PEDRO', 'pedro.samar@example.com');

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $leyte->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson('/admin/search?q=santos')
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.label', $mine->profile->directoryName());
    }

    /**
     * `scopedFieldOfficeId()` resolves to 0 for an unassigned field-office
     * account, which matches nothing. The box must fail closed the same way the
     * directory does rather than falling back to the region.
     */
    public function test_unassigned_field_office_staff_see_nothing(): void
    {
        $this->participantIn(FieldOffice::where('code', 'lfoi')->firstOrFail(), 'SANTOS', 'MARIA', 'maria@example.com');

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => null,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson('/admin/search?q=santos')
            ->assertOk()
            ->assertJsonCount(0, 'participants');
    }

    /**
     * Trainings belong to the region, not to a branch — the admin catalogue is
     * region-wide for every staff role, and the box must not invent a narrower
     * rule than the list it shortcuts.
     */
    public function test_trainings_are_not_scoped_to_a_field_office(): void
    {
        Training::factory()->create(['title' => 'Leadership Development Program']);

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => FieldOffice::where('code', 'lfoi')->firstOrFail()->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson('/admin/search?q=leadership')
            ->assertOk()
            ->assertJsonCount(1, 'trainings');
    }

    public function test_participants_may_not_search(): void
    {
        $participant = $this->participantIn(null, 'CRUZ', 'ANA', 'ana@example.com');

        $this->actingAs($participant)
            ->getJson('/admin/search?q=cruz')
            ->assertForbidden();
    }

    public function test_guests_may_not_search(): void
    {
        $this->getJson('/admin/search?q=cruz')->assertUnauthorized();
    }
}
