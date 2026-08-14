<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Support\PaymentService;
use App\Support\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The collecting officer's queues, ported from v1's
 * `admin/hrd/pending-payments.php` and `refund-mgmt.php`.
 */
class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $payments = Payment::with(['user', 'training', 'verifier', 'collectingOfficer', 'registration'])
            ->when(
                $request->string('status')->toString() ?: PaymentStatus::Pending->value,
                fn ($query, $status) => $query->where('status', $status)
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // Open claims first: a refund sitting mid-pipeline is work, a settled
        // one is history, and mixing them buries the former under the latter.
        $refunds = RefundRequest::with([
            'payment.user', 'payment.training', 'reviewer', 'statusLogs.actor',
        ])
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [
                RefundStatus::Refunded->value, RefundStatus::Rejected->value,
            ])
            ->latest()
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'participant' => $payment->user->name,
                'training' => $payment->training->title,
                'amount' => $payment->amount,
                'method' => $payment->payment_method->label(),
                'reference_number' => $payment->reference_number,
                'payment_date' => $payment->payment_date->format('d M Y'),
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'rejection_reason' => $payment->rejection_reason,
                'verified_by' => $payment->verifier?->name,
                'or_number' => $payment->or_number,
                'or_date' => $payment->or_date?->format('d M Y'),
                'collecting_officer' => $payment->collectingOfficer?->name,
                // What the fee is billed to, carried from the registration —
                // an agency charge is receipted to the agency, not the person.
                'charge_to' => $payment->registration?->charge_to?->label(),
                'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            ]),
            'refunds' => $refunds->map(fn (RefundRequest $refund) => [
                'id' => $refund->id,
                'request_code' => $refund->request_code,
                'participant' => $refund->payment->user->name,
                'training' => $refund->payment->training->title,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status->value,
                'status_label' => $refund->status->label(),
                // The payee block. Officers below collecting-officer never see
                // the full account number — see accountNumberFor().
                'account_name' => $refund->account_name,
                'bank_name' => $refund->bank_name,
                'account_number' => $this->accountNumberFor($refund, $request),
                'proof_url' => $refund->proof_path
                    ? route('payments.refund-proof', $refund)
                    : null,
                'rejection_reason' => $refund->rejection_reason,
                'reviewed_by' => $refund->reviewer?->name,
                'submitted_at' => $refund->created_at->format('d M Y'),
                // Exactly one forward move is ever offered, so the screen
                // cannot put the pipeline out of order.
                'next_stage' => $refund->status->next() === null ? null : [
                    'value' => $refund->status->next()->value,
                    'label' => $refund->status->next()->label(),
                ],
                'can_act' => $refund->status->isOpen(),
                'trail' => $refund->statusLogs->map(fn ($log) => [
                    'to' => $log->to_status->label(),
                    'notes' => $log->notes,
                    'actor' => $log->actor?->name ?? 'Participant',
                    'at' => $log->changed_at->format('d M Y, g:i A'),
                ])->all(),
            ])->all(),
            'filters' => ['status' => $request->string('status')->toString()],
            'statuses' => PaymentStatus::options(),
            'refundStatuses' => RefundStatus::options(),
        ]);
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([PaymentStatus::Verified->value, PaymentStatus::Rejected->value])],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Unique across payments: the same OR number on two rows is a
            // transcription slip or a duplicate, and both are worth catching
            // while the officer still has the receipt in hand.
            'or_number' => [
                'nullable', 'string', 'max:32',
                Rule::unique('payments', 'or_number')->ignore($payment),
            ],
            'or_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        if ($validated['decision'] === PaymentStatus::Verified->value) {
            PaymentService::verify(
                $payment,
                $request->user(),
                $validated['remarks'] ?? null,
                [
                    'or_number' => $validated['or_number'] ?? null,
                    'or_date' => $validated['or_date'] ?? null,
                ],
            );
        } else {
            PaymentService::reject($payment, $request->user(), (string) ($validated['remarks'] ?? ''));
        }

        return back()->with('success', 'Payment reviewed.');
    }

    /**
     * The full account number goes only to the roles that actually disburse.
     * Everyone else gets the last four, which is enough to match a claim
     * against a bank advice without the whole number sitting on screen.
     */
    private function accountNumberFor(RefundRequest $refund, Request $request): ?string
    {
        return $request->user()->role->seesBankDetails()
            ? $refund->account_number
            : $refund->maskedAccountNumber();
    }

    /**
     * Move a refund one stage along, or decline it.
     *
     * The two are one endpoint because the screen presents them as one
     * decision, but they take different paths in the service: advancing is
     * forward-only and validated against the pipeline, declining needs a
     * reason and is reachable from anywhere.
     */
    public function reviewRefund(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['advance', 'reject'])],
            // Required only when advancing: the screen sends the stage it
            // displayed, and RefundService checks it against the live one.
            'target' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'advance'),
                Rule::enum(RefundStatus::class),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'reject'),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        if ($validated['decision'] === 'advance') {
            $refund = RefundService::advance(
                $refundRequest,
                RefundStatus::from($validated['target']),
                $request->user(),
                $validated['notes'] ?? null,
            );

            return back()->with('success', "{$refund->request_code} moved to {$refund->status->label()}.");
        }

        RefundService::reject($refundRequest, $request->user(), $validated['rejection_reason']);

        return back()->with('success', 'Refund request declined.');
    }
}
