<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgencyRequestStatus;
use App\Http\Controllers\AgencyRequestController as ParticipantController;
use App\Http\Controllers\Controller;
use App\Models\AgencyRequest;
use App\Support\AgencyRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HRD's side of the agency-request correspondence, ported from v1's
 * `admin/hrd/agency-requested.php` and `training_request_actions.php`.
 */
class AgencyRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString() ?: 'ours';

        $requests = AgencyRequest::with(['documents.uploader', 'assignee', 'requester'])
            // "Waiting on us" first by default. HRD's real complaint about v1
            // was chasing requests that were never theirs to move — a queue
            // that mixes the two is a queue nobody trusts.
            ->when($filter === 'ours', fn ($query) => $query->awaitingStaff())
            ->when($filter === 'theirs', fn ($query) => $query->where('status', AgencyRequestStatus::RequirementsSent))
            ->when($filter === 'open', fn ($query) => $query->open())
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/AgencyRequests/Index', [
            'requests' => $requests->through(fn (AgencyRequest $agencyRequest) => [
                ...ParticipantController::present($agencyRequest),
                'requester' => $agencyRequest->requester?->name,
                'requester_email' => $agencyRequest->requester?->email,
                // Whose move it is, which is what the queue is sorted around.
                'awaits_staff' => $agencyRequest->status->awaitsStaff(),
                'awaits_requester' => $agencyRequest->status->awaitsRequester(),
                'can_assign' => $agencyRequest->status->isOpen() && $agencyRequest->assigned_to === null,
                'can_send_requirements' => in_array($agencyRequest->status, [
                    AgencyRequestStatus::Pending,
                    AgencyRequestStatus::UnderReview,
                ], true),
                'can_verify_payment' => $agencyRequest->completion_submitted_at !== null
                    && $agencyRequest->status !== AgencyRequestStatus::Completed,
                'can_notify_ord' => $agencyRequest->ord_notified_at === null
                    && $agencyRequest->status->isOpen(),
                'can_reject' => $agencyRequest->status->isOpen(),
                'missing_documents' => array_map(
                    fn ($kind) => $kind->label(),
                    $agencyRequest->missingCompletionDocuments(),
                ),
            ]),
            'filters' => ['filter' => $filter],
            'counts' => [
                'ours' => AgencyRequest::awaitingStaff()->count(),
                'theirs' => AgencyRequest::where('status', AgencyRequestStatus::RequirementsSent)->count(),
                'open' => AgencyRequest::open()->count(),
            ],
        ]);
    }

    public function assign(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        AgencyRequestService::assign($agencyRequest, $request->user());

        return back()->with('success', "{$agencyRequest->request_code} is now yours.");
    }

    public function notifyOrd(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        AgencyRequestService::notifyOrd($agencyRequest, $request->user());

        return back()->with('success', 'Recorded as notified to the ORD.');
    }

    public function sendRequirements(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        $validated = $request->validate([
            'requirements_text' => ['required', 'string', 'min:10', 'max:5000'],
            'response_letter' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
            // Optional: some requests need no form back, only the letter.
            'blank_confirmation_form' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
        ]);

        AgencyRequestService::sendRequirements(
            $agencyRequest,
            $request->user(),
            $validated['requirements_text'],
            $request->file('response_letter'),
            $request->file('blank_confirmation_form'),
        );

        return back()->with('success', "Requirements sent for {$agencyRequest->request_code}.");
    }

    public function verifyPayment(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        AgencyRequestService::verifyPayment($agencyRequest, $request->user(), $validated['notes'] ?? null);

        return back()->with('success', "{$agencyRequest->request_code} completed.");
    }

    public function reject(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'rejection_letter' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
        ]);

        AgencyRequestService::reject(
            $agencyRequest,
            $request->user(),
            $validated['reason'],
            $request->file('rejection_letter'),
        );

        return back()->with('success', "{$agencyRequest->request_code} declined.");
    }
}
