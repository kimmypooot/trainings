<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PhysicalOrRequestStatus;
use App\Enums\RefundStatus;
use App\Enums\RegistrationStatus;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\PhysicalOrRequest;
use App\Models\PhysicalOrSetting;
use App\Models\RefundRequest;
use App\Models\Registration;
use App\Support\PaymentService;
use App\Support\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The participant's side of payment: uploading proof and claiming refunds.
 *
 * Ported from v1's `upload-payment.php` and `request-refund.php`.
 */
class PaymentController extends Controller
{
    /** Proof of payment never touches a public disk. */
    public const DISK = 'local';

    public function index(Request $request): Response
    {
        $payments = Payment::with(['training', 'refundRequests.statusLogs', 'physicalOrRequests.statusLogs'])
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->get();

        // Registrations on paid trainings that have no payment recorded yet.
        /*
         * Only a registration that still holds a slot owes anything.
         *
         * This used to have no status filter at all, so a participant whose
         * registration had been rejected or cancelled was still shown a bank
         * account and asked to pay for a training they were not attending.
         * Now that settling the fee confirms the slot, that was also a way to
         * pay past a decision somebody had made on purpose.
         */
        $awaiting = Registration::with('training')
            ->where('user_id', $request->user()->getKey())
            ->whereIn('status', RegistrationStatus::occupying())
            ->whereHas('training', fn ($query) => $query->where('payment_required', true))
            ->whereDoesntHave('payments')
            ->get();

        $orSetting = PhysicalOrSetting::current();
        $bankSetting = PaymentSetting::current();

        return Inertia::render('My/Payments', [
            'payment_settings' => [
                'bank_name' => $bankSetting->bank_name,
                'account_name' => $bankSetting->account_name,
                'account_number' => $bankSetting->account_number,
                'instructions' => $bankSetting->instructions,
            ],
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'training' => [
                    'title' => $payment->training->title,
                    'starts_at' => $payment->training->starts_at->format('d M Y, g:i A'),
                    'mode_label' => $payment->training->mode->label(),
                    'url' => route('trainings.show', $payment->training->slug),
                ],
                'amount' => $payment->amount,
                'method' => $payment->payment_method->label(),
                'reference_number' => $payment->reference_number,
                'payment_date' => $payment->payment_date->format('d M Y'),
                'or_number' => $payment->or_number,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'rejection_reason' => $payment->rejection_reason,
                'can_request_refund' => $payment->status->isRefundable()
                    && ! $payment->hasPendingRefund()
                    && ! $payment->hasBeenRefunded(),
                // The whole claim, not just its label — a participant checking
                // this page is almost always asking "what stage is it at", and
                // only the stage track answers that.
                'refund' => $this->refundPayload($payment->refundRequests->sortByDesc('created_at')->first()),
                'can_request_physical_or' => $this->canRequestPhysicalOr($payment, $request),
                // Same shape as the refund block: the participant wants the
                // stage, not the label.
                'physical_or' => $this->physicalOrPayload($payment->physicalOrRequests->sortByDesc('created_at')->first()),
                'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            ])->all(),
            'awaitingPayment' => $awaiting->map(fn (Registration $registration) => [
                'registration_id' => $registration->id,
                'training' => [
                    'title' => $registration->training->title,
                    'starts_at' => $registration->training->starts_at->format('d M Y, g:i A'),
                    'mode_label' => $registration->training->mode->label(),
                    'url' => route('trainings.show', $registration->training->slug),
                ],
                'amount' => $registration->training->payment_amount,
                // Whether a promissory note is on offer is set per training, so
                // the method list has to be narrowed per row rather than once
                // for the page.
                'accepts_promissory' => $registration->training->accepts_promissory,
            ])->all(),
            'methods' => PaymentMethod::options(),
            // The GCash details the request modal renders. Configurable by
            // Admin/Super Admin — see Admin\PhysicalOrRequestController.
            'physical_or_settings' => [
                'gcash_number' => $orSetting->gcash_number,
                'account_name' => $orSetting->account_name,
                'courier_fee' => $orSetting->courier_fee,
                'instructions' => $orSetting->delivery_instructions,
            ],
            'physical_or_pipeline' => collect(PhysicalOrRequestStatus::pipeline())
                ->map(fn (PhysicalOrRequestStatus $stage) => ['value' => $stage->value, 'label' => $stage->label()])
                ->all(),
        ]);
    }

    /**
     * A verified, receipted payment outside Region VIII can be mailed a
     * physical copy — unless a delivery is already in flight or was completed.
     */
    private function canRequestPhysicalOr(Payment $payment, Request $request): bool
    {
        return $payment->status->isRefundable()
            && filled($payment->or_number)
            && ($request->user()->profile?->isOutsideCscRegion() ?? true)
            && ! $payment->hasPendingPhysicalOrRequest()
            && ! $payment->hasDeliveredPhysicalOrRequest();
    }

    /**
     * A physical-OR request as the participant sees it: where it is, and how
     * far along the pipeline that is.
     *
     * @return array<string, mixed>|null
     */
    private function physicalOrPayload(?PhysicalOrRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $reached = $request->statusLogs->pluck('to_status');

        return [
            'id' => $request->id,
            'request_code' => $request->request_code,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'message' => $request->status->participantMessage(),
            'rejection_reason' => $request->rejection_reason,
            'courier_name' => $request->courier_name,
            'tracking_number' => $request->tracking_number,
            'can_upload_proof' => $request->status === PhysicalOrRequestStatus::RequestSubmitted,
            'stages' => $request->status === PhysicalOrRequestStatus::Rejected ? [] : array_map(
                fn (PhysicalOrRequestStatus $stage) => [
                    'label' => $stage->label(),
                    'reached' => $reached->contains($stage),
                ],
                PhysicalOrRequestStatus::pipeline(),
            ),
        ];
    }

    /**
     * A refund claim as the participant sees it: where it is, and how far
     * along the pipeline that is.
     *
     * @return array<string, mixed>|null
     */
    private function refundPayload(?RefundRequest $refund): ?array
    {
        if ($refund === null) {
            return null;
        }

        $reached = $refund->statusLogs->pluck('to_status');

        return [
            'id' => $refund->id,
            'request_code' => $refund->request_code,
            'amount' => $refund->amount,
            'status' => $refund->status->value,
            'status_label' => $refund->status->label(),
            'message' => $refund->status->participantMessage(),
            'rejection_reason' => $refund->rejection_reason,
            'is_open' => $refund->status->isOpen(),
            // A declined claim never reaches the later stages, so the track is
            // suppressed entirely rather than shown as permanently stalled.
            'stages' => $refund->status === RefundStatus::Rejected ? [] : array_map(
                fn (RefundStatus $stage) => [
                    'label' => $stage->label(),
                    'reached' => $reached->contains($stage),
                ],
                RefundStatus::pipeline(),
            ),
        ];
    }

    public function store(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);

        $registration->loadMissing('training');

        abort_unless($registration->training->payment_required, 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'payment_method' => [
                'required',
                // A promissory note is only an option where the training was
                // published as accepting one — otherwise it is a way to claim a
                // slot without paying for it.
                Rule::enum(PaymentMethod::class)->when(
                    ! $registration->training->accepts_promissory,
                    fn ($rule) => $rule->except(PaymentMethod::Promissory)
                ),
            ],
            /*
             * No longer asked for at the counter form, so no longer required.
             *
             * It stays accepted rather than rejected: the column holds the
             * references of every payment recorded before this, the admin
             * screens still read it, and staff recording a payment on a
             * participant's behalf may still have one to enter. Requiring it
             * here after the field was removed from the form would have failed
             * every online payment with a message pinned to an input that is
             * not on the page.
             */
            'reference_number' => ['nullable', 'string', 'max:64'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            // Never refused for want of a document. An online transfer without
            // a slip is accepted and then flagged in the verification queue —
            // see PaymentMethod::expectsProof(). Blocking it here only moved
            // the participants who cannot scan onto the counter.
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        PaymentService::submit($registration, [
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'payment_date' => $validated['payment_date'],
            'proof_path' => $request->file('proof')?->store('payment-proofs', self::DISK),
        ]);

        return back()->with('success', 'Your payment has been recorded and is awaiting verification.');
    }

    /**
     * File a refund claim.
     *
     * The bank details are mandatory: MSD disburses by transfer, and a claim
     * without a payee is one HRD has to chase before it can move at all.
     */
    public function requestRefund(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'account_name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            // Kept as a string, never an integer: leading zeros are significant
            // and some banks use dashes.
            'account_number' => ['required', 'string', 'max:64'],
            'proof' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        RefundService::request(
            $payment,
            $validated['reason'],
            [
                'account_name' => $validated['account_name'],
                'bank_name' => $validated['bank_name'],
                'account_number' => $validated['account_number'],
                'proof_path' => $request->file('proof')->store('refund-proofs', self::DISK),
            ],
            isset($validated['amount']) ? (float) $validated['amount'] : null
        );

        return back()->with('success', 'Your refund request has been submitted.');
    }

    /**
     * The proof attached to a refund claim. Same rule as payment proof — the
     * participant who filed it and the officers who act on it, nobody else.
     */
    public function refundProof(Request $request, RefundRequest $refundRequest): StreamedResponse
    {
        abort_unless($refundRequest->proof_path !== null, 404);

        $refundRequest->loadMissing('payment');

        $isOwner = $refundRequest->payment->user_id === $request->user()->getKey();

        if (! $isOwner) {
            abort_unless($request->user()->collectsPayments(), 403);
            $this->assertPaymentInScope($request, $refundRequest->payment);
        }

        // Served inline rather than as an attachment: the officer who acts on
        // the claim reviews the receipt on screen, not after opening a download.
        return Storage::disk(self::DISK)->download(
            $refundRequest->proof_path,
            null,
            ['Content-Disposition' => 'inline; filename="proof-of-payment"'],
        );
    }

    /**
     * Field-office scoping for the two proof routes above, mirroring
     * Admin\PaymentController::scopedPayment() — a field office's collecting
     * officer may verify only its own money, and must not be able to open
     * another office's payment proof by walking ids in the URL either.
     */
    private function assertPaymentInScope(Request $request, Payment $payment): void
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        if ($officeId === null) {
            return;
        }

        $payment->loadMissing('user.profile');

        abort_unless($payment->user->profile?->field_office_id === $officeId, 404);
    }

    /**
     * Proof of payment, for the participant who filed it and the officers who
     * verify it. Never a public URL.
     */
    public function proof(Request $request, Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path !== null, 404);

        $isOwner = $payment->user_id === $request->user()->getKey();

        if (! $isOwner) {
            abort_unless($request->user()->collectsPayments(), 403);
            $this->assertPaymentInScope($request, $payment);
        }

        // Inline, not attachment: the collecting officer has to actually look
        // at the uploaded proof before it is verified, and a download adds a
        // step between seeing the row and seeing the document.
        return Storage::disk(self::DISK)->download(
            $payment->proof_path,
            null,
            ['Content-Disposition' => 'inline; filename="proof-of-payment"'],
        );
    }
}
