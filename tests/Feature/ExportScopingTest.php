<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Exports and analytics must honour field-office scoping.
 *
 * This is the highest-risk surface added in this phase: an export that leaked
 * another office's participants would be a data-protection incident, not a
 * cosmetic bug. Every assertion here is about what must NOT appear.
 */
class ExportScopingTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $officeA;

    private FieldOffice $officeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->officeA, $this->officeB] = FieldOffice::active()->take(2)->get()->all();
    }

    private function staffFor(?FieldOffice $office, Role $role = Role::FieldOffice): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            'field_office_id' => $office?->getKey(),
        ]);
    }

    private function participantIn(FieldOffice $office, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->getKey()]);

        return $user->refresh();
    }

    /** Drain a streamed download into a string. */
    private function body(TestResponse $response): string
    {
        return $response->streamedContent();
    }

    public function test_participant_export_is_limited_to_the_staff_members_office(): void
    {
        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $response = $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/exports/participants')
            ->assertOk();

        $csv = $this->body($response);

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
        $this->assertStringNotContainsString($theirs->email, $csv);
    }

    public function test_an_admin_export_covers_every_office(): void
    {
        $a = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $b = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $csv = $this->body(
            $this->actingAs($this->staffFor(null, Role::Admin))
                ->get('/admin/exports/participants')
                ->assertOk()
        );

        $this->assertStringContainsString($a->name, $csv);
        $this->assertStringContainsString($b->name, $csv);
    }

    public function test_registration_export_is_scoped(): void
    {
        $training = Training::factory()->create();

        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(), 'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(), 'training_id' => $training->getKey(),
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get('/admin/exports/registrations')
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
    }

    public function test_roster_export_is_scoped_and_carries_attendance(): void
    {
        $training = Training::factory()->startingToday()->runningFor(2)->create();

        $mine = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');
        $theirs = $this->participantIn($this->officeB, 'BRAVO PARTICIPANT');

        $registration = Registration::factory()->approved()->create([
            'user_id' => $mine->getKey(), 'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $theirs->getKey(), 'training_id' => $training->getKey(),
        ]);

        Attendance::factory()->create([
            'registration_id' => $registration->getKey(),
            'attendance_date' => $training->starts_at->toDateString(),
        ]);

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get("/admin/exports/trainings/{$training->id}/roster")
                ->assertOk()
        );

        $this->assertStringContainsString($mine->name, $csv);
        $this->assertStringNotContainsString($theirs->name, $csv);
        // Per-day columns, and the recorded attendance in one of them.
        $this->assertStringContainsString('Day 1', $csv);
        $this->assertStringContainsString('Day 2', $csv);
        $this->assertStringContainsString('Present', $csv);
    }

    public function test_a_field_office_user_with_no_office_exports_nothing(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        // Fails closed, matching FieldOfficeScopingTest's rule for listings.
        $csv = $this->body(
            $this->actingAs($this->staffFor(null))
                ->get('/admin/exports/participants')
                ->assertOk()
        );

        $this->assertStringNotContainsString('ALPHA PARTICIPANT', $csv);
    }

    public function test_only_finance_roles_can_export_payments(): void
    {
        Payment::factory()->create();

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/exports/payments')
            ->assertForbidden();

        $this->actingAs($this->staffFor(null, Role::CollectingOfficer))
            ->get('/admin/exports/payments')
            ->assertOk();
    }

    public function test_participants_cannot_reach_any_export(): void
    {
        $participant = $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        foreach (['participants', 'registrations', 'payments'] as $export) {
            $this->actingAs($participant)
                ->get("/admin/exports/{$export}")
                ->assertForbidden();
        }
    }

    public function test_an_export_can_be_requested_as_xlsx(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?format=xlsx')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );
    }

    public function test_an_unknown_format_falls_back_to_csv(): void
    {
        $this->participantIn($this->officeA, 'ALPHA PARTICIPANT');

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/exports/participants?format=exe')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // --- Analytics --------------------------------------------------------

    public function test_analytics_counts_are_scoped_to_the_office(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeB, 'BRAVO ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeB, 'BRAVO TWO')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Analytics')
                ->where('headline.registrations', 1)
                // The per-office breakdown is meaningless when scoped to one.
                ->has('byFieldOffice', 0)
            );

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('headline.registrations', 3));
    }

    /**
     * The demographic cuts are what CSC reports upward, and they are scoped
     * like everything else — a field office reporting the region's intake as
     * its own would be worse than not reporting at all.
     */
    public function test_demographic_breakdowns_are_scoped_to_the_office(): void
    {
        $training = Training::factory()->create();

        foreach ([[$this->officeA, 'ALPHA ONE'], [$this->officeB, 'BRAVO ONE'], [$this->officeB, 'BRAVO TWO']] as [$office, $name]) {
            Registration::factory()->approved()->create([
                'user_id' => $this->participantIn($office, $name)->getKey(),
                'training_id' => $training->getKey(),
            ]);
        }

        // Inertia's `where` hands the closure a Collection, not the raw array.
        $total = fn ($rows) => collect($rows)->sum('count');

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('demographics.sex', fn ($rows) => $total($rows) === 1)
                ->where('demographics.positionLevel', fn ($rows) => $total($rows) === 1)
                ->where('topAgencies', fn ($rows) => $total($rows) === 1)
            );

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('demographics.sex', fn ($rows) => $total($rows) === 3)
                ->where('topAgencies', fn ($rows) => $total($rows) === 3)
            );
    }

    /**
     * Every cut counts the same registrations, so their totals must agree.
     * A per-column GROUP BY would quietly drop rows with a blank field and
     * leave two charts on the same page disagreeing about the intake.
     */
    public function test_every_demographic_cut_totals_the_same(): void
    {
        $training = Training::factory()->create();

        foreach (['ALPHA ONE', 'ALPHA TWO', 'ALPHA THREE'] as $name) {
            Registration::factory()->approved()->create([
                'user_id' => $this->participantIn($this->officeA, $name)->getKey(),
                'training_id' => $training->getKey(),
            ]);
        }

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(function ($page) {
                $demographics = $page->toArray()['props']['demographics'];
                $totals = array_map(
                    fn ($rows) => array_sum(array_column($rows, 'count')),
                    $demographics,
                );

                $this->assertSame([3], array_values(array_unique($totals)));
            });
    }

    public function test_age_bands_keep_their_order_rather_than_sorting_by_size(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(function ($page) {
                $labels = array_column($page->toArray()['props']['demographics']['ageBand'], 'label');

                $this->assertSame(['18-25', '26-35', '36-45', '46-55', '56-65', 'Over 65'], $labels);
            });
    }

    public function test_analytics_hides_money_from_non_finance_roles(): void
    {
        Payment::factory()->verified()->create();

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('payments', null));

        $this->actingAs($this->staffFor(null, Role::CollectingOfficer))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('payments.verified_total'));
    }

    public function test_analytics_reports_an_attendance_rate(): void
    {
        $training = Training::factory()->startingToday()->create();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($this->officeA, 'ALPHA ONE')->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Attendance::factory()->create(['registration_id' => $registration->getKey()]);
        Attendance::factory()->absent()->onDay(2)->create(['registration_id' => $registration->getKey()]);

        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attendance.total', 2)
                // One Present, one Absent — JSON renders the 50.0 as 50.
                ->where('attendance.rate', 50)
            );
    }
}
