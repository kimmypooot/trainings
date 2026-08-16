<?php

namespace App\Http\Controllers;

use App\Enums\PhysicalOrRequestStatus;
use App\Models\Payment;
use App\Models\PhysicalOrRequest;
use App\Models\PhysicalOrSetting;
use App\Support\PhysicalOrRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The participant's side of physical-OR delivery: filing a request, attaching
 * the GCash proof, and watching it move to the courier.
 */
class PhysicalOrRequestController extends Controller
{
    /** Proof of the courier fee never touches a public disk. */
    public const DISK = 'local';

    public function index(Request $request): Response
    {
        $requests = PhysicalOrRequest::with(['payment.training', 'statusLogs'])
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->get();

        $setting = PhysicalOrSetting::current();

        return Inertia::render('My/PhysicalOrRequests', [
            'requests' => $requests->map(fn (PhysicalOrRequest $r) => $this->payload($r))->all(),
            'pipeline' => collect(PhysicalOrRequestStatus::pipeline())
                ->map(fn (PhysicalOrRequestStatus $stage) => ['value' => $stage->value, 'label' => $stage->label()])
                ->all(),
            'gcash' => [
                'number' => $setting->gcash_number,
                'account_name' => $setting->account_name,
                'courier_fee' => $setting->courier_fee,
                'instructions' => $setting->delivery_instructions,
            ],
        ]);
    }

    /**
     * A request as the participant sees it: where it is, what the courier was,
     * and whether the participant still has a move left (upload or cancel).
     *
     * @return array<string, mixed>
     */
    private function payload(PhysicalOrRequest $request): array
    {
        $reached = $request->statusLogs->pluck('to_status');

        return [
            'id' => $request->id,
            'request_code' => $request->request_code,
            'courier_fee' => $request->courier_fee,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'message' => $request->status->participantMessage(),
            'rejection_reason' => $request->rejection_reason,
            'can_upload_proof' => $request->status === PhysicalOrRequestStatus::RequestSubmitted,
            'can_cancel' => in_array($request->status, [
                PhysicalOrRequestStatus::RequestSubmitted,
                PhysicalOrRequestStatus::PaymentVerificationPending,
            ], true),
            'courier_name' => $request->courier_name,
            'tracking_number' => $request->tracking_number,
            'shipped_at' => $request->shipped_at?->format('d M Y'),
            'delivered_at' => $request->delivered_at?->format('d M Y'),
            'proof_url' => $request->proof_path ? route('physical-or.proof', $request) : null,
            'payment' => [
                'or_number' => $request->payment->or_number,
                'training' => $request->payment->training->title,
            ],
            // A declined request never reaches the later stages, so the track
            // is suppressed entirely rather than shown as permanently stalled.
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
     * File a request for a physical copy of a verified receipt.
     *
     * The proof is optional at filing time: the modal asks the participant to
     * pay the courier fee and attach the screenshot in one go, but someone who
     * cannot pay that minute should still be able to submit and pay later —
     * the request is what starts the clock.
     */
    public function store(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'proof' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        PhysicalOrRequestService::request(
            $payment,
            $request->user(),
            $request->hasFile('proof')
                ? $request->file('proof')->store('physical-or-proofs', self::DISK)
                : null,
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Your physical receipt request has been submitted.');
    }

    /** Attach the GCash proof to a request filed without one. */
    public function uploadProof(Request $request, PhysicalOrRequest $physicalOrRequest): RedirectResponse
    {
        abort_unless($physicalOrRequest->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'proof' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        PhysicalOrRequestService::uploadProof(
            $physicalOrRequest,
            $request->user(),
            $request->file('proof')->store('physical-or-proofs', self::DISK),
        );

        return back()->with('success', 'Your courier fee proof has been uploaded.');
    }

    /** Withdraw a request that has not yet been paid and verified. */
    public function cancel(Request $request, PhysicalOrRequest $physicalOrRequest): RedirectResponse
    {
        abort_unless($physicalOrRequest->user_id === $request->user()->getKey(), 403);

        PhysicalOrRequestService::cancel($physicalOrRequest, $request->user());

        return back()->with('success', 'Your request has been cancelled.');
    }

    /**
     * The courier fee proof, for the participant who filed it and the officers
     * who verify it. Same rule as payment proof — never a public URL.
     */
    public function proof(Request $request, PhysicalOrRequest $physicalOrRequest): StreamedResponse
    {
        abort_unless($physicalOrRequest->proof_path !== null, 404);

        $isOwner = $physicalOrRequest->user_id === $request->user()->getKey();

        abort_unless($isOwner || $request->user()->role->handlesPhysicalOrRequests(), 403);

        return Storage::disk(self::DISK)->download(
            $physicalOrRequest->proof_path,
            null,
            ['Content-Disposition' => 'inline; filename="courier-fee-proof"'],
        );
    }
}
