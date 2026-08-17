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
}
