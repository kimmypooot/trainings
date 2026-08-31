<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The analytics report generator: a report for one selected training and a
 * report over all trainings conducted in a period, each with a revenue form
 * (carrying the PRIME-HRM discount) and a demographic breakdown.
 *
 * The scoping guard lives in ExportScopingTest; this file is about what the
 * reports actually count and what they are allowed to show.
 */
class AnalyticsReportTest extends TestCase
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

    /** Field-office staff carrying the collecting-officer designation. */
    private function collectorFor(?FieldOffice $office): User
    {
        $user = $this->staffFor($office);

        $user->forceFill(['is_collecting_officer' => true])->save();

        return $user;
    }

    /**
     * An administrator: financial by virtue of the job, and scoped to no
     * office, so they see the whole region.
     */
    private function admin(): User
    {
        return $this->staffFor(null, Role::Admin);
    }

    private function participantIn(FieldOffice $office, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['field_office_id' => $office->getKey()]);

        return $user->refresh();
    }

    private function registrationIn(FieldOffice $office, Training $training, string $name): Registration
    {
        return Registration::factory()->approved()->create([
            'user_id' => $this->participantIn($office, $name)->getKey(),
            'training_id' => $training->getKey(),
        ]);
    }

    private function paymentFor(Registration $registration, array $overrides = []): Payment
    {
        return Payment::factory()->verified()->create(array_merge([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $registration->training_id,
        ], $overrides));
    }

    /** Drain a streamed download into a string. */
    private function body(TestResponse $response): string
    {
        return $response->streamedContent();
    }

    // --- Report by selected training --------------------------------------

    public function test_the_training_revenue_report_carries_the_prime_hrm_discount(): void
    {
        $training = Training::factory()->paid(1500)->create();

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA DISCOUNTED'), [
            'amount' => 1200,
            'discount_amount' => 300,
            'prime_hrm_discount' => true,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PLAIN'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Online,
        ]);
        // Verified but no money arrived — promised, not collected.
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PROMISSORY'), [
            'amount' => 2000,
            'payment_method' => PaymentMethod::Promissory,
        ]);
        // A claim in the queue is neither money nor a note.
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PENDING'), [
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'training')
                ->where('trainingReport.training.id', $training->getKey())
                ->where('trainingReport.revenue.gross', 3000)
                ->where('trainingReport.revenue.discount', 300)
                ->where('trainingReport.revenue.collected', 2700)
                ->where('trainingReport.revenue.promissory', 2000)
                ->where('trainingReport.revenue.promissory_count', 1)
                ->where('trainingReport.revenue.discounted_count', 1)
                ->where('trainingReport.revenue.pending_count', 1)
                ->has('trainingReport.revenue.discounted', 1)
                ->where('trainingReport.revenue.discounted.0.participant', 'ALPHA DISCOUNTED')
            );
    }

    /*
     * ── The reports have to add up ────────────────────────────────────────
     *
     * Every money surface reads the same rule from RevenueService: a
     * promissory note is verified, but no money arrived, so it is held apart
     * from what was assessed and what was collected. These pin the places
     * that used to restate the rule for themselves and get it wrong.
     */

    public function test_a_discounted_promissory_note_is_not_counted_as_a_discount_given(): void
    {
        $training = Training::factory()->paid(1500)->create();

        // The discount is only real once the money settles: promising to pay
        // 1200 of a 1500 fee has given nothing away yet.
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PROMISED'), [
            'amount' => 1200,
            'discount_amount' => 300,
            'prime_hrm_discount' => true,
            'payment_method' => PaymentMethod::Promissory,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.revenue.gross', 0)
                ->where('trainingReport.revenue.discount', 0)
                // The count and the table have to describe the same set as the
                // money above them, or the report cannot be reconciled.
                ->where('trainingReport.revenue.discounted_count', 0)
                ->has('trainingReport.revenue.discounted', 0)
                ->where('trainingReport.revenue.promissory', 1200)
                ->etc()
            );
    }

    public function test_the_monthly_trend_rows_sum_to_the_headline_totals(): void
    {
        $training = Training::factory()->paid(1500)->create([
            'starts_at' => CarbonImmutable::create(2026, 2, 10, 9),
            'ends_at' => CarbonImmutable::create(2026, 2, 10, 17),
        ]);

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA CASH'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PROMISSORY'), [
            'amount' => 2000,
            'payment_method' => PaymentMethod::Promissory,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=period&period=annual&year=2026')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $rows = collect($page->toArray()['props']['periodReport']['byPeriod']);

                $february = $rows->firstWhere('label', 'Feb 2026');

                // Named for the month it is, not the month today's date pushes
                // it into: 'Y-m' with no day overflows a short month on a 31st.
                $this->assertNotNull($february, 'February row is missing or mislabelled.');

                // The promissory 2000 belongs in its own column, exactly as the
                // headline reports it — not folded into gross. Cast because a
                // whole number of pesos comes back through JSON as an int.
                $this->assertSame(1500.0, (float) $february['gross']);
                $this->assertSame(1500.0, (float) $february['collected']);
                $this->assertSame(2000.0, (float) $february['promissory']);
            });
    }

    public function test_a_training_report_without_a_selection_asks_for_one(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'training')
                ->where('trainingReport', null)
            );
    }

    public function test_the_breakdown_report_cuts_by_the_requested_dimensions(): void
    {
        $training = Training::factory()->create();

        $participant = $this->participantIn($this->officeA, 'ALPHA CUT');
        $participant->profile->update([
            'sector' => 'Local Government Unit',
            'sex' => 'Female',
            'is_pwd' => true,
            'position_level' => '2nd Level (Rank and File)',
            'employment_status' => 'Permanent',
            'date_of_birth' => now()->subYears(30),
        ]);

        Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => $training->getKey(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.breakdown.total', 1)
                ->where('trainingReport.breakdown.sector.0.label', 'Local Government Unit')
                ->where('trainingReport.breakdown.sex.0.label', 'Female')
                ->where('trainingReport.breakdown.pwd.0.label', 'Yes')
                ->where('trainingReport.breakdown.positionLevel.0.label', '2nd Level (Rank and File)')
                ->where('trainingReport.breakdown.employmentStatus.0.label', 'Permanent')
                // Age bands keep their natural order, empty or not, so find the
                // populated band rather than assuming its index.
                ->where('trainingReport.breakdown.ageBand', fn ($rows) => collect($rows)
                    ->firstWhere('label', '26-35')['count'] === 1)
            );
    }

    // --- Report by period --------------------------------------------------

    public function test_the_monthly_report_covers_trainings_conducted_in_that_month(): void
    {
        $month = CarbonImmutable::now()->startOfMonth();

        $inMonth = Training::factory()->paid(1500)->create(['starts_at' => $month->addDays(10)]);
        Training::factory()->paid(1500)->create(['starts_at' => $month->subMonths(2)->addDays(10)]);

        $this->paymentFor($this->registrationIn($this->officeA, $inMonth, 'ALPHA PERIOD'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=period&period=monthly&year='.$month->year.'&month='.$month->month)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodReport.label', $month->format('F Y'))
                ->where('periodReport.conducted', 1)
                ->where('periodReport.participants', 1)
                ->where('periodReport.revenue.collected', 1500)
            );
    }

    public function test_period_reports_carry_the_period_labels(): void
    {
        $quarter = (int) ceil(now()->month / 3);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=period&period=monthly&year='.now()->year.'&month='.now()->month)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodReport.label', now()->format('F Y'))
            );

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=period&period=quarterly&year='.now()->year.'&quarter='.$quarter)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodReport.label', 'Q'.$quarter.' '.now()->year)
            );

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=period&period=annual&year='.now()->year)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodReport.label', (string) now()->year)
            );
    }

    // --- Money gating and scoping -----------------------------------------

    public function test_revenue_is_hidden_from_staff_who_do_not_collect_payments(): void
    {
        $training = Training::factory()->paid(1500)->create();

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA PAID'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSeeMoney', false)
                // The breakdown is demographics — any staff may read it.
                ->where('trainingReport.revenue', null)
                ->where('trainingReport.breakdown.total', 1)
            );

        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/analytics?view=period&period=annual&year='.now()->year)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodReport.revenue', null)
                ->where('periodReport.byPeriod', null)
            );
    }

    public function test_the_reports_are_scoped_to_the_field_office(): void
    {
        $training = Training::factory()->paid(1500)->create();

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA MINE'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $this->paymentFor($this->registrationIn($this->officeB, $training, 'BRAVO THEIRS'), [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);

        $this->actingAs($this->collectorFor($this->officeA))
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.breakdown.total', 1)
                ->where('trainingReport.revenue.collected', 1500)
            );

        // An admin has no office — they see the whole region.
        $this->actingAs($this->staffFor(null, Role::Admin))
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.breakdown.total', 2)
                ->where('trainingReport.revenue.collected', 3000)
            );
    }

    // --- The report exports ------------------------------------------------

    public function test_the_revenue_report_export_is_scoped_and_gated(): void
    {
        $training = Training::factory()->paid(1500)->create();

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA MINE'), [
            'or_number' => 'OR-A-1',
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $this->paymentFor($this->registrationIn($this->officeB, $training, 'BRAVO THEIRS'), [
            'or_number' => 'OR-B-1',
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);

        // A field-office collector downloads only their own office's rows.
        $csv = $this->body(
            $this->actingAs($this->collectorFor($this->officeA))
                ->get('/admin/exports/reports/revenue?view=training&training_id='.$training->getKey())
                ->assertOk()
        );

        $this->assertStringContainsString('ALPHA MINE', $csv);
        $this->assertStringContainsString('OR-A-1', $csv);
        $this->assertStringNotContainsString('BRAVO THEIRS', $csv);
        $this->assertStringNotContainsString('OR-B-1', $csv);

        // Staff who cannot touch money are shut out of the download entirely.
        $this->actingAs($this->staffFor($this->officeA))
            ->get('/admin/exports/reports/revenue?view=training&training_id='.$training->getKey())
            ->assertForbidden();
    }

    public function test_the_revenue_export_holds_a_promissory_note_apart_from_cash(): void
    {
        $training = Training::factory()->paid(1500)->create();

        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA CASH'), [
            'or_number' => 'OR-CASH',
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash,
        ]);
        $this->paymentFor($this->registrationIn($this->officeA, $training, 'ALPHA OWES'), [
            'or_number' => 'OR-NOTE',
            'amount' => 2000,
            'payment_method' => PaymentMethod::Promissory,
        ]);

        $csv = $this->body(
            $this->actingAs($this->admin())
                ->get('/admin/exports/reports/revenue?view=training&training_id='.$training->getKey())
                ->assertOk()
        );

        // Two money columns, so summing either one reconciles against the
        // figure the analytics screen prints. Folded into a single "Amount
        // Paid" the note inflated the takings by its own value.
        $this->assertStringContainsString('Amount Collected', $csv);
        $this->assertStringContainsString('On Promissory Note', $csv);

        $rows = collect(explode("\n", $csv));

        $cash = $rows->first(fn (string $line) => str_contains($line, 'OR-CASH'));
        $note = $rows->first(fn (string $line) => str_contains($line, 'OR-NOTE'));

        // The row is still there — the office needs to see who owes — but the
        // amount sits under the column that says nothing was received.
        $this->assertStringContainsString('1500', $cash);
        $this->assertStringContainsString('2000', $note);
        $this->assertStringContainsString('Promissory', $note);
    }

    public function test_the_breakdown_report_export_is_scoped(): void
    {
        $training = Training::factory()->create();

        $this->registrationIn($this->officeA, $training, 'ALPHA MINE');
        $this->registrationIn($this->officeB, $training, 'BRAVO THEIRS');

        $csv = $this->body(
            $this->actingAs($this->staffFor($this->officeA))
                ->get('/admin/exports/reports/breakdown?view=training&training_id='.$training->getKey())
                ->assertOk()
        );

        $this->assertStringContainsString('ALPHA MINE', $csv);
        $this->assertStringNotContainsString('BRAVO THEIRS', $csv);
    }

    public function test_the_training_report_names_the_full_run_not_just_its_first_day(): void
    {
        // Three days inside one month. Reported as its start date alone this
        // is indistinguishable from a one-day run, which is what the header
        // used to show.
        $training = Training::factory()->create([
            'starts_at' => CarbonImmutable::create(2026, 3, 12, 9),
            'ends_at' => CarbonImmutable::create(2026, 3, 14, 17),
            'duration_days' => 3,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.training.dates', '12–14 March 2026')
                ->where('trainingReport.training.duration_days', 3)
            );
    }

    public function test_a_run_spanning_two_months_names_both(): void
    {
        $training = Training::factory()->create([
            'starts_at' => CarbonImmutable::create(2026, 2, 28, 9),
            'ends_at' => CarbonImmutable::create(2026, 3, 2, 17),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.training.dates', '28 February – 02 March 2026')
            );
    }

    public function test_a_single_day_run_names_one_date(): void
    {
        $day = CarbonImmutable::create(2026, 3, 12, 9);

        $training = Training::factory()->create([
            'starts_at' => $day,
            'ends_at' => $day->setTime(17, 0),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training&training_id='.$training->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trainingReport.training.dates', '12 March 2026')
            );
    }

    public function test_the_training_picker_distinguishes_two_runs_in_one_month(): void
    {
        // The picker labelled both of these "… — Mar 2026", so selecting the
        // wrong run was a coin flip and the report gave no way to notice.
        Training::factory()->create([
            'title' => 'Foundations of Public Service',
            'starts_at' => CarbonImmutable::create(2026, 3, 3, 9),
            'ends_at' => CarbonImmutable::create(2026, 3, 4, 17),
        ]);
        Training::factory()->create([
            'title' => 'Foundations of Public Service',
            'starts_at' => CarbonImmutable::create(2026, 3, 24, 9),
            'ends_at' => CarbonImmutable::create(2026, 3, 25, 17),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/analytics?view=training')
            ->assertInertia(function (AssertableInertia $page) {
                $labels = collect($page->toArray()['props']['trainingOptions'])->pluck('label');

                $this->assertContains('Foundations of Public Service — 03–04 March 2026', $labels->all());
                $this->assertContains('Foundations of Public Service — 24–25 March 2026', $labels->all());
            });
    }
}
