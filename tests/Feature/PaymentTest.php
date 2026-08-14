<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
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
use App\Support\RefundService;
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

        $this->actingAs($this->officer(Role::CollectingOfficer))
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
}
