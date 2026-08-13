<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Registration;
use App\Support\PaymentService;
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
        $payments = Payment::with(['training', 'refundRequests'])
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->get();

        // Registrations on paid trainings that have no payment recorded yet.
        $awaiting = Registration::with('training')
            ->where('user_id', $request->user()->getKey())
            ->whereHas('training', fn ($query) => $query->where('payment_required', true))
            ->whereDoesntHave('payments')
            ->get();

        return Inertia::render('My/Payments', [
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'training' => $payment->training->title,
                'amount' => $payment->amount,
                'method' => $payment->payment_method->label(),
                'reference_number' => $payment->reference_number,
                'payment_date' => $payment->payment_date->format('d M Y'),
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'rejection_reason' => $payment->rejection_reason,
                'can_request_refund' => $payment->status->isRefundable()
                    && ! $payment->hasPendingRefund()
                    && ! $payment->hasBeenRefunded(),
                'refund_status' => $payment->refundRequests->first()?->status->label(),
                'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            ])->all(),
            'awaitingPayment' => $awaiting->map(fn (Registration $registration) => [
                'registration_id' => $registration->id,
                'training' => $registration->training->title,
                'amount' => $registration->training->payment_amount,
                // Whether a promissory note is on offer is set per training, so
                // the method list has to be narrowed per row rather than once
                // for the page.
                'accepts_promissory' => $registration->training->accepts_promissory,
            ])->all(),
            'methods' => PaymentMethod::options(),
        ]);
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
            // Cash is paid over the counter against a receipt; every other
            // method leaves a reference that is the only proof there is.
            'reference_number' => [
                'nullable', 'string', 'max:64',
                Rule::requiredIf(fn () => PaymentMethod::tryFrom(
                    (string) $request->input('payment_method')
                )?->requiresReference() ?? false),
            ],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        Payment::create([
            'registration_id' => $registration->getKey(),
            'user_id' => $registration->user_id,
            'training_id' => $registration->training_id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'payment_date' => $validated['payment_date'],
            'proof_path' => $request->file('proof')?->store('payment-proofs', self::DISK),
        ]);

        return back()->with('success', 'Your payment has been recorded and is awaiting verification.');
    }

    public function requestRefund(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        PaymentService::requestRefund(
            $payment,
            $validated['reason'],
            isset($validated['amount']) ? (float) $validated['amount'] : null
        );

        return back()->with('success', 'Your refund request has been submitted.');
    }

    /**
     * Proof of payment, for the participant who filed it and the officers who
     * verify it. Never a public URL.
     */
    public function proof(Request $request, Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path !== null, 404);

        $isOwner = $payment->user_id === $request->user()->getKey();

        abort_unless($isOwner || $request->user()->role->handlesPayments(), 403);

        return Storage::disk(self::DISK)->download($payment->proof_path);
    }
}
