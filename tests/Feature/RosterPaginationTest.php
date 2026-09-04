<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\RosterFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The roster narrows, orders and pages on the server.
 *
 * All of it used to happen in the browser, which meant every participant of
 * every run arrived in the page payload — thirty-five fields each, on every
 * load and after every row action. That is fine for the twelve-person courses
 * this was built against and is not fine for a regional run.
 *
 * Moving it server-side moves three things that are easy to get wrong, and each
 * has a test here that fails if it is:
 *
 *  - the *rows* narrow with the filters, but the *counts* beside the chips do
 *    not, because a chip's number is an offer to go and look at those people;
 *  - the printed sheet is the whole filtered roster, not the page on screen;
 *  - field-office scoping applies to the four new props as well as the rows,
 *    since each one is a new way for another office's participants to leave the
 *    building.
 */
class RosterPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Training $training;

    protected function setUp(): void
    {
        parent::setUp();

        $this->training = Training::factory()->create(['duration_days' => 1]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'profile_completed_at' => now()]);
    }

    private function enrol(string $name, RegistrationStatus $status = RegistrationStatus::Approved, array $profile = []): Registration
    {
        $user = User::factory()->create(['name' => $name, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create($profile);

        return Registration::factory()->create([
            'user_id' => $user->id,
            'training_id' => $this->training->id,
            'status' => $status,
        ]);
    }

    private function roster(array $query = []): TestResponse
    {
        return $this->actingAs($this->staff())
            ->get("/admin/trainings/{$this->training->id}/roster?".http_build_query($query));
    }

    public function test_the_roster_arrives_a_page_at_a_time(): void
    {
        foreach (range(1, 30) as $n) {
            $this->enrol(sprintf('PARTICIPANT %02d', $n));
        }

        $this->roster()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', RosterFilter::PER_PAGE)
            ->where('registrations.total', 30)
            ->where('registrations.last_page', 2)
        );

        // The remainder, and not a second copy of the first page — the check
        // that the paginator is actually slicing rather than being decorative.
        $this->roster(['page' => 2])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 5)
            ->where('registrations.current_page', 2)
        );
    }

    public function test_a_search_narrows_the_rows_but_never_the_chip_counts(): void
    {
        $this->enrol('MARIA SANTOS');
        $this->enrol('JUAN DELA CRUZ');
        $this->enrol('PEDRO REYES', RegistrationStatus::Cancelled);

        $this->roster(['search' => 'santos'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.name', 'MARIA SANTOS')
            // The chips still describe the whole roster. If these tracked the
            // filtered set instead, every chip but the selected one would read
            // zero and the roster would look empty from the inside.
            ->where('counts.status.all', 3)
            ->where('counts.status.cancelled', 1)
        );
    }

    public function test_the_status_filter_keeps_only_that_status(): void
    {
        $this->enrol('APPROVED PERSON');
        $this->enrol('CANCELLED PERSON', RegistrationStatus::Cancelled);

        $this->roster(['status' => 'cancelled'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.name', 'CANCELLED PERSON')
        );

        // The negative half: the filter has to exclude, not merely include.
        $this->roster(['status' => 'approved'])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.name', 'APPROVED PERSON')
        );
    }

    public function test_sorting_is_the_servers_and_an_unknown_column_is_ignored(): void
    {
        $this->enrol('ZAMORA, ANA');
        $this->enrol('ABAD, BEN');

        $this->roster(['sort' => 'name', 'direction' => 'asc'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.data.0.name', 'ABAD, BEN'));

        $this->roster(['sort' => 'name', 'direction' => 'desc'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.data.0.name', 'ZAMORA, ANA'));

        /*
         * A sort key arrives from a query string, so it is untrusted input
         * reaching a column name. An unknown one is dropped rather than passed
         * through: the request answers normally, in registration order, and
         * `filters.sort` comes back null so the header does not draw an arrow
         * against a column nobody is sorted by.
         */
        $this->roster(['sort' => 'users.password'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('registrations.data', 2)
                ->where('filters.sort', null));
    }

    public function test_the_printed_sheet_is_the_whole_filtered_roster_and_is_absent_until_asked_for(): void
    {
        foreach (range(1, 30) as $n) {
            $this->enrol(sprintf('PARTICIPANT %02d', $n));
        }

        // Not on an ordinary load. This is the whole reason it is an optional
        // prop: thirty rows nobody asked for, on every visit, to serve a button
        // pressed once a session.
        $this->roster()->assertInertia(fn (AssertableInertia $page) => $page->missing('printRows'));

        $response = $this->actingAs($this->staff())->get(
            "/admin/trainings/{$this->training->id}/roster",
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Admin/Trainings/Roster',
                'X-Inertia-Partial-Data' => 'printRows',
            ]
        );

        // Thirty, while the screen holds twenty-five: printing page one of two
        // without saying so is the bug this prop exists to avoid.
        $response->assertOk();
        $this->assertCount(30, $response->json('props.printRows'));
    }

    public function test_the_printed_sheet_follows_the_filters(): void
    {
        $this->enrol('MARIA SANTOS');
        $this->enrol('JUAN DELA CRUZ');

        $response = $this->actingAs($this->staff())->get(
            "/admin/trainings/{$this->training->id}/roster?search=santos",
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Admin/Trainings/Roster',
                'X-Inertia-Partial-Data' => 'printRows',
            ]
        );

        $rows = $response->json('props.printRows');

        $this->assertCount(1, $rows);
        $this->assertSame('MARIA SANTOS', $rows[0]['name']);
    }

    /**
     * The predicate this change had to get right rather than merely move.
     *
     * The page called it `isMarkable` — approved or completed — and it would
     * have been natural to reach for `occupiesSlot()` on the way to the server,
     * which also counts *pending*. That would put people who have not been
     * approved yet on a chase list of no-shows: nobody told them to come.
     */
    public function test_not_checked_in_today_means_approved_and_absent_not_merely_unapproved(): void
    {
        $this->training->update([
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
        ]);

        $pending = $this->enrol('PENDING PERSON', RegistrationStatus::Pending);
        $absent = $this->enrol('ABSENT PERSON');
        $present = $this->enrol('PRESENT PERSON');

        Attendance::factory()->create([
            'registration_id' => $present->id,
            'training_day' => 1,
            'status' => AttendanceStatus::Present,
            'time_in' => now(),
        ]);

        $this->roster(['not_checked_in' => 1])->assertInertia(fn (AssertableInertia $page) => $page
            ->has('registrations.data', 1)
            ->where('registrations.data.0.name', 'ABSENT PERSON')
            ->where('counts.not_checked_in_today', 1)
        );

        $this->assertNotNull($pending->fresh(), 'the pending registration still exists — it is excluded, not missing');
    }

    /**
     * Four new props, four new ways for another office's people to leave.
     *
     * The rows were already guarded; `printRows`, `restrictions` and the chip
     * `counts` are new surfaces and each derives from the same scoped query, so
     * this asserts the whole shape rather than the list alone.
     */
    public function test_every_new_prop_respects_field_office_scoping(): void
    {
        $leyte = FieldOffice::where('code', 'lfoi')->firstOrFail();
        $samar = FieldOffice::where('code', 'sfo')->firstOrFail();

        $this->enrol('LEYTE PERSON', RegistrationStatus::Approved, [
            'field_office_id' => $leyte->id,
            'food_restrictions_details' => 'NO PORK',
        ]);
        $this->enrol('SAMAR PERSON', RegistrationStatus::Approved, [
            'field_office_id' => $samar->id,
            'food_restrictions_details' => 'NO SHELLFISH',
        ]);

        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $leyte->id,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get("/admin/trainings/{$this->training->id}/roster")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('registrations.total', 1)
                ->where('counts.status.all', 1)
                ->has('restrictions', 1)
                ->where('restrictions.0.food_restrictions', 'NO PORK')
            );

        $response = $this->actingAs($staff)->get(
            "/admin/trainings/{$this->training->id}/roster",
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Admin/Trainings/Roster',
                'X-Inertia-Partial-Data' => 'printRows',
            ]
        );

        $rows = $response->json('props.printRows');

        $this->assertCount(1, $rows);
        $this->assertSame('LEYTE PERSON', $rows[0]['name']);
    }
}
