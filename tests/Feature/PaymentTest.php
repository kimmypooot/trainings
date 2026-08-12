<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\PaymentReviewed;
use App\Notifications\RefundReviewed;
use App\Support\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Payments and refunds, ported from v1's `upload-payment.php`,
 * `pending-payments.php`, `request-refund.php` and `refund-mgmt.php`.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function officer(Role $role = Role::CollectingOfficer): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    private function participant(): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create();

        return $user->refresh();
    }

    private function paidRegistration(User $participant): Registration
    {
        return Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create([
                'payment_required' => true,
                'payment_amount' => 1500,
            ])->getKey(),
        ]);
    }

    // --- Recording a payment ---------------------------------------------

    public function test_a_participant_can_upload_proof_of_payment(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Online->value,
                'reference_number' => 'REF123456789',
                'payment_date' => now()->subDay()->toDateString(),
                'proof' => UploadedFile::fake()->create('deposit-slip.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = Payment::sole();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame('1500.00', $payment->amount);
        Storage::disk('local')->assertExists($payment->proof_path);
    }

    public function test_a_non_cash_payment_must_carry_a_reference_number(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->from('/my/payments')
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Online->value,
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('reference_number');

        // Cash is paid over the counter against a receipt, so it is exempt.
        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHas('success');
    }

    public function test_a_future_dated_payment_is_refused(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->from('/my/payments')
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('payment_date');
    }

    public function test_payment_cannot_be_recorded_against_a_free_training(): void
    {
        $participant = $this->participant();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create()->getKey(),
        ]);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertNotFound();
    }

    public function test_a_participant_cannot_pay_against_someone_elses_registration(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $this->actingAs($this->participant())
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    // --- Verification -----------------------------------------------------

    public function test_a_collecting_officer_can_verify_a_payment(): void
    {
        $payment = Payment::factory()->create();

        $this->actingAs($this->officer())
            ->post("/admin/payments/{$payment->id}/review", ['decision' => 'verified'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PaymentStatus::Verified, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->verified_at);
    }

    public function test_rejecting_a_payment_requires_a_reason(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Give a reason when rejecting');

        PaymentService::reject($payment, $this->officer(), '');
    }

    public function test_a_payment_cannot_be_reviewed_twice(): void
    {
        $payment = Payment::factory()->create();
        $officer = $this->officer();

        PaymentService::verify($payment, $officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been');

        PaymentService::verify($payment->fresh(), $officer);
    }

    public function test_the_participant_is_notified_of_the_payment_decision(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $payment = Payment::factory()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);

        PaymentService::verify($payment, $this->officer());

        Notification::assertSentTo($participant, PaymentReviewed::class);
    }

    public function test_field_office_staff_cannot_reach_the_payment_queue(): void
    {
        $this->actingAs($this->officer(Role::FieldOffice))
            ->get('/admin/payments')
            ->assertForbidden();
    }

    public function test_a_collecting_officer_cannot_reach_the_participant_directory(): void
    {
        // The cashier is staff, but has no business in participant records.
        $this->actingAs($this->officer())
            ->get('/admin/field-offices')
            ->assertForbidden();
    }

    public function test_proof_of_payment_is_not_publicly_readable(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)->post("/my/registrations/{$registration->id}/payments", [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_date' => now()->toDateString(),
            'proof' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'),
        ]);

        $payment = Payment::sole();

        $this->actingAs($participant)->get("/payments/{$payment->id}/proof")->assertOk();
        $this->actingAs($this->officer())->get("/payments/{$payment->id}/proof")->assertOk();
        $this->actingAs($this->participant())->get("/payments/{$payment->id}/proof")->assertForbidden();
    }

    // --- Refunds ----------------------------------------------------------

    public function test_only_a_verified_payment_can_be_refunded(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only a verified payment');

        PaymentService::requestRefund($payment, 'The training was cancelled.');
    }

    public function test_a_participant_can_claim_a_refund_on_a_verified_payment(): void
    {
        $participant = $this->participant();
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/refund", [
                'reason' => 'The training was cancelled by CSC.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $refund = RefundRequest::sole();

        $this->assertSame('1500.00', $refund->amount);
        $this->assertSame(RequestStatus::Pending, $refund->status);
    }

    public function test_a_refund_cannot_exceed_the_amount_paid(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot exceed the amount paid');

        PaymentService::requestRefund($payment, 'A reason for the claim.', 5000);
    }

    public function test_a_second_open_refund_is_refused(): void
    {
        $payment = Payment::factory()->verified()->create();

        PaymentService::requestRefund($payment, 'First claim reason.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already awaiting review');

        PaymentService::requestRefund($payment->fresh(), 'Second claim reason.');
    }

    public function test_an_already_refunded_payment_cannot_be_claimed_again(): void
    {
        $payment = Payment::factory()->verified()->create();
        $officer = $this->officer();

        $refund = PaymentService::requestRefund($payment, 'The training was cancelled.');
        PaymentService::reviewRefund($refund, RequestStatus::Approved, $officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been refunded');

        PaymentService::requestRefund($payment->fresh(), 'Trying again.');
    }

    public function test_approving_a_refund_stamps_when_the_money_went_back(): void
    {
        $payment = Payment::factory()->verified()->create();
        $refund = PaymentService::requestRefund($payment, 'The training was cancelled.');

        $this->actingAs($this->officer())
            ->post("/admin/refunds/{$refund->id}/review", ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(RequestStatus::Approved, $refund->fresh()->status);
        $this->assertNotNull($refund->fresh()->refunded_at);
    }

    public function test_declining_a_refund_requires_a_reason(): void
    {
        $payment = Payment::factory()->verified()->create();
        $refund = PaymentService::requestRefund($payment, 'A claim reason.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Give a reason when declining');

        PaymentService::reviewRefund($refund, RequestStatus::Rejected, $this->officer());
    }

    public function test_declining_a_refund_leaves_no_refunded_timestamp(): void
    {
        $payment = Payment::factory()->verified()->create();
        $refund = PaymentService::requestRefund($payment, 'A claim reason.');

        PaymentService::reviewRefund($refund, RequestStatus::Rejected, $this->officer(), 'Outside the refund window.');

        $this->assertNull($refund->fresh()->refunded_at);
    }

    public function test_the_participant_is_notified_of_the_refund_decision(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);
        $refund = PaymentService::requestRefund($payment, 'The training was cancelled.');

        PaymentService::reviewRefund($refund, RequestStatus::Approved, $this->officer());

        Notification::assertSentTo($participant, RefundReviewed::class);
    }

    // --- Screens ----------------------------------------------------------

    public function test_the_participant_page_lists_payments_and_what_is_still_owed(): void
    {
        $participant = $this->participant();
        $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->get('/my/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('My/Payments')
                ->has('awaitingPayment', 1)
                ->has('payments', 0)
            );
    }

    public function test_a_superadmin_can_appoint_a_collecting_officer(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::SuperAdmin,
            'profile_completed_at' => now(),
        ]))
            ->post('/admin/users', [
                'name' => 'Cashier One',
                'email' => 'cashier@csc.gov.ph',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'role' => Role::CollectingOfficer->value,
            ])
            ->assertRedirect('/admin/users');

        $this->assertSame(
            Role::CollectingOfficer,
            User::where('email', 'cashier@csc.gov.ph')->sole()->role
        );
    }

    public function test_the_officer_queue_shows_pending_payments_by_default(): void
    {
        Payment::factory()->create();
        Payment::factory()->verified()->create();

        $this->actingAs($this->officer())
            ->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Payments/Index')
                ->has('payments.data', 1)
            );
    }
}
