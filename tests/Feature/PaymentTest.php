<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Models\FieldOffice;
use App\Models\Payment;
use App\Models\Profile;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Notifications\PaymentReviewed;
use App\Notifications\RefundReviewed;
use App\Notifications\RegistrationReviewed;
use App\Support\PaymentService;
use App\Support\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
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

    /**
     * A staff member who may handle money.
     *
     * Collecting is a designation rather than a role, so the default here is a
     * field-office account carrying it. This file's default officer is
     * deliberately *not* that combination, though: every money screen is
     * scoped to the officer's own office, so a field-office actor would make
     * each workflow assertion here depend on which office a factory happened to
     * put a participant in — and the assertion that failed would never be the
     * one the test was written to make. The scoped case is exercised where it
     * belongs, in FieldOfficeScopingTest. Admins and superadmins reach the
     * money screens by role; a collecting officer reaches them by designation,
     * which is what the default here exercises.
     */
    private function officer(Role $role = Role::CollectingOfficer): User
    {
        return User::factory()->create([
            'role' => $role,
            'profile_completed_at' => now(),
            /*
             * Set only for the roles that need it. Admin and superadmin reach
             * the money screens through Role::financial(), and handing them the
             * designation as well would quietly change what they may *see*:
             * handlesPayments() unmasks refund account numbers, so a blanket
             * true here would defeat the masking test below.
             */
            'is_collecting_officer' => in_array($role, [Role::CollectingOfficer, Role::FieldOffice], true),
        ]);
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

    /**
     * A promissory note is only on the table where the training offered one.
     *
     * Without this, the method is a way to claim a slot on a training the
     * office had decided must be paid up front.
     */
    public function test_a_promissory_note_is_refused_where_the_training_does_not_accept_one(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $registration->training->forceFill(['accepts_promissory' => false])->save();

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Promissory->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('payment_method');

        $registration->training->forceFill(['accepts_promissory' => true])->save();

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                // No reference number: a note is its own document.
                'payment_method' => PaymentMethod::Promissory->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentMethod::Promissory, Payment::sole()->payment_method);
    }

    /**
     * The reference number is no longer asked for, so it can no longer be
     * demanded — a rule requiring a field the form does not render would fail
     * every online payment with a message that has nowhere to appear.
     */
    public function test_a_non_cash_payment_no_longer_needs_a_reference_number(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        // Check rather than Online: both used to demand a reference, and this
        // is the one of the two that does not now demand proof instead, so the
        // assertion stays about the reference alone.
        $this->actingAs($participant)
            ->from('/my/payments')
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Check->value,
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Payment::sole()->reference_number);
    }

    /**
     * A missing slip is a gap for staff to chase, not a wall for the
     * participant.
     *
     * Refusing the submission put everybody who cannot scan — no printer, a
     * lost slip, a transfer somebody else made — through the counter, which is
     * a lot of load to add for a document staff can ask for. So it goes
     * through, and the queue is told.
     */
    public function test_an_online_transfer_without_proof_is_accepted(): void
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
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(Payment::sole()->proof_path);
    }

    public function test_the_verification_queue_flags_a_missing_slip(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)->post("/my/registrations/{$registration->id}/payments", [
            'amount' => 1500,
            'payment_method' => PaymentMethod::Online->value,
            'payment_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->officer())
            ->get('/admin/payments')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Payments/Index')
                ->where('payments.data.0.proof_missing', true)
            );
    }

    /**
     * Cash has a counter receipt and a promissory note is itself the document,
     * so neither is ever missing anything — flagging them would train staff to
     * ignore the flag.
     */
    public function test_a_method_that_expects_no_document_is_not_flagged(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->officer())
            ->get('/admin/payments')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('payments.data.0.proof_missing', false)
            );
    }

    /**
     * Card payments are no longer offered, but three of them are already in the
     * table. The case has to survive so those rows still cast — it is the
     * dropdown that stops listing it.
     */
    public function test_credit_card_is_retired_from_the_dropdown_but_still_reads(): void
    {
        $offered = array_column(PaymentMethod::options(), 'value');

        $this->assertNotContains(PaymentMethod::CreditCard->value, $offered);
        $this->assertContains(PaymentMethod::Lddap->value, $offered);

        // The stored value still resolves, which is the whole point of keeping it.
        $this->assertSame(PaymentMethod::CreditCard, PaymentMethod::from('credit_card'));
    }

    public function test_an_agency_can_settle_by_lddap(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Lddap->value,
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->assertSame(PaymentMethod::Lddap, Payment::sole()->payment_method);
    }

    /** Still stored when something does send one — staff entry, imports, history. */
    public function test_a_reference_number_is_kept_when_one_is_supplied(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Check->value,
                'reference_number' => 'REF123456789',
                'payment_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->assertSame('REF123456789', Payment::sole()->reference_number);
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

    public function test_the_payment_queue_turns_away_staff_without_the_designation(): void
    {
        $undesignated = User::factory()->create([
            'role' => Role::FieldOffice,
            'profile_completed_at' => now(),
            'is_collecting_officer' => false,
        ]);

        $this->actingAs($undesignated)->get('/admin/payments')->assertForbidden();
    }

    public function test_a_designated_field_office_officer_keeps_both_the_till_and_the_scoping(): void
    {
        // The combination v1 has and v2 could not express: the same person is
        // scoped to their own office *and* takes money for it. Modelling the
        // designation as a role forced a choice between the two.
        //
        // Named explicitly rather than leaning on the default, which is an
        // unscoped collector so that the workflow tests are not all quietly
        // office-dependent.
        $officer = $this->officer(Role::FieldOffice);

        $this->actingAs($officer)->get('/admin/payments')->assertOk();

        $this->assertTrue($officer->isScopedToFieldOffice());
        $this->assertTrue($officer->collectsPayments());
    }

    public function test_the_designation_alone_does_not_open_the_rest_of_the_admin_area(): void
    {
        // Collecting money is not a licence to edit reference data.
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

    // --- Official receipts -------------------------------------------------

    public function test_verifying_records_the_official_receipt_and_its_issuer(): void
    {
        $payment = Payment::factory()->create();
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post("/admin/payments/{$payment->id}/review", [
                'decision' => PaymentStatus::Verified->value,
                'or_number' => 'OR-2026-00417',
                'or_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = $payment->fresh();

        $this->assertSame('OR-2026-00417', $payment->or_number);
        $this->assertSame($officer->getKey(), $payment->collecting_officer_id);
        $this->assertNotNull($payment->or_date);
    }

    /**
     * The same OR number on two payments is a transcription slip or a
     * duplicate, and both are worth catching while the officer still has the
     * receipt in front of them.
     */
    public function test_an_official_receipt_number_cannot_be_reused(): void
    {
        $officer = $this->officer();
        $first = Payment::factory()->create();
        $second = Payment::factory()->create();

        $this->actingAs($officer)->post("/admin/payments/{$first->id}/review", [
            'decision' => PaymentStatus::Verified->value,
            'or_number' => 'OR-2026-00417',
        ]);

        $this->actingAs($officer)
            ->post("/admin/payments/{$second->id}/review", [
                'decision' => PaymentStatus::Verified->value,
                'or_number' => 'OR-2026-00417',
            ])
            ->assertSessionHasErrors('or_number');

        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
    }

    /**
     * A promissory note is verified without a receipt — no money has arrived,
     * so there is nothing to issue an OR against.
     */
    public function test_a_payment_can_be_verified_without_a_receipt(): void
    {
        $payment = Payment::factory()->create(['payment_method' => PaymentMethod::Promissory]);

        $this->actingAs($this->officer())
            ->post("/admin/payments/{$payment->id}/review", [
                'decision' => PaymentStatus::Verified->value,
            ])
            ->assertSessionHas('success');

        $payment = $payment->fresh();

        $this->assertSame(PaymentStatus::Verified, $payment->status);
        $this->assertNull($payment->or_number);
        $this->assertNull($payment->collecting_officer_id);
    }

    // --- Refunds ----------------------------------------------------------

    /** The payee block every claim needs. */
    private function payee(array $overrides = []): array
    {
        return array_merge([
            'account_name' => 'Juan Dela Cruz',
            'bank_name' => 'Land Bank of the Philippines',
            'account_number' => '1234567890',
        ], $overrides);
    }

    private function claim(Payment $payment, string $reason = 'The training was cancelled.', ?float $amount = null): RefundRequest
    {
        return RefundService::request($payment, $reason, $this->payee(), $amount);
    }

    /** Walk a claim forward to a given stage, the way officers would. */
    private function advanceTo(RefundRequest $refund, RefundStatus $target): RefundRequest
    {
        $officer = $this->officer();

        while ($refund->status !== $target && $refund->status->next() !== null) {
            $refund = RefundService::advance($refund, $refund->status->next(), $officer);
        }

        return $refund;
    }

    public function test_only_a_verified_payment_can_be_refunded(): void
    {
        $payment = Payment::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only a verified payment');

        $this->claim($payment);
    }

    public function test_a_participant_can_claim_a_refund_on_a_verified_payment(): void
    {
        $participant = $this->participant();
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/refund", $this->payee([
                'reason' => 'The training was cancelled by CSC.',
                'proof' => UploadedFile::fake()->create('receipt.pdf', 64, 'application/pdf'),
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $refund = RefundRequest::sole();

        $this->assertSame('1500.00', $refund->amount);
        $this->assertSame(RefundStatus::ForReview, $refund->status);
        $this->assertSame('Land Bank of the Philippines', $refund->bank_name);
        $this->assertNotNull($refund->proof_path);
        // The code is what the participant quotes on follow-up, so it has to
        // exist from the moment the claim does.
        $this->assertMatchesRegularExpression('/^RFD-\d{4}-\d{3}$/', $refund->request_code);
    }

    public function test_a_claim_without_bank_details_is_refused(): void
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
            ->assertSessionHasErrors(['account_name', 'bank_name', 'account_number', 'proof']);

        $this->assertSame(0, RefundRequest::count());
    }

    public function test_a_refund_cannot_exceed_the_amount_paid(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot exceed the amount paid');

        $this->claim($payment, 'A reason for the claim.', 5000);
    }

    public function test_a_second_open_refund_is_refused(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->claim($payment, 'First claim reason.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already in progress');

        $this->claim($payment->fresh(), 'Second claim reason.');
    }

    /**
     * The regression that motivated the rewrite: under the old three-status
     * shape a claim that had left review was no longer "pending", so a second
     * one could be filed against the same payment while the first sat at MSD.
     */
    public function test_a_claim_mid_pipeline_still_blocks_a_second_one(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->advanceTo($this->claim($payment), RefundStatus::ForwardedToMsd);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already in progress');

        $this->claim($payment->fresh(), 'Second claim reason.');
    }

    public function test_an_already_refunded_payment_cannot_be_claimed_again(): void
    {
        $payment = Payment::factory()->verified()->create();

        $this->advanceTo($this->claim($payment), RefundStatus::Refunded);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already been refunded');

        $this->claim($payment->fresh(), 'Trying again.');
    }

    public function test_a_refund_walks_the_pipeline_one_stage_at_a_time(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create());
        $officer = $this->officer();

        foreach ([
            RefundStatus::Processing,
            RefundStatus::ForwardedToMsd,
            RefundStatus::ForRelease,
            RefundStatus::Refunded,
        ] as $stage) {
            $refund = RefundService::advance($refund, $stage, $officer);
            $this->assertSame($stage, $refund->status);
        }

        $this->assertNotNull($refund->refunded_at);
    }

    public function test_a_stage_cannot_be_skipped(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot move straight to');

        RefundService::advance($refund, RefundStatus::Refunded, $this->officer());
    }

    public function test_a_settled_refund_cannot_be_moved_again(): void
    {
        $refund = $this->advanceTo(
            $this->claim(Payment::factory()->verified()->create()),
            RefundStatus::Refunded,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already Refunded');

        RefundService::advance($refund, RefundStatus::Refunded, $this->officer());
    }

    public function test_every_transition_is_logged_with_its_actor(): void
    {
        $officer = $this->officer();
        $refund = $this->claim(Payment::factory()->verified()->create());

        RefundService::advance($refund, RefundStatus::Processing, $officer, 'Documents complete.');

        $trail = $refund->fresh()->statusLogs;

        $this->assertCount(2, $trail);
        // The opening entry is the participant's, so it carries no staff actor.
        $this->assertNull($trail[0]->changed_by);
        $this->assertSame(RefundStatus::ForReview, $trail[0]->to_status);
        $this->assertSame($officer->getKey(), $trail[1]->changed_by);
        $this->assertSame(RefundStatus::ForReview, $trail[1]->from_status);
        $this->assertSame(RefundStatus::Processing, $trail[1]->to_status);
        $this->assertSame('Documents complete.', $trail[1]->notes);
    }

    public function test_an_officer_can_advance_a_refund_from_the_queue(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create());

        $this->actingAs($this->officer())
            ->post("/admin/refunds/{$refund->id}/review", [
                'decision' => 'advance',
                'target' => RefundStatus::Processing->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(RefundStatus::Processing, $refund->fresh()->status);
    }

    public function test_declining_a_refund_requires_a_reason(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create(), 'A claim reason.');

        $this->actingAs($this->officer())
            ->post("/admin/refunds/{$refund->id}/review", ['decision' => 'reject'])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(RefundStatus::ForReview, $refund->fresh()->status);
    }

    public function test_declining_a_refund_leaves_no_refunded_timestamp(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create(), 'A claim reason.');

        RefundService::reject($refund, $this->officer(), 'Outside the refund window.');

        $refund = $refund->fresh();

        $this->assertSame(RefundStatus::Rejected, $refund->status);
        $this->assertNull($refund->refunded_at);
        $this->assertSame('Outside the refund window.', $refund->rejection_reason);
    }

    /**
     * MSD can bounce a claim HRD already passed, so declining has to stay
     * reachable from the middle of the pipeline, not just its head.
     */
    public function test_a_refund_can_be_declined_mid_pipeline(): void
    {
        $refund = $this->advanceTo(
            $this->claim(Payment::factory()->verified()->create()),
            RefundStatus::ForwardedToMsd,
        );

        RefundService::reject($refund, $this->officer(), 'Account details did not match.');

        $this->assertSame(RefundStatus::Rejected, $refund->fresh()->status);
    }

    public function test_the_participant_is_notified_at_every_stage(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);
        $refund = $this->claim($payment);
        $officer = $this->officer();

        RefundService::advance($refund, RefundStatus::Processing, $officer);
        RefundService::advance($refund->fresh(), RefundStatus::ForwardedToMsd, $officer);

        Notification::assertSentToTimes($participant, RefundReviewed::class, 2);
    }

    public function test_the_account_number_is_masked_from_staff_who_do_not_handle_money(): void
    {
        $refund = $this->claim(Payment::factory()->verified()->create());

        $this->actingAs($this->officer())
            ->get('/admin/payments')
            ->assertInertia(fn ($page) => $page->where('refunds.data.0.account_number', '1234567890'));

        $this->actingAs($this->officer(Role::Admin))
            ->get('/admin/payments')
            ->assertInertia(fn ($page) => $page->where('refunds.data.0.account_number', '••••••7890'));

        $this->assertNotNull($refund->request_code);
    }

    public function test_refund_proof_is_reachable_by_its_owner_and_officers_only(): void
    {
        $participant = $this->participant();
        $payment = Payment::factory()->verified()->create([
            'registration_id' => $this->paidRegistration($participant)->getKey(),
            'user_id' => $participant->getKey(),
        ]);

        $this->actingAs($participant)->post("/my/payments/{$payment->id}/refund", $this->payee([
            'reason' => 'The training was cancelled by CSC.',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 64, 'application/pdf'),
        ]));

        $refund = RefundRequest::sole();

        $this->actingAs($participant)->get("/refunds/{$refund->id}/proof")->assertOk();
        $this->actingAs($this->officer())->get("/refunds/{$refund->id}/proof")->assertOk();
        $this->actingAs($this->participant())->get("/refunds/{$refund->id}/proof")->assertForbidden();
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
                // A designation on top of the job they already hold, which is
                // how v1 has it — not a role that replaces it.
                'role' => Role::FieldOffice->value,
                'field_office_id' => FieldOffice::first()->getKey(),
                'is_collecting_officer' => true,
            ])
            ->assertRedirect('/admin/users');

        $appointed = User::where('email', 'cashier@csc.gov.ph')->sole();

        $this->assertSame(Role::FieldOffice, $appointed->role);
        $this->assertTrue($appointed->collectsPayments());
        // The office survives the appointment — that is the whole point.
        $this->assertNotNull($appointed->field_office_id);
    }

    public function test_the_retired_collecting_officer_role_is_no_longer_assignable(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => Role::SuperAdmin,
            'profile_completed_at' => now(),
        ]))
            ->post('/admin/users', [
                'name' => 'Cashier Two',
                'email' => 'cashier2@csc.gov.ph',
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'role' => Role::CollectingOfficer->value,
            ])
            ->assertSessionHasErrors('role');
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

    public function test_the_queue_carries_the_summary_counts_and_refund_pipeline(): void
    {
        $pending = Payment::factory()->create(['amount' => 1500]);
        $verified = Payment::factory()->verified()->create(['amount' => 2000]);
        Payment::factory()->rejected()->create(['amount' => 500]);
        $this->claim($verified);

        $this->actingAs($this->officer())
            ->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The chips and summary count the whole queue, not the
                // default "pending" filter the rows are narrowed to.
                ->where('summary.pending.count', 1)
                ->where('summary.pending.amount', 1500)
                ->where('summary.verified.count', 1)
                ->where('summary.verified.amount', 2000)
                ->where('summary.rejected.count', 1)
                ->where('summary.rejected.amount', 500)
                ->where('summary.open_refunds.count', 1)
                ->where('paymentCounts.pending', 1)
                ->where('paymentCounts.verified', 1)
                ->where('paymentCounts.rejected', 1)
                ->where('refundCounts.for_review', 1)
                // The refunds list is its own paginated pipeline, carrying the
                // ordered stages the screen draws the timeline from.
                ->has('refunds.data', 1)
                ->where('refunds.data.0.status', 'for_review')
                ->where('refunds.data.0.next_stage.value', 'processing')
                ->where('refunds.data.0.can_act', true)
                ->has('refundPipeline')
            );
    }

    public function test_the_queue_carries_the_officers_remarks(): void
    {
        Payment::factory()->verified()->create(['remarks' => 'Paid at the branch counter.']);

        $this->actingAs($this->officer())
            ->get('/admin/payments?status=verified')
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.remarks', 'Paid at the branch counter.')
            );
    }

    public function test_the_queue_honours_the_search_and_method_filters(): void
    {
        $target = Payment::factory()->create([
            'amount' => 1000,
            'payment_method' => PaymentMethod::Cash->value,
            'or_number' => 'OR-SEARCH-1',
        ]);
        Payment::factory()->create([
            'amount' => 900,
            'payment_method' => PaymentMethod::Online->value,
        ]);

        $this->actingAs($this->officer())
            ->get('/admin/payments?search=OR-SEARCH')
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.or_number', 'OR-SEARCH-1')
                ->where('filters.search', 'OR-SEARCH')
            );

        $this->actingAs($this->officer())
            ->get('/admin/payments?method=cash')
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $target->getKey())
                ->where('filters.method', 'cash')
            );
    }

    // --- Money taken at the counter (v1's payment-actions.php) ------------

    public function test_an_officer_can_record_a_payment_taken_at_the_counter(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);
        // HRD, so the registration is in scope whatever office the seeded
        // participant landed in; the scoped case has its own test below.
        $officer = $this->officer(Role::Admin);

        $this->actingAs($officer)
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-000123',
                'or_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = Payment::where('registration_id', $registration->getKey())->sole();

        // Lands verified, not pending: there is nothing to review — the money
        // and the receipt both changed hands at the desk.
        $this->assertSame(PaymentStatus::Verified, $payment->status);
        $this->assertSame('OR-000123', $payment->or_number);
        // The officer who recorded it is the one accountable for the receipt.
        $this->assertSame($officer->getKey(), $payment->collecting_officer_id);
        $this->assertSame($officer->getKey(), $payment->verified_by);
        $this->assertTrue($registration->fresh()->hasClearedFee());

        // Same road as a reviewed upload, so the participant still hears about it.
        Notification::assertSentTo($participant, PaymentReviewed::class);
    }

    public function test_a_counter_payment_can_name_the_officer_who_collected_it(): void
    {
        $registration = $this->paidRegistration($this->participant());
        $collector = $this->officer();

        // HRD entering money a field office actually took.
        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-000456',
                'collecting_officer_id' => $collector->getKey(),
            ])
            ->assertRedirect();

        $this->assertSame(
            $collector->getKey(),
            Payment::where('registration_id', $registration->getKey())->sole()->collecting_officer_id
        );
    }

    public function test_a_designated_field_office_officer_collects_for_their_own_office_only(): void
    {
        [$mine, $theirs] = FieldOffice::active()->take(2)->get();

        $officer = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => $mine->getKey(),
            'profile_completed_at' => now(),
            'is_collecting_officer' => true,
        ]);

        $ours = $this->participant();
        $ours->profile->update(['field_office_id' => $mine->getKey()]);

        $others = $this->participant();
        $others->profile->update(['field_office_id' => $theirs->getKey()]);

        $post = fn (Registration $registration, string $or) => $this->actingAs($officer)
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => $or,
            ]);

        // The designation grants the till; the role still bounds the reach.
        $post($this->paidRegistration($ours), 'OR-100001')->assertRedirect();
        $post($this->paidRegistration($others), 'OR-100002')->assertNotFound();

        $this->assertSame(1, Payment::count());
    }

    public function test_a_settled_registration_cannot_be_charged_twice(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $record = fn (string $or) => $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => $or,
            ]);

        $record('OR-000789')->assertRedirect();
        $record('OR-000790')->assertSessionHasErrors('payment');

        $this->assertSame(1, Payment::where('registration_id', $registration->getKey())->count());
    }

    public function test_a_settlement_payment_must_carry_an_official_receipt(): void
    {
        $registration = $this->paidRegistration($this->participant());

        // The OR stub is the whole reason this is recorded here rather than
        // reviewed, so cash without one is refused.
        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('or_number');

        $this->assertSame(0, Payment::where('registration_id', $registration->getKey())->count());
    }

    // --- PRIME-HRM 20% discount -------------------------------------------

    public function test_a_counter_payment_can_carry_the_prime_hrm_discount(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                // Deliberately wrong: the officer ticks a box, the server does
                // the arithmetic. A posted amount must not be able to steer it.
                'amount' => 9999,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-PRIME-01',
                'prime_hrm_discount' => true,
            ])
            ->assertRedirect();

        $payment = Payment::sole();

        $this->assertTrue($payment->prime_hrm_discount);
        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame('300.00', $payment->discount_amount);
        // The identity that makes the revenue report trustworthy.
        $this->assertSame(1500.0, $payment->grossAmount());
        $this->assertSame(20.0, $payment->discountRate());
    }

    public function test_a_payment_without_the_discount_records_no_forgone_revenue(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-FULL-01',
            ])
            ->assertRedirect();

        $payment = Payment::sole();

        $this->assertFalse($payment->prime_hrm_discount);
        $this->assertSame('0.00', $payment->discount_amount);
        $this->assertSame(1500.0, $payment->grossAmount());
        $this->assertNull($payment->discountRate());
    }

    /**
     * The whole reason the peso value is stored rather than recomputed.
     */
    public function test_a_later_fee_change_cannot_rewrite_a_closed_payment(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1200,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-PRIME-02',
                'prime_hrm_discount' => true,
            ])
            ->assertRedirect();

        // The course fee doubles next year.
        $registration->training->forceFill(['payment_amount' => 3000])->save();

        $payment = Payment::sole()->fresh();

        // Last year's receipt is untouched: still ₱1,200 collected against a
        // ₱1,500 fee. Recomputing from the training would have invented ₱600 of
        // discount nobody granted.
        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame('300.00', $payment->discount_amount);
        $this->assertSame(1500.0, $payment->grossAmount());
    }

    public function test_the_discount_is_refused_on_a_training_with_no_fee(): void
    {
        $participant = $this->participant();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create(['payment_required' => false])->getKey(),
        ]);

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1200,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-PRIME-03',
                'prime_hrm_discount' => true,
            ])
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Payment::count());
    }

    /**
     * A promissory note may carry the discount — the note is then written for
     * the discounted amount.
     */
    public function test_a_promissory_note_can_carry_the_discount(): void
    {
        $registration = $this->paidRegistration($this->participant());

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Promissory->value,
                'payment_date' => now()->toDateString(),
                'prime_hrm_discount' => true,
            ])
            ->assertRedirect();

        $payment = Payment::sole();

        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame('300.00', $payment->discount_amount);
    }

    public function test_an_uploaded_payment_can_be_verified_as_discounted(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        // The participant was quoted the discounted price and paid it.
        $payment = Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $registration->training_id,
            'amount' => 1200,
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/payments/{$payment->id}/review", [
                'decision' => PaymentStatus::Verified->value,
                'or_number' => 'OR-PRIME-04',
                'prime_hrm_discount' => true,
            ])
            ->assertRedirect();

        $payment->refresh();

        // The amount is a fact — the money already moved — so the discount
        // records why it fell short of the fee rather than changing it.
        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame('300.00', $payment->discount_amount);
        $this->assertSame(PaymentStatus::Verified, $payment->status);
    }

    public function test_a_discount_that_does_not_reconcile_is_refused(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        // Paid the full fee, but the officer ticks the discount box.
        $payment = Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $registration->training_id,
            'amount' => 1500,
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($this->officer(Role::Admin))
            ->post("/admin/payments/{$payment->id}/review", [
                'decision' => PaymentStatus::Verified->value,
                'or_number' => 'OR-PRIME-05',
                'prime_hrm_discount' => true,
            ])
            ->assertSessionHasErrors('prime_hrm_discount');

        $payment->refresh();

        // Nothing recorded, nothing verified: an overpayment is a discrepancy
        // for the officer to resolve, not something to annotate away.
        $this->assertFalse($payment->prime_hrm_discount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_a_participant_cannot_grant_themselves_the_discount(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);

        $this->actingAs($participant)
            ->post("/my/registrations/{$registration->id}/payments", [
                'amount' => 1200,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'prime_hrm_discount' => true,
            ])
            ->assertRedirect();

        // The field is not part of the participant's form, so it is ignored
        // rather than honoured. The officer decides at verification.
        $payment = Payment::sole();

        $this->assertFalse($payment->prime_hrm_discount);
        $this->assertSame('0.00', $payment->discount_amount);
    }

    // --- Settling the fee confirms the slot --------------------------------

    /** A pending registration on a paid training, with a payment awaiting review. */
    private function pendingWithPayment(PaymentMethod $method = PaymentMethod::Cash): Payment
    {
        $participant = $this->participant();
        $registration = Registration::factory()->create([
            'user_id' => $participant->getKey(),
            'status' => RegistrationStatus::Pending,
            'training_id' => Training::factory()->create([
                'payment_required' => true,
                'payment_amount' => 1500,
            ])->getKey(),
        ]);

        return Payment::factory()->create([
            'registration_id' => $registration->getKey(),
            'user_id' => $participant->getKey(),
            'training_id' => $registration->training_id,
            'amount' => 1500,
            'payment_method' => $method,
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_verifying_a_payment_confirms_the_slot(): void
    {
        Notification::fake();

        $payment = $this->pendingWithPayment();

        PaymentService::verify($payment, $this->officer(Role::Admin), null, ['or_number' => 'OR-AUTO-01']);

        $registration = $payment->registration->fresh();

        $this->assertSame(RegistrationStatus::Approved, $registration->status);
        // The officer who took the money is the one on record for the decision.
        $this->assertNotNull($registration->reviewed_by);
        $this->assertStringContainsString('fee was settled', $registration->review_remarks);
        // The participant hears they are confirmed, as with any approval.
        Notification::assertSentTo($payment->user, RegistrationReviewed::class);
    }

    /**
     * A note is not money, but the office has accepted it — so it confirms the
     * slot on the same terms. The certificate is still withheld until the money
     * actually arrives, which hasClearedFee() enforces separately.
     */
    public function test_a_verified_promissory_note_also_confirms_the_slot(): void
    {
        $payment = $this->pendingWithPayment(PaymentMethod::Promissory);

        PaymentService::verify($payment, $this->officer(Role::Admin));

        $registration = $payment->registration->fresh();

        $this->assertSame(RegistrationStatus::Approved, $registration->status);
        $this->assertTrue($registration->hasSettledFee());
        // Settled, but not cleared — no certificate on a promise.
        $this->assertFalse($registration->fresh()->load('payments')->hasClearedFee());
    }

    public function test_a_waitlisted_registration_is_not_approved_by_paying(): void
    {
        $payment = $this->pendingWithPayment();
        $payment->registration->forceFill(['status' => RegistrationStatus::Waitlisted])->save();

        PaymentService::verify($payment, $this->officer(Role::Admin), null, ['or_number' => 'OR-AUTO-02']);

        // A waitlisted registration holds no slot, so approving it here would
        // put the training over capacity. That is the office's call to make.
        $this->assertSame(RegistrationStatus::Waitlisted, $payment->registration->fresh()->status);
        // The money is still a fact — verification is not undone.
        $this->assertSame(PaymentStatus::Verified, $payment->fresh()->status);
    }

    public function test_paying_cannot_reverse_a_rejection(): void
    {
        $payment = $this->pendingWithPayment();
        $payment->registration->forceFill([
            'status' => RegistrationStatus::Rejected,
            'review_remarks' => 'Agency has already sent its maximum nominees.',
        ])->save();

        PaymentService::verify($payment, $this->officer(Role::Admin), null, ['or_number' => 'OR-AUTO-03']);

        $registration = $payment->registration->fresh();

        $this->assertSame(RegistrationStatus::Rejected, $registration->status);
        $this->assertSame('Agency has already sent its maximum nominees.', $registration->review_remarks);
    }

    public function test_an_already_approved_registration_is_left_alone(): void
    {
        $payment = $this->pendingWithPayment();
        $payment->registration->forceFill([
            'status' => RegistrationStatus::Approved,
            'review_remarks' => 'Approved by HRD on the nomination list.',
        ])->save();

        PaymentService::verify($payment, $this->officer(Role::Admin), null, ['or_number' => 'OR-AUTO-04']);

        // The original reviewer's note survives — the fee did not re-decide it.
        $this->assertSame(
            'Approved by HRD on the nomination list.',
            $payment->registration->fresh()->review_remarks
        );
    }

    public function test_a_rejected_registration_is_not_asked_to_pay(): void
    {
        $participant = $this->participant();
        $registration = $this->paidRegistration($participant);
        $registration->forceFill(['status' => RegistrationStatus::Rejected])->save();

        // Being shown a bank account for a training you were refused is wrong
        // on its own, and doubly so now that settling confirms the slot.
        $this->actingAs($participant)
            ->get('/my/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('awaitingPayment', 0));
    }

    // --- Revenue reporting -------------------------------------------------

    /**
     * Build a training with a mix of paying participants.
     *
     * @return array{0: Training, 1: User}
     */
    private function trainingWithRevenue(): array
    {
        $training = Training::factory()->create([
            'payment_required' => true,
            'payment_amount' => 1500,
        ]);

        $officer = $this->officer(Role::Admin);

        $pay = function (float $amount, bool $discounted, string $or, PaymentMethod $method) use ($training, $officer) {
            $participant = $this->participant();
            $registration = Registration::factory()->approved()->create([
                'user_id' => $participant->getKey(),
                'training_id' => $training->getKey(),
            ]);

            $this->actingAs($officer)->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => $amount,
                'payment_method' => $method->value,
                'payment_date' => now()->toDateString(),
                'or_number' => $or,
                'prime_hrm_discount' => $discounted,
            ])->assertRedirect();
        };

        $pay(1500, false, 'OR-REV-01', PaymentMethod::Cash);
        $pay(1500, true, 'OR-REV-02', PaymentMethod::Cash);
        $pay(1500, true, 'OR-REV-03', PaymentMethod::Cash);
        // A promissory note: verified, but no money arrived.
        $pay(1500, false, 'OR-REV-04', PaymentMethod::Promissory);

        return [$training, $officer];
    }

    public function test_the_roster_reports_revenue_and_names_the_discounted(): void
    {
        [$training, $officer] = $this->trainingWithRevenue();

        $this->actingAs($officer)
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Three settled payments assessed at ₱1,500 each.
                ->where('revenue.gross', 4500)
                ->where('revenue.discount', 600)
                ->where('revenue.collected', 3900)
                ->where('revenue.discounted_count', 2)
                // A note is verified but is not money, so it is counted apart
                // rather than folded into what was collected.
                ->where('revenue.promissory_count', 1)
                ->has('revenue.discounted', 2)
            );
    }

    public function test_revenue_totals_reconcile(): void
    {
        [$training, $officer] = $this->trainingWithRevenue();

        $this->actingAs($officer)
            ->get("/admin/trainings/{$training->id}/roster")
            ->assertOk()
            ->assertInertia(function ($page) {
                $revenue = $page->toArray()['props']['revenue'];

                // The identity the whole report rests on.
                $this->assertSame(
                    (float) $revenue['gross'],
                    (float) $revenue['collected'] + (float) $revenue['discount'],
                );
            });
    }

    public function test_the_revenue_export_identifies_the_discounted_participants(): void
    {
        [$training, $officer] = $this->trainingWithRevenue();

        $response = $this->actingAs($officer)
            ->get("/admin/exports/trainings/{$training->id}/revenue")
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('PRIME-HRM Discount', $csv);
        // Spelled out for the person reconciling the sheet.
        $this->assertStringContainsString('Yes (20%)', $csv);
        $this->assertStringContainsString('OR-REV-02', $csv);
        // The full-price row is still there, marked as such.
        $this->assertStringContainsString('OR-REV-01', $csv);
    }

    public function test_the_revenue_export_is_closed_to_staff_who_do_not_handle_money(): void
    {
        [$training] = $this->trainingWithRevenue();

        $this->actingAs($this->staffWithoutTill())
            ->get("/admin/exports/trainings/{$training->id}/revenue")
            ->assertForbidden();
    }

    private function staffWithoutTill(): User
    {
        return User::factory()->create([
            'role' => Role::Management,
            'profile_completed_at' => now(),
            'is_collecting_officer' => false,
        ]);
    }

    public function test_a_scoped_officer_cannot_record_against_another_office(): void
    {
        $registration = $this->paidRegistration($this->participant());

        // A field-office account with no office resolves to 0, matching nothing
        // — the same fail-closed rule the roster and directory use.
        $staff = User::factory()->create([
            'role' => Role::FieldOffice,
            'field_office_id' => null,
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->post("/admin/registrations/{$registration->id}/payment", [
                'amount' => 1500,
                'payment_method' => PaymentMethod::Cash->value,
                'payment_date' => now()->toDateString(),
                'or_number' => 'OR-000999',
            ])
            ->assertForbidden();

        $this->assertSame(0, Payment::where('registration_id', $registration->getKey())->count());
    }
}
