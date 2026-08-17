<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Jobs\ReleaseCertificates;
use App\Models\Certificate;
use App\Models\CertificateVerification;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\CertificateReleased;
use App\Support\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Certificate issuance and the public verification endpoint that replaces v1's
 * `verify-certificate.php`.
 */
class CertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(CertificateService::DISK);
    }

    private function staff(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function completedRegistration(): Registration
    {
        return Registration::factory()->completed()->create([
            'user_id' => $this->participant()->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);
    }

    /** A completed registration on a paid training, settled however you like. */
    private function paidRegistration(PaymentMethod $method, PaymentStatus $status = PaymentStatus::Verified): Registration
    {
        $participant = $this->participant();

        $registration = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create([
                'payment_required' => true,
                'payment_amount' => 1500,
            ])->getKey(),
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $registration->training_id,
            'payment_method' => $method,
            'status' => $status,
            'verified_at' => $status === PaymentStatus::Verified ? now() : null,
        ]);

        return $registration->refresh();
    }

    // --- The fee must have actually arrived ------------------------------

    /**
     * The certificate is the office's last leverage over an unpaid fee.
     *
     * A promissory note is enough to attend — see MeetingLinkTest — but not
     * enough to walk away with the document that proves it.
     */
    public function test_a_certificate_is_withheld_while_the_fee_rests_on_a_promissory_note(): void
    {
        $registration = $this->paidRegistration(PaymentMethod::Promissory);

        $this->expectException(ValidationException::class);

        CertificateService::release($registration, $this->staff());
    }

    public function test_settling_the_note_in_cash_releases_the_certificate(): void
    {
        $registration = $this->paidRegistration(PaymentMethod::Promissory);

        // The participant comes back and pays; the note is not deleted, it is
        // simply no longer the only thing on file.
        Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $registration->training_id,
            'payment_method' => PaymentMethod::Cash,
        ]);

        $certificate = CertificateService::release($registration->refresh(), $this->staff());

        $this->assertNotNull($certificate->generated_at);
    }

    public function test_a_verified_payment_releases_the_certificate(): void
    {
        $registration = $this->paidRegistration(PaymentMethod::Online);

        $certificate = CertificateService::release($registration, $this->staff());

        $this->assertNotNull($certificate->generated_at);
    }

    public function test_a_payment_still_awaiting_verification_withholds_the_certificate(): void
    {
        $registration = $this->paidRegistration(PaymentMethod::Online, PaymentStatus::Pending);

        $this->expectException(ValidationException::class);

        CertificateService::release($registration, $this->staff());
    }

    /**
     * The bulk run skips the unpaid rather than failing on them.
     *
     * An outstanding fee is an ordinary state, not an error, and the count in
     * the flash message has to reflect what will actually be issued.
     */
    public function test_a_bulk_release_issues_to_the_paid_and_holds_the_rest(): void
    {
        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        foreach ([PaymentMethod::Cash, PaymentMethod::Promissory] as $method) {
            $participant = $this->participant();

            $registration = Registration::factory()->completed()->create([
                'user_id' => $participant->getKey(),
                'training_id' => $training->getKey(),
            ]);

            Payment::factory()->verified()->create([
                'registration_id' => $registration->getKey(),
                'user_id' => $participant->getKey(),
                'training_id' => $training->getKey(),
                'payment_method' => $method,
            ]);
        }

        $this->actingAs($this->staff())
            ->from("/admin/trainings/{$training->id}/roster")
            ->post("/admin/trainings/{$training->id}/certificates")
            ->assertSessionHas('success');

        (new ReleaseCertificates($training, $this->staff()))->handle();

        // Exactly the one who paid in cash.
        $this->assertSame(1, Certificate::whereNotNull('generated_at')->count());
    }

    public function test_releasing_a_certificate_writes_a_pdf_and_a_record(): void
    {
        $registration = $this->completedRegistration();

        $certificate = CertificateService::release($registration, $this->staff());

        $this->assertNotNull($certificate->generated_at);
        $this->assertStringStartsWith('CSC8-', $certificate->certificate_number);
        $this->assertSame(32, strlen($certificate->verification_code));

        Storage::disk(CertificateService::DISK)->assertExists($certificate->file_path);

        // A real PDF, not an empty placeholder.
        $this->assertStringStartsWith(
            '%PDF',
            Storage::disk(CertificateService::DISK)->get($certificate->file_path)
        );
    }

    public function test_an_incomplete_registration_cannot_be_issued_a_certificate(): void
    {
        $registration = Registration::factory()->approved()->create([
            'user_id' => $this->participant()->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only a completed registration');

        CertificateService::release($registration, $this->staff());
    }

    public function test_releasing_twice_does_not_mint_a_second_certificate(): void
    {
        $registration = $this->completedRegistration();
        $staff = $this->staff();

        $first = CertificateService::release($registration, $staff);
        $second = CertificateService::release($registration->fresh(), $staff);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->certificate_number, $second->certificate_number);
        $this->assertSame(1, Certificate::count());
    }

    public function test_the_participant_is_notified_when_their_certificate_is_released(): void
    {
        Notification::fake();

        $registration = $this->completedRegistration();

        CertificateService::release($registration, $this->staff());

        Notification::assertSentTo($registration->user, CertificateReleased::class);
    }

    public function test_a_participant_can_download_their_own_certificate(): void
    {
        $registration = $this->completedRegistration();
        $certificate = CertificateService::release($registration, $this->staff());

        $this->actingAs($registration->user)
            ->get("/my/certificates/{$certificate->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition', "attachment; filename={$certificate->certificate_number}.pdf");

        $this->assertSame(1, $certificate->fresh()->download_count);
    }

    public function test_a_missing_pdf_is_a_not_found_rather_than_a_server_error(): void
    {
        $registration = $this->completedRegistration();
        $certificate = CertificateService::release($registration, $this->staff());

        // The record outliving its file is a normal state: seeded certificates
        // carry a path with no PDF, and storage can be purged.
        Storage::disk(CertificateService::DISK)->delete($certificate->file_path);

        $this->actingAs($registration->user)
            ->get("/my/certificates/{$certificate->id}/download")
            ->assertNotFound();

        // A failed download must not be counted as one.
        $this->assertSame(0, $certificate->fresh()->download_count);
    }

    public function test_a_participant_cannot_download_someone_elses_certificate(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        $this->actingAs($this->participant())
            ->get("/my/certificates/{$certificate->id}/download")
            ->assertForbidden();
    }

    public function test_the_certificates_page_separates_released_from_awaiting(): void
    {
        $participant = $this->participant();

        $released = Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);
        Registration::factory()->completed()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);

        CertificateService::release($released, $this->staff());

        $this->actingAs($participant)
            ->get('/my/certificates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('My/Certificates')
                ->has('released', 1)
                ->has('awaitingRelease', 1)
            );
    }

    public function test_the_register_export_keeps_the_not_yet_emailed_filter(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin/certificates?emailed=0')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                // "0" is falsy, so a bare array_filter dropped it and the
                // export quietly widened to every certificate in scope.
                $this->assertStringContainsString(
                    'emailed=0',
                    $page->toArray()['props']['exportUrl'],
                );
            });
    }

    public function test_the_register_export_still_drops_filters_left_blank(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertStringNotContainsString(
                    'emailed=',
                    $page->toArray()['props']['exportUrl'],
                );
            });
    }

    public function test_the_public_verification_page_works_without_signing_in(): void
    {
        $registration = $this->completedRegistration();
        $certificate = CertificateService::release($registration, $this->staff());

        $this->get("/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Certificates/Verify')
                ->where('certificate.number', $certificate->certificate_number)
                ->where('certificate.participant', $registration->user->name)
            );
    }

    public function test_verification_never_discloses_contact_details(): void
    {
        $registration = $this->completedRegistration();
        $certificate = CertificateService::release($registration, $this->staff());

        // The employer checking a certificate has no business seeing the
        // participant's email or which office they belong to.
        $this->get("/verify/{$certificate->verification_code}")
            ->assertOk()
            ->assertDontSee($registration->user->email);
    }

    public function test_each_verification_is_logged(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        $this->get("/verify/{$certificate->verification_code}")->assertOk();
        $this->get("/verify/{$certificate->verification_code}")->assertOk();

        $this->assertSame(2, CertificateVerification::count());
        $this->assertSame(2, $certificate->fresh()->verification_count);
        $this->assertNotNull($certificate->fresh()->last_verified_at);
    }

    public function test_an_unknown_verification_code_is_not_found(): void
    {
        $this->get('/verify/'.str_repeat('x', 32))->assertNotFound();
    }

    public function test_an_unreleased_certificate_cannot_be_verified(): void
    {
        // A row exists but no PDF was ever generated — it must not verify.
        $certificate = Certificate::factory()->create();

        $this->get("/verify/{$certificate->verification_code}")->assertNotFound();
    }

    public function test_the_verification_code_is_not_the_certificate_number(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        // Guessing the printed sequential number must not resolve anything —
        // otherwise every certificate CSC issued would be enumerable.
        $this->get("/verify/{$certificate->certificate_number}")->assertNotFound();
    }

    public function test_hrd_can_issue_a_certificate_from_the_roster(): void
    {
        $registration = $this->completedRegistration();

        $this->actingAs($this->staff())
            ->post("/admin/registrations/{$registration->id}/certificate")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Certificate::count());
    }

    public function test_hrd_can_issue_certificates_for_a_whole_training(): void
    {
        $training = Training::factory()->create();

        foreach (range(1, 3) as $ignored) {
            Registration::factory()->completed()->create([
                'user_id' => $this->participant()->getKey(),
                'training_id' => $training->getKey(),
            ]);
        }

        $this->actingAs($this->staff())
            ->post("/admin/trainings/{$training->id}/certificates")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(3, Certificate::whereNotNull('generated_at')->count());
    }

    public function test_bulk_release_reports_when_there_is_nothing_to_issue(): void
    {
        $training = Training::factory()->create();

        $this->actingAs($this->staff())
            ->from('/admin/trainings')
            ->post("/admin/trainings/{$training->id}/certificates")
            ->assertSessionHasErrors('certificate');
    }

    public function test_field_office_staff_cannot_issue_certificates(): void
    {
        $registration = $this->completedRegistration();

        $this->actingAs($this->staff(Role::FieldOffice))
            ->post("/admin/registrations/{$registration->id}/certificate")
            ->assertForbidden();

        $this->assertSame(0, Certificate::count());
    }

    public function test_certificate_numbers_are_sequential_within_a_year(): void
    {
        $training = Training::factory()->create(['starts_at' => now()->addWeek()]);
        $staff = $this->staff();
        $numbers = [];

        foreach (range(1, 3) as $ignored) {
            $registration = Registration::factory()->completed()->create([
                'user_id' => $this->participant()->getKey(),
                'training_id' => $training->getKey(),
            ]);

            $numbers[] = CertificateService::release($registration, $staff)->certificate_number;
        }

        $year = now()->addWeek()->format('Y');

        $this->assertSame([
            "CSC8-{$year}-000001",
            "CSC8-{$year}-000002",
            "CSC8-{$year}-000003",
        ], $numbers);
    }

    public function test_completed_registrations_without_certificates_still_render(): void
    {
        $participant = $this->participant();

        Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
            'status' => RegistrationStatus::Completed,
        ]);

        $this->actingAs($participant)->get('/my/certificates')->assertOk();
    }

    // --- The certificate register (v1's certificates.php) -----------------

    public function test_the_register_lists_issued_certificates_and_counts_them(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        // A half-finished release — a row with no PDF behind it — is not a
        // certificate anyone can be shown.
        Certificate::factory()->create(['generated_at' => null, 'file_path' => null]);

        $this->actingAs($this->staff())
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Certificates/Index')
                ->has('certificates.data', 1)
                ->where('certificates.data.0.number', $certificate->certificate_number)
                ->where('stats.total', 1)
                ->where('can.resend', true)
            );
    }

    public function test_the_register_finds_a_certificate_by_its_number(): void
    {
        $wanted = CertificateService::release($this->completedRegistration(), $this->staff());
        CertificateService::release($this->completedRegistration(), $this->staff());

        // The commonest real question: someone rings up quoting a number.
        $this->actingAs($this->staff())
            ->get('/admin/certificates?search='.$wanted->certificate_number)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('certificates.data', 1)
                ->where('certificates.data.0.number', $wanted->certificate_number)
            );
    }

    public function test_the_register_filters_by_issue_year(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());
        $certificate->forceFill(['generated_at' => now()->subYear()])->save();

        $this->actingAs($this->staff())
            ->get('/admin/certificates?year='.now()->subYear()->year)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('certificates.data', 1)
                ->where('certificates.data.0.number', $certificate->certificate_number)
                ->where('years.0.value', (string) now()->subYear()->year)
                ->where('exportUrl', route('admin.exports.certificates', ['year' => now()->subYear()->year]))
            );
    }

    public function test_staff_can_download_an_issued_certificate(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        $this->actingAs($this->staff())
            ->get("/admin/certificates/{$certificate->id}/download")
            ->assertOk()
            ->assertDownload("{$certificate->certificate_number}.pdf");
    }

    public function test_staff_can_re_send_the_certificate_email(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        Notification::fake();

        $this->actingAs($this->staff())
            ->post("/admin/certificates/{$certificate->id}/resend")
            ->assertRedirect()
            ->assertSessionHas('success');

        Notification::assertSentTo($certificate->user, CertificateReleased::class);

        // Nothing is re-issued: the number and the stored file are untouched,
        // so the document already in circulation stays valid.
        $this->assertSame(
            $certificate->file_path,
            $certificate->fresh()->file_path
        );
        $this->assertNotNull($certificate->fresh()->email_sent_at);
    }

    public function test_the_detail_page_shows_who_has_verified_a_certificate(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        // Two public lookups, recorded the way the verification endpoint does.
        CertificateService::recordVerification($certificate, '203.0.113.7', 'Mozilla/5.0 Employer');
        CertificateService::recordVerification($certificate, '198.51.100.4', 'Mozilla/5.0 Other');

        $this->actingAs($this->staff())
            ->get("/admin/certificates/{$certificate->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Certificates/Show')
                ->where('certificate.number', $certificate->certificate_number)
                ->where('certificate.verifications', 2)
                ->has('verifications', 2)
                // Newest first: the question is who has been asking lately.
                ->where('verifications.0.ip_address', '198.51.100.4')
                // The QR is rendered server-side, so the page needs no request
                // to an outside service carrying a certificate code.
                ->where('certificate.qr', fn ($qr) => str_starts_with($qr, 'data:image/'))
            );
    }

    public function test_a_certificate_nobody_has_checked_says_so(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        $this->actingAs($this->staff())
            ->get("/admin/certificates/{$certificate->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('verifications', 0)
                ->where('certificate.verifications', 0)
                ->where('certificate.last_verified_at', null)
            );
    }

    public function test_management_may_read_the_register_but_not_re_send(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());
        $staff = $this->staff(Role::Management);

        $this->actingAs($staff)
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.resend', false));

        $this->actingAs($staff)
            ->post("/admin/certificates/{$certificate->id}/resend")
            ->assertForbidden();
    }

    public function test_the_register_is_field_office_scoped(): void
    {
        $certificate = CertificateService::release($this->completedRegistration(), $this->staff());

        // Fails closed: a field-office account with no office matches nothing.
        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => null,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get('/admin/certificates')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('certificates.data', 0));

        $this->actingAs($staff)
            ->get("/admin/certificates/{$certificate->id}/download")
            ->assertNotFound();

        $this->actingAs($staff)
            ->get("/admin/certificates/{$certificate->id}")
            ->assertNotFound();
    }
}
