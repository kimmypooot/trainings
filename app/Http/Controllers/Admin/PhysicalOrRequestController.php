<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PhysicalOrRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\PhysicalOrRequest;
use App\Models\PhysicalOrSetting;
use App\Support\PhysicalOrRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue of physical-OR delivery requests. Admin and Super Admin move
 * requests along the pipeline and edit the GCash/delivery settings participants
 * are shown.
 */
class PhysicalOrRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $requests = PhysicalOrRequest::with([
            'payment.user', 'payment.training', 'verifier', 'reviewer', 'statusLogs.actor',
        ])
            // The verification queue defaults to the work in front of the
            // officer; every other status has to be asked for.
            ->when($status ?: PhysicalOrRequestStatus::PaymentVerificationPending->value, fn ($query, $s) => $query->where('status', $s))
            ->when($search, fn ($query, $s) => $query->where(function ($inner) use ($s) {
                $inner->where('request_code', 'like', "%{$s}%")
                    ->orWhere('tracking_number', 'like', "%{$s}%")
                    ->orWhereHas('payment.user', fn ($user) => $user->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('payment', fn ($payment) => $payment->where('or_number', 'like', "%{$s}%"));
            }))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // The chips count the whole queue while the rows below narrow — a chip
        // whose count shrank as the officer typed would read as "work disappeared".
        $counts = PhysicalOrRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $setting = PhysicalOrSetting::current();

        return Inertia::render('Admin/PhysicalOrRequests/Index', [
            'requests' => $requests->through(fn (PhysicalOrRequest $request) => [
                'id' => $request->id,
                'request_code' => $request->request_code,
                'participant' => $request->payment->user->name,
                'training' => $request->payment->training->title,
                'or_number' => $request->payment->or_number,
                'courier_fee' => $request->courier_fee,
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'proof_url' => $request->proof_path ? route('physical-or.proof', $request) : null,
                'notes' => $request->notes,
                'verified_by' => $request->verifier?->name,
                'reviewed_by' => $request->reviewer?->name,
                'courier_name' => $request->courier_name,
                'tracking_number' => $request->tracking_number,
                'rejection_reason' => $request->rejection_reason,
                'submitted_at' => $request->created_at->format('d M Y'),
                // Exactly one forward move is ever offered, so the screen
                // cannot put the pipeline out of order.
                'next_stage' => $request->status->next() === null ? null : [
                    'value' => $request->status->next()->value,
                    'label' => $request->status->next()->label(),
                ],
                'can_act' => $request->status->isOpen(),
                'trail' => $request->statusLogs->map(fn ($log) => [
                    'to' => $log->to_status->label(),
                    'notes' => $log->notes,
                    'actor' => $log->actor?->name ?? 'Participant',
                    'at' => $log->changed_at->format('d M Y, g:i A'),
                ])->all(),
            ]),
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'statuses' => PhysicalOrRequestStatus::options(),
            // The ordered stages, so the screen can draw the pipeline without
            // hardcoding a second copy of it.
            'pipeline' => collect(PhysicalOrRequestStatus::pipeline())
                ->map(fn (PhysicalOrRequestStatus $stage) => ['value' => $stage->value, 'label' => $stage->label()])
                ->all(),
            'settings' => [
                'gcash_number' => $setting->gcash_number,
                'account_name' => $setting->account_name,
                'courier_fee' => $setting->courier_fee,
                'instructions' => $setting->delivery_instructions,
            ],
        ]);
    }

    /**
     * Move a request one stage along, or decline it.
     *
     * The two are one endpoint because the screen presents them as one
     * decision, but they take different paths in the service: advancing is
     * forward-only and validated against the pipeline, declining needs a
     * reason and is reachable from anywhere.
     */
    public function review(Request $request, PhysicalOrRequest $physicalOrRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['advance', 'reject'])],
            // Required only when advancing: the screen sends the stage it
            // displayed, and PhysicalOrRequestService checks it against the live one.
            'target' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'advance'),
                Rule::enum(PhysicalOrRequestStatus::class),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Required when advancing to Shipped: the participant can only be
            // told "on its way" if there is something to actually track.
            'courier_name' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'advance'
                    && $request->input('target') === PhysicalOrRequestStatus::Shipped->value),
                'nullable', 'string', 'max:255',
            ],
            'tracking_number' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'advance'
                    && $request->input('target') === PhysicalOrRequestStatus::Shipped->value),
                'nullable', 'string', 'max:128',
            ],
            'rejection_reason' => [
                Rule::requiredIf(fn () => $request->input('decision') === 'reject'),
                'nullable', 'string', 'max:1000',
            ],
        ]);

        if ($validated['decision'] === 'advance') {
            $request = PhysicalOrRequestService::advance(
                $physicalOrRequest,
                PhysicalOrRequestStatus::from($validated['target']),
                $request->user(),
                $validated['notes'] ?? null,
                [
                    'courier_name' => $validated['courier_name'] ?? null,
                    'tracking_number' => $validated['tracking_number'] ?? null,
                ],
            );

            return back()->with('success', "{$request->request_code} moved to {$request->status->label()}.");
        }

        PhysicalOrRequestService::reject($physicalOrRequest, $request->user(), $validated['rejection_reason']);

        return back()->with('success', 'Physical OR request declined.');
    }

    /**
     * Save the GCash details and delivery instructions the participant's modal
     * renders. The one place these live is the settings row, so updating it is
     * updating every request form in one move.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gcash_number' => ['required', 'string', 'max:32'],
            'account_name' => ['required', 'string', 'max:255'],
            'courier_fee' => ['required', 'numeric', 'min:0.01'],
            'instructions' => ['required', 'string', 'max:2000'],
        ]);

        $setting = PhysicalOrSetting::current();

        $setting->forceFill([
            'gcash_number' => $validated['gcash_number'],
            'account_name' => $validated['account_name'],
            'courier_fee' => $validated['courier_fee'],
            'delivery_instructions' => $validated['instructions'],
            'updated_by' => $request->user()->getKey(),
        ])->save();

        return back()->with('success', 'Physical OR settings updated.');
    }
}
