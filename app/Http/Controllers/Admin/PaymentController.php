<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Support\PaymentService;
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
        $payments = Payment::with(['user', 'training', 'verifier'])
            ->when(
                $request->string('status')->toString() ?: PaymentStatus::Pending->value,
                fn ($query, $status) => $query->where('status', $status)
            )
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $refunds = RefundRequest::with(['payment.user', 'payment.training'])
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
                'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            ]),
            'refunds' => $refunds->map(fn (RefundRequest $refund) => [
                'id' => $refund->id,
                'participant' => $refund->payment->user->name,
                'training' => $refund->payment->training->title,
                'amount' => $refund->amount,
                'reason' => $refund->reason,
                'status' => $refund->status->value,
                'status_label' => $refund->status->label(),
                'review_remarks' => $refund->review_remarks,
                'submitted_at' => $refund->created_at->format('d M Y'),
            ])->all(),
            'filters' => ['status' => $request->string('status')->toString()],
            'statuses' => PaymentStatus::options(),
        ]);
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([PaymentStatus::Verified->value, PaymentStatus::Rejected->value])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['decision'] === PaymentStatus::Verified->value) {
            PaymentService::verify($payment, $request->user(), $validated['remarks'] ?? null);
        } else {
            PaymentService::reject($payment, $request->user(), (string) ($validated['remarks'] ?? ''));
        }

        return back()->with('success', 'Payment reviewed.');
    }

    public function reviewRefund(Request $request, RefundRequest $refundRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([RequestStatus::Approved->value, RequestStatus::Rejected->value])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        PaymentService::reviewRefund(
            $refundRequest,
            RequestStatus::from($validated['decision']),
            $request->user(),
            $validated['remarks'] ?? null
        );

        return back()->with('success', 'Refund request reviewed.');
    }
}
