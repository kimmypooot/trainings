<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\User;
use App\Support\AgencyReference;
use Database\Seeders\AgencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The employer list, and the screen that maintains it.
 *
 * `agencies` shipped as seeder-only reference data, so correcting a name or
 * adding a missing employer meant editing a JSON file and redeploying. The
 * cases here cover the screen that replaced that, and three things about it
 * that are silent when wrong: the gap report that says what is missing, the
 * generated code that stops the seeder forking a hand-added row, and the field
 * office change that moves people between offices' views.
 */
class AgencyManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function participantOf(Agency $agency): User
    {
        $user = User::factory()->create(['role' => Role::Participant]);

        Profile::factory()->create([
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'organization_name' => $agency->name,
            'sector' => $agency->sector,
            'field_office_id' => $agency->field_office_id,
        ]);

        return $user;
    }

    /** A participant who typed their employer because it was not on the list. */
    private function typedEmployer(string $name): User
    {
        $user = User::factory()->create(['role' => Role::Participant]);

        Profile::factory()->create([
            'user_id' => $user->id,
            'agency_id' => null,
            'organization_name' => $name,
        ]);

        return $user;
    }

    public function test_an_admin_can_open_the_agency_list(): void
    {
        Agency::factory()->count(3)->create();

        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Agencies/Index')
                ->where('counts.all', 3)
                ->has('agencies.data', 3)
            );
    }

    /**
     * Region-wide reference data, so it is not a field office's to edit.
     *
     * A field-office user changing this list would be changing what every
     * other office is offered on the profile form — which is the opposite of
     * what their scoping means everywhere else in the application.
     */
    public function test_a_field_office_user_cannot_reach_the_agency_screens(): void
    {
        $user = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => FieldOffice::factory()->create()->id,
        ]);
        $agency = Agency::factory()->create();

        $this->actingAs($user)->get('/admin/agencies')->assertForbidden();

        $this->flushSession();
        $this->actingAs($user)->get('/admin/agencies/create')->assertForbidden();

        $this->flushSession();
        $this->actingAs($user)
            ->put("/admin/agencies/{$agency->id}", [
                'name' => 'REBRANDED',
                'sector' => $agency->sector,
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_a_participant_cannot_reach_the_agency_screens(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Participant]))
            ->get('/admin/agencies')
            ->assertForbidden();
    }

    public function test_an_admin_can_add_an_agency(): void
    {
        $office = FieldOffice::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/agencies', [
                'name' => 'LEYTE METROPOLITAN WATER DISTRICT',
                'acronym' => 'LMWD',
                'sector' => 'Government-Owned and Controlled Corporation (GOCC)',
                'field_office_id' => $office->id,
                'is_active' => true,
            ])
            ->assertRedirect('/admin/agencies');

        $agency = Agency::firstWhere('name', 'LEYTE METROPOLITAN WATER DISTRICT');

        $this->assertNotNull($agency);
        $this->assertSame($office->id, $agency->field_office_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'agency.created']);
    }

    /**
     * A hand-added row carries a code, and that is what stops it being forked.
     *
     * AgencySeeder matches on `code` and falls back to `name` when there is
     * none. A row added here with a null code would therefore be matched by
     * name, so correcting its spelling on this screen and re-running the
     * seeder later would create a *second* agency and strand every profile
     * already linked to the first — the exact "one employer, two spellings"
     * split the table exists to end.
     */
    public function test_an_agency_added_here_is_given_a_code(): void
    {
        $this->actingAs($this->admin())->post('/admin/agencies', [
            'name' => 'PALO WATER DISTRICT',
            'sector' => 'Local Government Unit (LGU)',
            'is_active' => true,
        ]);

        $agency = Agency::firstWhere('name', 'PALO WATER DISTRICT');

        $this->assertSame('ui-'.$agency->id, $agency->code);
    }

    /**
     * The collision this screen made possible, and the seeder now absorbs.
     *
     * An office adds an agency the source list does not have yet. Later the
     * list is regenerated and *does* have it, under a code no row here
     * carries — so the seeder looks that code up, finds nothing, and inserts a
     * second row with the same name. `agencies.name` is unique, so this does
     * not duplicate quietly: the seed **dies part-applied** with a constraint
     * violation naming a column nobody was thinking about.
     *
     * This was reproduced before the fix was written, and it is the reason the
     * seeder falls back to matching on name and adopts the row.
     */
    public function test_a_hand_added_agency_is_adopted_when_the_source_list_catches_up(): void
    {
        $this->actingAs($this->admin())->post('/admin/agencies', [
            'name' => 'PALO WATER DISTRICT',
            'sector' => 'Local Government Unit (LGU)',
            'is_active' => true,
        ]);

        $agency = Agency::firstWhere('name', 'PALO WATER DISTRICT');
        $participant = $this->participantOf($agency);

        $this->withAgencyFile(
            [[
                'code' => 'jp-500',
                'name' => 'PALO WATER DISTRICT',
                'sector' => 'Local Government Unit (LGU)',
            ]],
            fn () => (new AgencySeeder)->run(),
        );

        $this->assertSame(
            1,
            Agency::where('name', 'PALO WATER DISTRICT')->count(),
            'The agency should have been adopted, not duplicated.'
        );

        $adopted = $agency->fresh();

        $this->assertNotNull($adopted, 'The hand-added row must survive, not be replaced.');
        $this->assertSame('jp-500', $adopted->code, 'It should take the source list\'s code.');
        $this->assertSame(
            $agency->id,
            $participant->profile->fresh()->agency_id,
            'Everyone already filed under it must stay linked to the same row.'
        );
    }

    /**
     * Adding an agency from a typed employer must actually resolve it.
     *
     * This is the case the gap panel exists for, and the one it got wrong
     * first time: creating "Department of Education" left the people who typed
     * "DEPED" exactly where they were — `agency_id` still null, so the panel
     * went on offering the same row and working through the list changed
     * nothing.
     *
     * Note the name is deliberately *corrected* on the way in. Matching on the
     * name that was saved would find nobody, which is why the typed string
     * travels separately from it.
     */
    public function test_adding_an_agency_from_a_typed_employer_moves_the_people_who_typed_it(): void
    {
        $office = FieldOffice::factory()->create();
        $this->typedEmployer('DEPED');
        $this->typedEmployer('DEPED');
        $other = $this->typedEmployer('SOMETHING ELSE');

        $this->actingAs($this->admin())->post('/admin/agencies', [
            'name' => 'Department of Education',
            'acronym' => 'DepEd',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $office->id,
            'is_active' => true,
            'claim' => 'DEPED',
        ])->assertRedirect('/admin/agencies');

        $agency = Agency::firstWhere('name', 'Department of Education');
        $moved = Profile::where('agency_id', $agency->id)->get();

        $this->assertCount(2, $moved, 'Both people who typed DEPED should have been moved.');

        foreach ($moved as $profile) {
            // The reference's own spelling, not an upper-cased copy — the same
            // shape ProfileService writes when the participant picks the agency
            // themselves. Only a *typed* employer is upper-cased.
            $this->assertSame('Department of Education', $profile->organization_name);
            $this->assertSame('National Government Agency (NGA)', $profile->sector);
            $this->assertSame($office->id, $profile->field_office_id);
        }

        // Somebody who typed something else is not swept up by a decision
        // about a name they never used.
        $this->assertNull($other->profile->fresh()->agency_id);
    }

    /** And the panel shrinks, which is the whole point of it being a worklist. */
    public function test_the_gap_shrinks_once_a_typed_employer_is_resolved(): void
    {
        $this->typedEmployer('DEPED');

        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertInertia(fn ($page) => $page->where('unlisted.distinct', 1));

        $this->flushSession();
        $this->actingAs($this->admin())->post('/admin/agencies', [
            'name' => 'Department of Education',
            'sector' => 'National Government Agency (NGA)',
            'is_active' => true,
            'claim' => 'DEPED',
        ]);

        $this->flushSession();
        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertInertia(fn ($page) => $page
                ->where('unlisted.distinct', 0)
                ->where('unlisted.participants', 0)
            );
    }

    /**
     * The shortcut case the "Add to list" button cannot serve.
     *
     * "DEPED" typed while "Department of Education" is already on the list.
     * Adding it again is refused by the unique name — correctly — so matching
     * is the only way that spelling ever leaves the panel.
     */
    public function test_a_typed_shortcut_can_be_matched_to_an_agency_already_on_the_list(): void
    {
        $office = FieldOffice::factory()->create();
        $agency = Agency::factory()->create([
            'name' => 'Department of Education',
            'acronym' => 'DepEd',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $office->id,
        ]);

        $this->typedEmployer('DEPED');
        $this->typedEmployer('DEP ED');

        $this->actingAs($this->admin())
            ->post('/admin/agencies/resolve', [
                'organization_name' => 'DEPED',
                'agency_id' => $agency->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, Profile::where('agency_id', $agency->id)->count());
        $this->assertSame(
            'Department of Education',
            Profile::where('agency_id', $agency->id)->value('organization_name')
        );

        // The other spelling is a separate decision, still waiting.
        $this->assertSame(1, Profile::whereNull('agency_id')
            ->where('organization_name', 'DEP ED')
            ->count());

        $this->assertDatabaseHas('activity_logs', ['action' => 'agency.employers_claimed']);
    }

    /**
     * Someone who has since picked an agency properly is not swept up by a
     * decision about a name they no longer carry.
     */
    public function test_matching_only_touches_profiles_that_are_still_unlinked(): void
    {
        $agency = Agency::factory()->create(['name' => 'Department of Education']);
        $already = Agency::factory()->create(['name' => 'Department of Health']);

        $linked = $this->participantOf($already);
        // The same typed string, but this one has already been resolved.
        $linked->profile->update(['organization_name' => 'DEPED']);

        $this->typedEmployer('DEPED');

        $this->actingAs($this->admin())->post('/admin/agencies/resolve', [
            'organization_name' => 'DEPED',
            'agency_id' => $agency->id,
        ]);

        $this->assertSame(
            $already->id,
            $linked->profile->fresh()->agency_id,
            'A profile that already points at an agency must be left alone.'
        );
        $this->assertSame(1, Profile::where('agency_id', $agency->id)->count());
    }

    /** An agency naming no office leaves the participant's own answer standing. */
    public function test_matching_to_an_agency_without_an_office_keeps_the_participants_office(): void
    {
        $ownOffice = FieldOffice::factory()->create();
        $agency = Agency::factory()->withoutFieldOffice()->create();

        $user = User::factory()->create(['role' => Role::Participant]);
        Profile::factory()->create([
            'user_id' => $user->id,
            'agency_id' => null,
            'organization_name' => 'SOME OFFICE',
            'field_office_id' => $ownOffice->id,
        ]);

        $this->actingAs($this->admin())->post('/admin/agencies/resolve', [
            'organization_name' => 'SOME OFFICE',
            'agency_id' => $agency->id,
        ]);

        $this->assertSame($ownOffice->id, $user->profile->fresh()->field_office_id);
        $this->assertSame($agency->id, $user->profile->fresh()->agency_id);
    }

    /** The form needs the number before it can promise anything with it. */
    public function test_the_create_form_carries_how_many_typed_that_employer(): void
    {
        $this->typedEmployer('DEPED');
        $this->typedEmployer('DEPED');

        $this->actingAs($this->admin())
            ->get('/admin/agencies/create?name=DEPED')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('claimCount', 2));
    }

    public function test_a_field_office_user_cannot_resolve_a_typed_employer(): void
    {
        $agency = Agency::factory()->create();
        $this->typedEmployer('DEPED');

        $user = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => FieldOffice::factory()->create()->id,
        ]);

        $this->actingAs($user)
            ->post('/admin/agencies/resolve', [
                'organization_name' => 'DEPED',
                'agency_id' => $agency->id,
            ])
            ->assertForbidden();
    }

    /**
     * Correcting the text, without deciding what agency it is.
     *
     * The third thing a typed employer can need. It rewrites the participants'
     * own records — `organization_name` is what every export, roster and search
     * shows — and deliberately links nothing, because an agency may not exist
     * for this employer yet and inventing one to tidy a spelling would put a
     * row in the picker nobody decided to add.
     */
    public function test_a_typed_employer_can_be_corrected_without_linking_anything(): void
    {
        $user = $this->typedEmployer('DEP ED');

        $this->actingAs($this->admin())
            ->post('/admin/agencies/typed-employers/rename', [
                'organization_name' => 'DEP ED',
                'name' => 'Department of Education',
            ])
            ->assertRedirect();

        $profile = $user->profile->fresh();

        // Written exactly as the administrator typed it. A participant's own
        // entry is upper-cased because free text has no owner to be canonical
        // for it; a correction made on this screen is that owner deciding, so
        // shouting it back would refuse the correction being made.
        $this->assertSame('Department of Education', $profile->organization_name);
        $this->assertNull($profile->agency_id, 'Correcting a spelling must not link an agency.');
        $this->assertSame(0, Agency::count(), 'Nor invent one.');
        $this->assertDatabaseHas('activity_logs', ['action' => 'agency.typed_employer_renamed']);
    }

    /**
     * Renaming onto a spelling already in the panel merges the two.
     *
     * This is the point of the button rather than an accident: three spellings
     * of one employer are three rows and three separate decisions until they
     * read the same, and one afterwards.
     */
    public function test_correcting_a_spelling_merges_it_with_a_matching_one(): void
    {
        $this->typedEmployer('DEPED');
        $this->typedEmployer('DEP ED');
        $this->typedEmployer('DEP ED');

        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertInertia(fn ($page) => $page->where('unlisted.distinct', 2));

        $this->flushSession();
        $this->actingAs($this->admin())->post('/admin/agencies/typed-employers/rename', [
            'organization_name' => 'DEP ED',
            'name' => 'DEPED',
        ]);

        $this->flushSession();
        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertInertia(fn ($page) => $page
                ->where('unlisted.distinct', 1)
                ->where('unlisted.rows.0.name', 'DEPED')
                ->where('unlisted.rows.0.participants', 3)
            );
    }

    /** Someone who has already picked an agency is not caught by a spelling fix. */
    public function test_correcting_a_spelling_leaves_linked_profiles_alone(): void
    {
        $agency = Agency::factory()->create(['name' => 'Department of Health']);
        $linked = $this->participantOf($agency);
        $linked->profile->update(['organization_name' => 'DEPED']);

        $this->typedEmployer('DEPED');

        $this->actingAs($this->admin())->post('/admin/agencies/typed-employers/rename', [
            'organization_name' => 'DEPED',
            'name' => 'Department of Education',
        ]);

        $this->assertSame(
            'DEPED',
            $linked->profile->fresh()->organization_name,
            'A profile that already points at an agency must keep what that agency gave it.'
        );
    }

    public function test_a_field_office_user_cannot_correct_a_typed_employer(): void
    {
        $this->typedEmployer('DEPED');

        $user = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => FieldOffice::factory()->create()->id,
        ]);

        $this->actingAs($user)
            ->post('/admin/agencies/typed-employers/rename', [
                'organization_name' => 'DEPED',
                'name' => 'Department of Education',
            ])
            ->assertForbidden();
    }

    /**
     * Run something with the agency data file swapped for a fixture.
     *
     * AgencyReference caches the decoded file for the life of the process, so
     * the flush on both sides is what stops one test's fixture leaking into the
     * next — and stops the committed 285 loading into a test that wanted one.
     *
     * @param  array<int, array<string, mixed>>  $agencies
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

    /**
     * A correction follows through to everyone filed under the agency.
     *
     * `profiles.organization_name` and `.sector` are denormalized copies — they
     * are what every export, roster and search actually reads — so a correction
     * that stopped at the reference row would leave the list right and the data
     * still wrong.
     */
    public function test_correcting_an_agency_updates_the_profiles_linked_to_it(): void
    {
        $agency = Agency::factory()->create([
            'name' => 'DEPARTMENT OF EDUCATON',
            'sector' => 'National Government Agency (NGA)',
        ]);
        $participant = $this->participantOf($agency);

        $this->actingAs($this->admin())->put("/admin/agencies/{$agency->id}", [
            'name' => 'DEPARTMENT OF EDUCATION',
            'sector' => 'National Government Agency (NGA)',
            'field_office_id' => $agency->field_office_id,
            'is_active' => true,
        ])->assertRedirect('/admin/agencies');

        $this->assertSame(
            'DEPARTMENT OF EDUCATION',
            $participant->profile->fresh()->organization_name
        );
    }

    /**
     * Changing the office is a visibility change, and the trail records it.
     *
     * Every linked participant moves into the new office's scoped view: they
     * leave one office's lists and exports and appear in another's. The audit
     * entry carries how many people it carried, because that number is the
     * whole significance of the edit.
     */
    public function test_changing_the_field_office_moves_the_people_filed_under_it(): void
    {
        $from = FieldOffice::factory()->create();
        $to = FieldOffice::factory()->create();

        $agency = Agency::factory()->create(['field_office_id' => $from->id]);
        $participant = $this->participantOf($agency);

        $this->assertSame($from->id, $participant->profile->field_office_id);

        $this->actingAs($this->admin())->put("/admin/agencies/{$agency->id}", [
            'name' => $agency->name,
            'acronym' => $agency->acronym,
            'sector' => $agency->sector,
            'field_office_id' => $to->id,
            'is_active' => true,
        ])->assertRedirect('/admin/agencies');

        $this->assertSame(
            $to->id,
            $participant->profile->fresh()->field_office_id,
            'The participant should have moved into the agency\'s new office.'
        );

        $entry = ActivityLog::where('action', 'agency.updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertContains('field_office_id', $entry->properties['changed']);
        $this->assertSame(1, $entry->properties['profiles_affected']);
    }

    /**
     * Only what moved. A form posts every field whether or not it changed, and
     * an office reassignment buried among four unchanged values is a trail
     * nobody scans.
     */
    public function test_the_audit_entry_records_only_the_fields_that_changed(): void
    {
        $agency = Agency::factory()->create(['acronym' => 'OLD']);

        $this->actingAs($this->admin())->put("/admin/agencies/{$agency->id}", [
            'name' => $agency->name,
            'acronym' => 'NEW',
            'sector' => $agency->sector,
            'field_office_id' => $agency->field_office_id,
            'is_active' => true,
        ]);

        $entry = ActivityLog::where('action', 'agency.updated')->latest('id')->firstOrFail();

        $this->assertSame(['acronym'], $entry->properties['changed']);
        $this->assertSame('OLD', $entry->properties['from']['acronym']);
        $this->assertSame('NEW', $entry->properties['to']['acronym']);
    }

    public function test_an_agency_can_be_deactivated_without_touching_its_people(): void
    {
        $agency = Agency::factory()->create();
        $participant = $this->participantOf($agency);

        $this->actingAs($this->admin())
            ->post("/admin/agencies/{$agency->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($agency->fresh()->is_active);
        $this->assertSame($agency->id, $participant->profile->fresh()->agency_id);
        $this->assertSame($agency->name, $participant->profile->fresh()->organization_name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'agency.deactivated']);

        // A deactivated agency drops out of the profile form's picker.
        $this->assertEmpty(array_filter(
            Agency::options(),
            fn (array $option) => $option['value'] === $agency->id,
        ));
    }

    /**
     * Deleting is refused for an agency anyone is filed under.
     *
     * `profiles.agency_id` is nullOnDelete, so the database would not object —
     * it would silently unlink those participants, dropping them back to the
     * typed-employer state with nothing recording that it happened.
     */
    public function test_an_agency_with_people_filed_under_it_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        $participant = $this->participantOf($agency);

        $superadmin = User::factory()->create(['role' => Role::SuperAdmin]);

        $this->actingAs($superadmin)
            ->delete("/admin/agencies/{$agency->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('agencies', ['id' => $agency->id]);
        $this->assertSame($agency->id, $participant->profile->fresh()->agency_id);
    }

    public function test_an_unused_agency_can_be_deleted_by_a_superadmin(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs(User::factory()->create(['role' => Role::SuperAdmin]))
            ->delete("/admin/agencies/{$agency->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('agencies', ['id' => $agency->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'agency.deleted']);
    }

    /** Deleting sits one role above managing, exactly as it does for offices. */
    public function test_an_admin_cannot_delete_an_agency(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/agencies/{$agency->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('agencies', ['id' => $agency->id]);
    }

    /**
     * The gap report — the reason this screen leads with something other than
     * the list.
     *
     * Before it, `agency_id` was written by the profile form and read by
     * nothing, so an employer missing from the list was invisible: the
     * participant typed their own, the registration completed, and nobody was
     * told. Ranked by how many people typed the same thing, because that is
     * the order worth working through.
     */
    public function test_the_screen_reports_employers_people_typed_ranked_by_how_many_typed_them(): void
    {
        $this->typedEmployer('PALO WATER DISTRICT');
        $this->typedEmployer('LEYTE METROPOLITAN WATER DISTRICT');
        $this->typedEmployer('LEYTE METROPOLITAN WATER DISTRICT');
        $this->typedEmployer('LEYTE METROPOLITAN WATER DISTRICT');

        // Somebody who picked from the list is not a gap.
        $this->participantOf(Agency::factory()->create());

        $this->actingAs($this->admin())
            ->get('/admin/agencies')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('unlisted.distinct', 2)
                ->where('unlisted.participants', 4)
                ->where('unlisted.rows.0.name', 'LEYTE METROPOLITAN WATER DISTRICT')
                ->where('unlisted.rows.0.participants', 3)
                ->where('unlisted.rows.1.name', 'PALO WATER DISTRICT')
                ->where('unlisted.rows.1.participants', 1)
            );
    }

    /**
     * The gap counts the whole list, never the filtered page.
     *
     * Wire it to the filters and it reads zero the moment somebody searches,
     * and the screen says the problem has gone away.
     */
    public function test_searching_the_list_does_not_narrow_the_gap_report(): void
    {
        $this->typedEmployer('PALO WATER DISTRICT');
        Agency::factory()->create(['name' => 'DEPARTMENT OF EDUCATION']);

        $this->actingAs($this->admin())
            ->get('/admin/agencies?search=NOTHING-MATCHES-THIS')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('agencies.data', 0)
                ->where('unlisted.distinct', 1)
                ->where('counts.all', 1)
            );
    }

    /** The typed name is carried into the form so nobody retypes it. */
    public function test_adding_a_typed_employer_prefills_its_name(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/agencies/create?name='.urlencode('PALO WATER DISTRICT'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Agencies/Form')
                ->where('prefillName', 'PALO WATER DISTRICT')
            );
    }

    /** Two rows with one name is the split this whole table exists to end. */
    public function test_an_agency_name_cannot_be_used_twice(): void
    {
        Agency::factory()->create(['name' => 'DEPARTMENT OF EDUCATION']);

        $this->actingAs($this->admin())
            ->post('/admin/agencies', [
                'name' => 'DEPARTMENT OF EDUCATION',
                'sector' => 'National Government Agency (NGA)',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('name');
    }

    /**
     * A sector off the profile form's own list bounces the participant's next
     * save on a field they never touched — the trap the relabel migration
     * exists to clean up after.
     */
    public function test_a_sector_outside_the_profile_options_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/agencies', [
                'name' => 'SOMETHING NEW',
                'sector' => 'Made Up Sector',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('sector');
    }

    /** The edit form needs the number before it can warn with it. */
    public function test_the_edit_form_carries_how_many_people_a_move_would_affect(): void
    {
        $agency = Agency::factory()->create();
        $this->participantOf($agency);
        $this->participantOf($agency);

        $this->actingAs($this->admin())
            ->get("/admin/agencies/{$agency->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Agencies/Form')
                ->where('linkedProfiles', 2)
            );
    }
}
