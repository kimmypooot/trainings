<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\TrainingStatus;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Models\Training;
use App\Models\TrainingRequest;
use App\Models\User;
use Database\Seeders\SampleActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleActivitySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder pulls SampleUsersSeeder in itself when there is nobody to
        // register, which is what an empty test database looks like.
        $this->seed(SampleActivitySeeder::class);
    }

    public function test_it_seeds_trainings_across_the_whole_lifecycle(): void
    {
        foreach ([TrainingStatus::Draft, TrainingStatus::Published, TrainingStatus::Completed, TrainingStatus::Cancelled] as $status) {
            $this->assertGreaterThan(
                0,
                Training::where('status', $status)->count(),
                "No {$status->label()} training was seeded."
            );
        }

        // A dataset of nothing but future trainings leaves the roster, the
        // scanner and the certificate screens all looking empty.
        $this->assertGreaterThan(0, Training::where('starts_at', '<', now())->count());
        $this->assertGreaterThan(0, Training::where('starts_at', '>', now())->count());
    }

    public function test_a_training_is_running_today_so_the_scanner_has_work(): void
    {
        $running = Training::where('status', TrainingStatus::Published)
            ->get()
            ->filter(fn (Training $training) => $training->isRunningToday());

        $this->assertNotEmpty($running, 'No training is running today.');
    }

    public function test_draft_trainings_have_no_registrations(): void
    {
        $drafts = Training::where('status', TrainingStatus::Draft)->pluck('id');

        $this->assertSame(
            0,
            Registration::whereIn('training_id', $drafts)->count(),
            'A draft has not been announced, so nobody could have registered.'
        );
    }

    public function test_nobody_is_registered_twice_for_the_same_training(): void
    {
        $duplicates = Registration::selectRaw('user_id, training_id, count(*) as total')
            ->groupBy('user_id', 'training_id')
            ->havingRaw('count(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);
    }

    public function test_no_training_is_overbooked(): void
    {
        foreach (Training::whereNotNull('capacity')->get() as $training) {
            $this->assertLessThanOrEqual(
                $training->capacity,
                $training->activeRegistrations()->count(),
                "“{$training->title}” is over capacity."
            );
        }
    }

    public function test_attendance_only_falls_on_days_a_training_actually_runs(): void
    {
        $attendances = Attendance::with('registration.training')->get();

        $this->assertNotEmpty($attendances);

        foreach ($attendances as $attendance) {
            $training = $attendance->registration->training;

            $this->assertNotNull(
                $training->dayNumberFor($attendance->attendance_date),
                "Attendance on {$attendance->attendance_date} falls outside “{$training->title}”."
            );
            $this->assertLessThanOrEqual($training->duration_days, $attendance->training_day);
            // Nothing is recorded for a day that has not happened yet.
            $this->assertTrue($attendance->attendance_date->startOfDay()->lessThanOrEqualTo(now()));
        }
    }

    public function test_attended_at_agrees_with_the_attendance_rows(): void
    {
        $registrations = Registration::with('attendances')->whereNotNull('attended_at')->get();

        $this->assertNotEmpty($registrations);

        foreach ($registrations as $registration) {
            $this->assertGreaterThan(
                0,
                $registration->creditedDays(),
                'attended_at is set on a registration with no credited attendance.'
            );
        }
    }

    public function test_certificates_exist_as_data_without_a_rendered_pdf(): void
    {
        $certificates = Certificate::all();

        $this->assertNotEmpty($certificates);

        foreach ($certificates as $certificate) {
            $this->assertNotNull($certificate->generated_at);
            $this->assertSame(32, strlen($certificate->verification_code));
            $this->assertStringStartsWith('CSC8-', $certificate->certificate_number);
            // Data only: a path is recorded but no file was ever written.
            $this->assertFalse(file_exists(storage_path('app/'.$certificate->file_path)));
        }
    }

    public function test_certificates_only_belong_to_completed_registrations(): void
    {
        $statuses = Certificate::with('registration')
            ->get()
            ->map(fn (Certificate $certificate) => $certificate->registration->status)
            ->unique();

        $this->assertEquals([RegistrationStatus::Completed], $statuses->values()->all());
    }

    public function test_a_seeded_certificate_verifies_publicly(): void
    {
        $certificate = Certificate::with('user')->firstOrFail();

        $this->get("/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('certificate.number', $certificate->certificate_number)
                ->where('certificate.participant', $certificate->user->name)
            );
    }

    public function test_payments_only_attach_to_paid_trainings(): void
    {
        $payments = Payment::with('training')->get();

        $this->assertNotEmpty($payments);

        foreach ($payments as $payment) {
            $this->assertTrue(
                $payment->training->payment_required,
                "“{$payment->training->title}” is free but carries a payment."
            );
            $this->assertSame(
                $payment->training->payment_amount,
                $payment->amount,
                'The amount paid should match what the training charges.'
            );
        }
    }

    public function test_the_payment_queue_has_something_pending_to_verify(): void
    {
        $this->assertGreaterThan(0, Payment::where('status', PaymentStatus::Pending)->count());
        $this->assertGreaterThan(0, Payment::where('status', PaymentStatus::Verified)->count());
    }

    public function test_a_verified_payment_records_who_verified_it(): void
    {
        foreach (Payment::where('status', '!=', PaymentStatus::Pending)->get() as $payment) {
            $this->assertNotNull($payment->verified_by);
            $this->assertNotNull($payment->verified_at);
        }
    }

    public function test_refunds_only_claim_against_verified_payments(): void
    {
        foreach (RefundRequest::with('payment')->get() as $refund) {
            $this->assertSame(PaymentStatus::Verified, $refund->payment->status);
            $this->assertLessThanOrEqual((float) $refund->payment->amount, (float) $refund->amount);
        }
    }

    public function test_training_requests_cover_every_review_state(): void
    {
        $this->assertGreaterThan(0, TrainingRequest::where('status', 'pending')->count());
        $this->assertGreaterThan(0, TrainingRequest::where('status', 'approved')->count());
        $this->assertGreaterThan(0, TrainingRequest::where('status', 'rejected')->count());
    }

    public function test_the_admin_screens_render_against_the_seeded_data(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $training = Training::where('status', TrainingStatus::Completed)->firstOrFail();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/trainings')->assertOk();
        $this->actingAs($admin)->get("/admin/trainings/{$training->id}/roster")->assertOk();
        $this->actingAs($admin)->get('/admin/analytics')->assertOk();
        $this->actingAs($admin)->get('/admin/payments')->assertOk();
        $this->actingAs($admin)->get('/admin/requests')->assertOk();
    }

    public function test_a_participant_with_a_certificate_sees_it_as_released(): void
    {
        $certificate = Certificate::with('user')->firstOrFail();

        $response = $this->actingAs($certificate->user)
            ->get('/my/certificates')
            ->assertOk();

        // A participant can hold several — the seeder spreads completions
        // across trainings — so assert this one is among them rather than
        // pinning a count that shifts with the seed.
        $numbers = collect($response->viewData('page')['props']['released'])->pluck('number');

        $this->assertContains($certificate->certificate_number, $numbers->all());
    }

    public function test_running_twice_tops_up_rather_than_duplicating(): void
    {
        $trainings = Training::count();

        $this->seed(SampleActivitySeeder::class);

        // Trainings are matched on their slug, so the catalogue is stable.
        $this->assertSame($trainings, Training::count());

        // Registrations may grow — each pass registers a random slice of the
        // pool and skips pairs that already exist — but nobody is registered
        // twice, and nobody ends up with two certificates.
        $this->assertCount(
            0,
            Registration::selectRaw('user_id, training_id, count(*) as total')
                ->groupBy('user_id', 'training_id')
                ->havingRaw('count(*) > 1')
                ->get()
        );

        $this->assertCount(
            0,
            Certificate::selectRaw('registration_id, count(*) as total')
                ->groupBy('registration_id')
                ->havingRaw('count(*) > 1')
                ->get()
        );

        $this->assertCount(
            0,
            Payment::selectRaw('registration_id, count(*) as total')
                ->groupBy('registration_id')
                ->havingRaw('count(*) > 1')
                ->get()
        );
    }
}
