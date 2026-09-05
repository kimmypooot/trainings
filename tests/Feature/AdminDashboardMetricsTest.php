<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Models\Certificate;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin dashboard's analytics.
 *
 * Two things are being guarded, and only one of them is arithmetic.
 *
 * The arithmetic half is that a comparison against the previous period is
 * measured over the *same stretch* of it — the failure mode there is silent
 * and permanent: three days of September set against the whole of August
 * reads as a collapse in demand every single month, and looks like a business
 * problem rather than a bug.
 *
 * The other half is the one that matters more. Every figure a field office
 * sees must be its own, and every figure a role cannot act on must be absent
 * rather than zero. A KPI leaking another office's participants is the same
 * class of incident as an export doing it — see ExportScopingTest — and the
 * dashboard is the one page every staff member opens.
 */
class AdminDashboardMetricsTest extends TestCase
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

    private function participantIn(FieldOffice $office): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->getKey()]);

        return $user->refresh();
    }

    /** One KPI out of the set, by key. */
    private function kpi(array $metrics, string $key): ?array
    {
        return collect($metrics['kpis'])->firstWhere('key', $key);
    }

    private function attention(array $metrics, string $key): ?array
    {
        return collect($metrics['attention'])->firstWhere('key', $key);
    }

    // --- Scoping -----------------------------------------------------------

    public function test_registration_figures_are_limited_to_the_staff_members_office(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->count(3)->create([
            'user_id' => fn () => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->count(5)->create([
            'user_id' => fn () => $this->participantIn($this->officeB)->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $mine = DashboardMetrics::for($this->staffFor($this->officeA));

        $this->assertSame(3, $this->kpi($mine, 'registrations')['value']);
        $this->assertSame(3, collect($mine['pipeline'])->sum('count'));
    }

    public function test_an_unscoped_role_sees_the_whole_region(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->count(3)->create([
            'user_id' => fn () => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Registration::factory()->count(5)->create([
            'user_id' => fn () => $this->participantIn($this->officeB)->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $metrics = DashboardMetrics::for($this->staffFor(null, Role::Admin));

        $this->assertSame(8, $this->kpi($metrics, 'registrations')['value']);
    }

    public function test_certificate_figures_are_limited_to_the_staff_members_office(): void
    {
        $training = Training::factory()->create();

        Certificate::factory()->released()->create([
            'user_id' => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $training->getKey(),
        ]);
        Certificate::factory()->released()->count(4)->create([
            'user_id' => fn () => $this->participantIn($this->officeB)->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $metrics = DashboardMetrics::for($this->staffFor($this->officeA));

        $this->assertSame(1, $this->kpi($metrics, 'certificates')['value']);
        $this->assertSame(1, $this->attention($metrics, 'certificates')['count']);
    }

    // --- Comparison --------------------------------------------------------

    /**
     * Last month's figure covers the same number of days as this month so far.
     *
     * Registered on the 20th of last month, with today being the 5th: that
     * registration is outside the comparison window, and counting it would
     * make every early-month visit report a decline.
     */
    public function test_the_previous_period_matches_the_elapsed_part_of_this_month(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(4)->setTime(9, 0));

        $training = Training::factory()->create();
        $office = $this->officeA;

        $within = now()->subMonthNoOverflow()->startOfMonth()->addDay();
        $beyond = now()->subMonthNoOverflow()->startOfMonth()->addDays(20);

        foreach ([$within, $beyond] as $moment) {
            Registration::factory()->create([
                'user_id' => $this->participantIn($office)->getKey(),
                'training_id' => $training->getKey(),
                'registered_at' => $moment,
            ]);
        }

        Registration::factory()->create([
            'user_id' => $this->participantIn($office)->getKey(),
            'training_id' => $training->getKey(),
            'registered_at' => now()->startOfMonth()->addDay(),
        ]);

        $kpi = $this->kpi(DashboardMetrics::for($this->staffFor($office)), 'registrations');

        $this->assertSame(1, $kpi['value']);
        // One, not two: the 20th of last month has not happened yet this month.
        $this->assertSame(1.0, $kpi['delta']['previous']);
        $this->assertSame('flat', $kpi['delta']['direction']);
    }

    /**
     * A month with nothing behind it did not rise by a percentage.
     */
    public function test_a_period_with_no_history_reports_no_percentage(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->create([
            'user_id' => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $training->getKey(),
            'registered_at' => now(),
        ]);

        $kpi = $this->kpi(DashboardMetrics::for($this->staffFor($this->officeA)), 'registrations');

        $this->assertNull($kpi['delta']['percent']);
        $this->assertSame('up', $kpi['delta']['direction']);
    }

    /** Six months of history, oldest first, with empty months present as zero. */
    public function test_the_sparkline_carries_a_zero_for_a_month_with_no_activity(): void
    {
        $training = Training::factory()->create();

        Registration::factory()->create([
            'user_id' => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $training->getKey(),
            'registered_at' => now(),
        ]);

        $spark = $this->kpi(DashboardMetrics::for($this->staffFor($this->officeA)), 'registrations')['spark'];

        $this->assertCount(6, $spark);
        $this->assertSame([0, 0, 0, 0, 0, 1], $spark);
    }

    // --- Completion rate ---------------------------------------------------

    public function test_the_completion_rate_is_null_when_no_run_has_ended(): void
    {
        $kpi = $this->kpi(DashboardMetrics::for($this->staffFor(null, Role::Admin)), 'completion');

        $this->assertNull($kpi['value']);
        $this->assertNull($kpi['delta']);
    }

    public function test_the_completion_rate_counts_only_runs_that_have_ended(): void
    {
        $ended = Training::factory()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(9),
        ]);
        $running = Training::factory()->create([
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(6),
        ]);

        foreach ([RegistrationStatus::Completed, RegistrationStatus::Completed, RegistrationStatus::Approved] as $status) {
            Registration::factory()->create([
                'user_id' => $this->participantIn($this->officeA)->getKey(),
                'training_id' => $ended->getKey(),
                'status' => $status,
            ]);
        }

        // Approved on a run still to come. Counting it would drag the rate
        // down for work that is not yet due.
        Registration::factory()->create([
            'user_id' => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $running->getKey(),
            'status' => RegistrationStatus::Approved,
        ]);

        $kpi = $this->kpi(DashboardMetrics::for($this->staffFor($this->officeA)), 'completion');

        $this->assertSame(66.7, $kpi['value']);
    }

    // --- Role gating -------------------------------------------------------

    public function test_money_is_absent_for_a_role_that_does_not_collect_it(): void
    {
        $metrics = DashboardMetrics::for($this->staffFor(null, Role::Management));

        $this->assertNull($this->kpi($metrics, 'revenue'));
        $this->assertNull($this->attention($metrics, 'payments'));
    }

    public function test_a_collecting_officer_sees_what_was_banked(): void
    {
        $training = Training::factory()->paid()->create();
        $participant = $this->participantIn($this->officeA);

        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'amount' => 1500,
            'status' => PaymentStatus::Verified,
            'payment_method' => PaymentMethod::Cash,
            'payment_date' => now(),
        ]);

        // A note is verified the moment it is accepted and no money moved, so
        // it must not appear in what was collected.
        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
            'amount' => 9999,
            'status' => PaymentStatus::Verified,
            'payment_method' => PaymentMethod::Promissory,
            'payment_date' => now(),
        ]);

        /*
         * The designation, not the role name: `Role::financial()` is HRD and
         * superadmin, and everyone else — including the collecting-officer
         * role itself — reaches money through `is_collecting_officer`. Same
         * gate `EnsureUserCollectsPayments` applies.
         */
        $collector = $this->staffFor(null, Role::CollectingOfficer);
        $collector->forceFill(['is_collecting_officer' => true])->save();

        $kpi = $this->kpi(DashboardMetrics::for($collector->refresh()), 'revenue');

        $this->assertSame(1500.0, $kpi['value']);
    }

    /**
     * Clearing this queue means re-sending a certificate, which management
     * cannot do — so the row is absent rather than shown at a number nobody
     * at that desk can move.
     */
    public function test_a_queue_a_role_cannot_clear_is_not_offered_to_it(): void
    {
        $this->assertNull(
            $this->attention(DashboardMetrics::for($this->staffFor(null, Role::Management)), 'certificates')
        );

        $this->assertNotNull(
            $this->attention(DashboardMetrics::for($this->staffFor(null, Role::Admin)), 'certificates')
        );
    }

    // --- Capacity watch ----------------------------------------------------

    public function test_the_capacity_watch_names_the_emptiest_runs_first(): void
    {
        $empty = Training::factory()->create([
            'title' => 'Barely Booked',
            'status' => TrainingStatus::Published,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDay(),
            'capacity' => 100,
        ]);

        $full = Training::factory()->create([
            'title' => 'Nearly Full',
            'status' => TrainingStatus::Published,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDay(),
            'capacity' => 10,
        ]);

        Registration::factory()->count(9)->create([
            'user_id' => fn () => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $full->getKey(),
            'status' => RegistrationStatus::Approved,
        ]);
        Registration::factory()->create([
            'user_id' => $this->participantIn($this->officeA)->getKey(),
            'training_id' => $empty->getKey(),
            'status' => RegistrationStatus::Approved,
        ]);

        $rows = DashboardMetrics::for($this->staffFor(null, Role::Admin))['capacity'];

        $this->assertSame('Barely Booked', $rows[0]['label']);
        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame(90, $rows[1]['count']);
    }

    /** Without a capacity there is no such thing as under-filled. */
    public function test_a_run_with_no_capacity_is_not_watched(): void
    {
        Training::factory()->create([
            'status' => TrainingStatus::Published,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addDay(),
            'capacity' => null,
        ]);

        $this->assertSame([], DashboardMetrics::for($this->staffFor(null, Role::Admin))['capacity']);
    }

    // --- The page itself ---------------------------------------------------

    public function test_the_dashboard_renders_with_its_metrics(): void
    {
        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->has('metrics.kpis')
                ->has('metrics.attention')
                ->has('metrics.pipeline')
                ->has('metrics.period.label')
                ->has('totals.participants')
                ->etc()
            );
    }
}
