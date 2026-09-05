<?php

namespace Tests\Feature;

use App\Enums\PhysicalOrRequestStatus;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\PhysicalOrRequest;
use App\Models\PhysicalOrSetting;
use App\Models\Profile;
use App\Models\Registration;
use App\Models\Training;
use App\Models\User;
use App\Support\PhysicalOrRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The physical-OR delivery pipeline: filing a request outside Region VIII,
 * verifying the GCash courier fee proof, and walking the receipt out to the
 * courier. Organised like PaymentTest — service where the rule lives, and a
 * controller test where the route is what is on trial.
 */
class PhysicalOrRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function officer(Role $role = Role::Admin): User
    {
        return User::factory()->create(['role' => $role, 'profile_completed_at' => now()]);
    }

    /**
     * A participant, by default outside the region so a physical receipt is
     * actually on the table. Pass a region to pin one down.
     */
    private function participant(?string $region = 'REGION X'): User
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        Profile::factory()->for($user)->create(['region' => $region]);

        return $user->refresh();
    }

    /**
     * A verified, receipted payment on a paid training — the only shape that
     * can be mailed a physical OR.
     */
    /** The OR number is a parameter because `or_number` is unique — two receipted
     * payments in one test need two of them. */
    private function receiptedPayment(User $participant, string $orNumber = 'OR-2026-10001'): Payment
    {
        return Payment::factory()->verified()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create([
                'payment_required' => true,
                'payment_amount' => 1500,
            ])->getKey(),
            'or_number' => $orNumber,
            'collecting_officer_id' => $this->officer(Role::CollectingOfficer)->getKey(),
        ]);
    }

    // --- Filing ----------------------------------------------------------

    public function test_an_outside_region_participant_can_file_a_request_with_proof(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [
                'proof' => UploadedFile::fake()->create('gcash.png', 60, 'image/png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = PhysicalOrRequest::sole();

        $this->assertSame(PhysicalOrRequestStatus::PaymentVerificationPending, $request->status);
        $this->assertStringStartsWith('OR-', $request->request_code);
        Storage::disk('local')->assertExists($request->proof_path);

        // Filed with the screenshot attached, the request skips straight to
        // the verification queue — and the log says so in two moments.
        $this->assertSame(2, $request->statusLogs()->count());
    }

    public function test_a_request_may_be_filed_before_the_proof_is_paid(): void
    {
        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = PhysicalOrRequest::sole();

        $this->assertSame(PhysicalOrRequestStatus::RequestSubmitted, $request->status);
        $this->assertNull($request->proof_path);
    }

    public function test_only_a_verified_receipted_payment_can_be_requested(): void
    {
        $participant = $this->participant();
        $registration = Registration::factory()->approved()->create([
            'user_id' => $participant->getKey(),
            'training_id' => Training::factory()->create(['payment_required' => true])->getKey(),
        ]);

        $pending = Payment::factory()->create(['registration_id' => $registration->getKey()]);
        $unreceipted = Payment::factory()->verified()->create([
            'registration_id' => $registration->getKey(),
            'or_number' => null,
        ]);

        $this->actingAs($participant)->post("/my/payments/{$pending->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');
        $this->actingAs($participant)->post("/my/payments/{$unreceipted->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');

        $this->assertSame(0, PhysicalOrRequest::count());
    }

    // --- The region gate -------------------------------------------------

    public function test_a_participant_inside_region_viii_cannot_request(): void
    {
        $participant = $this->participant('REGION VIII');
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');

        $this->assertSame(0, PhysicalOrRequest::count());
    }

    /** Fails open: no region on file still offers the option. */
    public function test_a_missing_region_does_not_block_the_request(): void
    {
        $participant = $this->participant(null);
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PhysicalOrRequest::count());
    }

    public function test_an_in_region_participant_is_never_offered_the_option(): void
    {
        $participant = $this->participant('REGION VIII');
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->get('/my/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('My/Payments')
                ->where('payments.0.can_request_physical_or', false));
    }

    /**
     * The gate follows `office.psgc_region`, so another office's deployment
     * gates on its own region.
     *
     * This was `str_contains($region, 'VIII')` written into Profile — correct
     * in exactly one deployment. Everywhere else nobody matched, so every
     * participant read as an outsider and the whole region was offered courier
     * delivery of a receipt they could have collected at the counter. It was
     * silent: no error, no wrong page, just unexpected postage.
     */
    public function test_the_region_gate_follows_the_configured_office(): void
    {
        config(['office.psgc_region' => 'Region V (Bicol Region)']);

        // Local to a Bicol office, so the option is refused.
        $local = $this->participant('Region V (Bicol Region)');
        $localPayment = $this->receiptedPayment($local);

        $this->actingAs($local)
            ->post("/my/payments/{$localPayment->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');

        $this->assertSame(0, PhysicalOrRequest::count());

        // An Eastern Visayas participant is now the outsider.
        $this->flushSession();

        $visitor = $this->participant('Region VIII (Eastern Visayas)');
        $visitorPayment = $this->receiptedPayment($visitor, 'OR-2026-10002');

        $this->actingAs($visitor)
            ->post("/my/payments/{$visitorPayment->id}/physical-or", [])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PhysicalOrRequest::count());
    }

    /**
     * Roman numerals are prefixes of one another, which is why the match is
     * word-bounded rather than a substring test.
     *
     * "REGION VIII" contains "VII". Generalising the old check by swapping the
     * numeral in would have made a Region VII office treat every Region VIII
     * participant as local — the same silent failure, one region over.
     */
    public function test_a_neighbouring_roman_numeral_is_not_treated_as_local(): void
    {
        config(['office.psgc_region' => 'Region VII (Central Visayas)']);

        $participant = $this->participant('Region VIII (Eastern Visayas)');
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PhysicalOrRequest::count());
    }

    // --- Duplicates ------------------------------------------------------

    public function test_a_second_request_cannot_ride_on_an_open_one(): void
    {
        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        $this->actingAs($participant)->post("/my/payments/{$payment->id}/physical-or", []);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');

        $this->assertSame(1, PhysicalOrRequest::count());
    }

    public function test_a_rejected_request_can_be_filed_again(): void
    {
        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        PhysicalOrRequestService::reject(
            PhysicalOrRequestService::request($payment, $participant),
            $this->officer(),
            'Address could not be reached.',
        );

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PhysicalOrRequest::count());
    }

    public function test_a_delivered_request_cannot_be_filed_again(): void
    {
        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        $request = PhysicalOrRequestService::request($payment, $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::Delivered);

        $this->actingAs($participant)
            ->post("/my/payments/{$payment->id}/physical-or", [])
            ->assertSessionHasErrors('physical_or');
    }

    // --- The participant's proof upload ----------------------------------

    public function test_a_participant_can_attach_the_proof_later(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);

        $this->actingAs($participant)
            ->post("/my/physical-or/{$request->id}/proof", [
                'proof' => UploadedFile::fake()->create('gcash.png', 60, 'image/png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = $request->fresh();

        $this->assertSame(PhysicalOrRequestStatus::PaymentVerificationPending, $request->status);
        Storage::disk('local')->assertExists($request->proof_path);
    }

    public function test_a_proof_cannot_be_attached_once_verification_started(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::PaymentVerified);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no longer waiting');

        PhysicalOrRequestService::uploadProof(
            $request->fresh(),
            $participant,
            'physical-or-proofs/late.png',
        );
    }

    public function test_a_participant_cannot_touch_someone_elses_request(): void
    {
        $owner = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($owner), $owner);

        $this->actingAs($this->participant())
            ->post("/my/physical-or/{$request->id}/proof", [
                'proof' => UploadedFile::fake()->create('gcash.png', 60, 'image/png'),
            ])
            ->assertForbidden();
    }

    // --- The officer's pipeline ------------------------------------------

    public function test_a_request_moves_one_stage_at_a_time_and_only_forward(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant, 'physical-or-proofs/paid.png');
        $officer = $this->officer();

        PhysicalOrRequestService::advance($request->fresh(), PhysicalOrRequestStatus::PaymentVerified, $officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot move straight to');

        // Skipping straight to "ready for shipment" skips the whole middle.
        PhysicalOrRequestService::advance(
            $request->fresh(),
            PhysicalOrRequestStatus::ReadyForShipment,
            $officer,
        );
    }

    public function test_verifying_the_payment_records_the_officer(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant, 'physical-or-proofs/paid.png');
        $officer = $this->officer();

        PhysicalOrRequestService::advance($request, PhysicalOrRequestStatus::PaymentVerified, $officer);

        $request = $request->fresh();

        $this->assertSame($officer->getKey(), $request->verified_by);
        $this->assertNotNull($request->verified_at);
    }

    public function test_shipping_requires_a_courier_and_a_tracking_number(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::ReadyForShipment);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('courier name and tracking number');

        PhysicalOrRequestService::advance(
            $request->fresh(),
            PhysicalOrRequestStatus::Shipped,
            $this->officer(),
        );
    }

    public function test_shipping_records_the_courier_and_tracking(): void
    {
        Notification::fake();

        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::ReadyForShipment);

        PhysicalOrRequestService::advance(
            $request->fresh(),
            PhysicalOrRequestStatus::Shipped,
            $this->officer(),
            'Via LBC.',
            ['courier_name' => 'LBC Express', 'tracking_number' => 'LB-2026-0044'],
        );

        $request = $request->fresh();

        $this->assertSame('LBC Express', $request->courier_name);
        $this->assertSame('LB-2026-0044', $request->tracking_number);
        $this->assertNotNull($request->shipped_at);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reason');

        PhysicalOrRequestService::reject($request, $this->officer(), '');
    }

    public function test_rejection_is_reachable_from_any_open_stage(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::PaymentVerified);

        PhysicalOrRequestService::reject($request->fresh(), $this->officer(), 'Receipt cannot be mailed.');

        $this->assertSame(PhysicalOrRequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_every_move_leaves_a_trail_and_an_activity_log(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant, 'physical-or-proofs/paid.png');
        $officer = $this->officer();

        PhysicalOrRequestService::advance($request, PhysicalOrRequestStatus::PaymentVerified, $officer);

        // The whole trail, in order, rather than "the latest one".
        // `statusLogs()` carries its own orderBy, so a `latest('id')` tacked
        // on becomes a *secondary* sort and quietly returns the oldest row —
        // which passed only while all three entries shared a second, and
        // failed whenever the clock ticked between filing and verifying.
        $this->assertSame(
            [
                PhysicalOrRequestStatus::RequestSubmitted,
                PhysicalOrRequestStatus::PaymentVerificationPending,
                PhysicalOrRequestStatus::PaymentVerified,
            ],
            $request->fresh()->statusLogs->pluck('to_status')->all(),
        );
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => (new PhysicalOrRequest)->getMorphClass(),
            'subject_id' => $request->getKey(),
            'action' => 'physical_or.payment_verified',
        ]);
    }

    public function test_the_trail_still_reads_in_order_when_a_second_ticks_mid_flow(): void
    {
        $participant = $this->participant();
        $payment = $this->receiptedPayment($participant);

        // Filing writes two entries in one transaction, so they share a
        // `changed_at` to the second. Verifying then happens on the far side
        // of a tick. That gap is what made this file fail intermittently in a
        // full-suite run and pass on its own — the timing was incidental to
        // the test, so nothing pointed at it.
        $this->travelTo(CarbonImmutable::parse('2026-08-17 09:00:00'));
        $request = PhysicalOrRequestService::request($payment, $participant, 'physical-or-proofs/paid.png');

        $this->travelTo(CarbonImmutable::parse('2026-08-17 09:00:01'));
        PhysicalOrRequestService::advance($request->fresh(), PhysicalOrRequestStatus::PaymentVerified, $this->officer());

        $this->travelBack();

        $logs = $request->fresh()->statusLogs;

        $this->assertSame(
            [
                PhysicalOrRequestStatus::RequestSubmitted,
                PhysicalOrRequestStatus::PaymentVerificationPending,
                PhysicalOrRequestStatus::PaymentVerified,
            ],
            $logs->pluck('to_status')->all(),
        );

        // The two that share a second keep their insertion order, which is the
        // tie-break the relation now spells out rather than leaving to the
        // database. Filing must read before the proof that followed it.
        $this->assertTrue($logs[0]->changed_at->equalTo($logs[1]->changed_at));
        $this->assertLessThan($logs[1]->getKey(), $logs[0]->getKey());
    }

    // --- Participant cancellation ----------------------------------------

    public function test_a_participant_can_cancel_before_the_fee_is_verified(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);

        $this->actingAs($participant)
            ->post("/my/physical-or/{$request->id}/cancel")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PhysicalOrRequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame('Cancelled by the participant.', $request->fresh()->rejection_reason);
    }

    public function test_a_participant_cannot_cancel_once_the_fee_is_verified(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::PaymentVerified);

        $this->actingAs($participant)
            ->post("/my/physical-or/{$request->id}/cancel")
            ->assertSessionHasErrors('status');
    }

    public function test_a_participant_cannot_cancel_someone_elses_request(): void
    {
        $owner = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($owner), $owner);

        $this->actingAs($this->participant())
            ->post("/my/physical-or/{$request->id}/cancel")
            ->assertForbidden();
    }

    // --- The proof file ---------------------------------------------------

    public function test_the_proof_is_private_but_readable_by_owner_and_officers(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request(
            $this->receiptedPayment($participant),
            $participant,
            Storage::disk('local')->putFile('physical-or-proofs', UploadedFile::fake()->create('proof.png', 60, 'image/png')),
        );

        $this->actingAs($participant)->get("/physical-or/{$request->id}/proof")->assertOk();
        $this->actingAs($this->officer())->get("/physical-or/{$request->id}/proof")->assertOk();
        $this->actingAs($this->participant())->get("/physical-or/{$request->id}/proof")->assertForbidden();
    }

    // --- The admin queue --------------------------------------------------

    public function test_only_admin_and_superadmin_reach_the_physical_or_queue(): void
    {
        $this->actingAs($this->officer(Role::FieldOffice))->get('/admin/physical-or')->assertForbidden();
        $this->actingAs($this->officer(Role::CollectingOfficer))->get('/admin/physical-or')->assertForbidden();
        $this->actingAs($this->participant())->get('/admin/physical-or')->assertForbidden();

        $this->actingAs($this->officer(Role::Admin))->get('/admin/physical-or')->assertOk();
        $this->actingAs($this->officer(Role::SuperAdmin))->get('/admin/physical-or')->assertOk();
    }

    public function test_an_admin_moves_a_request_through_the_queue_route(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant, 'physical-or-proofs/paid.png');

        $this->actingAs($this->officer())
            ->post("/admin/physical-or/{$request->id}/review", [
                'decision' => 'advance',
                'target' => PhysicalOrRequestStatus::PaymentVerified->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PhysicalOrRequestStatus::PaymentVerified, $request->fresh()->status);
    }

    public function test_an_admin_cannot_skip_a_stage_through_the_route(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant, 'physical-or-proofs/paid.png');

        $this->actingAs($this->officer())
            ->post("/admin/physical-or/{$request->id}/review", [
                'decision' => 'advance',
                'target' => PhysicalOrRequestStatus::Delivered->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(PhysicalOrRequestStatus::PaymentVerificationPending, $request->fresh()->status);
    }

    public function test_the_shipping_fields_are_required_on_the_route(): void
    {
        $participant = $this->participant();
        $request = PhysicalOrRequestService::request($this->receiptedPayment($participant), $participant);
        $this->walkTo($request, PhysicalOrRequestStatus::ReadyForShipment);

        $this->actingAs($this->officer())
            ->post("/admin/physical-or/{$request->id}/review", [
                'decision' => 'advance',
                'target' => PhysicalOrRequestStatus::Shipped->value,
            ])
            ->assertSessionHasErrors(['courier_name', 'tracking_number']);
    }

    public function test_the_settings_are_editable_only_by_an_admin(): void
    {
        $payload = [
            'gcash_number' => '09091234567',
            'account_name' => 'CSC Regional Office',
            'courier_fee' => 250.00,
            'instructions' => 'Please allow three to five days for delivery.',
        ];

        $this->actingAs($this->officer(Role::FieldOffice))
            ->post('/admin/physical-or/settings', $payload)
            ->assertForbidden();

        $this->actingAs($this->officer(Role::Admin))
            ->post('/admin/physical-or/settings', $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('09091234567', PhysicalOrSetting::current()->gcash_number);
        $this->assertSame('250.00', PhysicalOrSetting::current()->courier_fee);
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * Walk a request to a target stage the way officers would, so later rules
     * can be tested against a request that is already most of the way out.
     */
    private function walkTo(PhysicalOrRequest $request, PhysicalOrRequestStatus $target): void
    {
        $officer = $this->officer();
        $shipping = ['courier_name' => 'JRS Express', 'tracking_number' => 'JRS-0001'];

        while ($request->status->isOpen() && $request->status !== $target) {
            $next = $request->status->next();

            if ($next === null) {
                break;
            }

            PhysicalOrRequestService::advance(
                $request->fresh(),
                $next,
                $officer,
                null,
                $next === PhysicalOrRequestStatus::Shipped ? $shipping : [],
            );

            $request = $request->fresh();
        }
    }
}
