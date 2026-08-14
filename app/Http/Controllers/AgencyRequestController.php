<?php

namespace App\Http\Controllers;

use App\Enums\AgencyDocumentKind;
use App\Enums\AgencyRequestStatus;
use App\Models\AgencyRequest;
use App\Models\AgencyRequestDocument;
use App\Support\AgencyRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The agency's side of a training request, ported from v1's
 * `participant/training-requests.php`.
 *
 * Distinct from TrainingRequestController, which is the suggestion box — a
 * participant proposing a topic for CSC to run regionally. This is an agency
 * formally asking CSC to conduct a training for its own staff, and the document
 * exchange that follows.
 */
class AgencyRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $requests = AgencyRequest::with(['documents.uploader', 'assignee'])
            ->where('requested_by', $request->user()->getKey())
            ->latest()
            ->get();

        return Inertia::render('My/AgencyRequests', [
            'requests' => $requests->map(fn (AgencyRequest $agencyRequest) => self::present($agencyRequest))->all(),
            'defaultAgency' => $request->user()->profile?->organization_name,
            'completionKinds' => array_map(
                fn (AgencyDocumentKind $kind) => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'required' => in_array($kind, AgencyDocumentKind::requiredForCompletion(), true),
                ],
                [
                    AgencyDocumentKind::CertificateOfDuties,
                    AgencyDocumentKind::AttendanceSheet,
                    AgencyDocumentKind::AttendanceList,
                    AgencyDocumentKind::ProofOfPayment,
                ],
            ),
        ]);
    }

    /**
     * The shape both sides render from.
     *
     * @return array<string, mixed>
     */
    public static function present(AgencyRequest $agencyRequest): array
    {
        return [
            'id' => $agencyRequest->id,
            'request_code' => $agencyRequest->request_code,
            'agency_name' => $agencyRequest->agency_name,
            'training_title' => $agencyRequest->training_title,
            'proposed_start' => $agencyRequest->proposed_start->format('d M Y'),
            'proposed_end' => $agencyRequest->proposed_end->format('d M Y'),
            'proposed_venue' => $agencyRequest->proposed_venue,
            'expected_participants' => $agencyRequest->expected_participants,
            'status' => $agencyRequest->status->value,
            'status_label' => $agencyRequest->status->label(),
            'message' => $agencyRequest->status->requesterMessage(),
            'requirements_text' => $agencyRequest->requirements_text,
            'confirmed_start' => $agencyRequest->confirmed_start?->format('d M Y'),
            'confirmed_end' => $agencyRequest->confirmed_end?->format('d M Y'),
            'confirmed_venue' => $agencyRequest->confirmed_venue,
            'payment_amount' => $agencyRequest->payment_amount,
            'payment_verified_at' => $agencyRequest->payment_verified_at?->format('d M Y'),
            'rejection_reason' => $agencyRequest->rejection_reason,
            'cancellation_reason' => $agencyRequest->cancellation_reason,
            'submitted_at' => $agencyRequest->created_at->format('d M Y'),
            'assigned_to' => $agencyRequest->assignee?->name,
            'ord_notified' => $agencyRequest->ord_notified_at !== null,
            'completion_submitted' => $agencyRequest->completion_submitted_at !== null,
            // Drives which form the screen offers, so the two sides can never
            // disagree about whose move it is.
            'can_confirm' => $agencyRequest->status->awaitsRequester(),
            'can_submit_completion' => $agencyRequest->status === AgencyRequestStatus::Confirmed,
            // Not offered once confirmed: by then CSC has committed to the run.
            'can_cancel' => $agencyRequest->status->isOpen()
                && $agencyRequest->status !== AgencyRequestStatus::Confirmed,
            'is_open' => $agencyRequest->status->isOpen(),
            'stages' => array_map(
                fn (AgencyRequestStatus $stage) => [
                    'label' => $stage->label(),
                    'reached' => $agencyRequest->status->hasReached($stage),
                ],
                AgencyRequestStatus::pipeline(),
            ),
            'documents' => $agencyRequest->documents->map(fn (AgencyRequestDocument $document) => [
                'id' => $document->id,
                'kind' => $document->kind->value,
                'kind_label' => $document->kind->label(),
                'filename' => $document->original_filename,
                'size' => $document->readableSize(),
                'uploaded_by' => $document->uploader?->name,
                'uploaded_at' => $document->created_at->format('d M Y'),
                'url' => route('agency-requests.documents.download', $document),
            ])->all(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agency_name' => ['nullable', 'string', 'max:255'],
            'training_title' => ['required', 'string', 'max:255'],
            'proposed_start' => ['required', 'date', 'after_or_equal:today'],
            'proposed_end' => ['required', 'date', 'after_or_equal:proposed_start'],
            'proposed_venue' => ['required', 'string', 'max:255'],
            'expected_participants' => ['nullable', 'integer', 'min:1', 'max:10000'],
            // The letter is what makes it a formal request rather than an
            // enquiry, so it cannot follow later.
            'request_letter' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx'],
        ]);

        AgencyRequestService::submit(
            $request->user(),
            [
                'agency_name' => $validated['agency_name'] ?? null,
                'training_title' => $validated['training_title'],
                'proposed_start' => $validated['proposed_start'],
                'proposed_end' => $validated['proposed_end'],
                'proposed_venue' => $validated['proposed_venue'],
                'expected_participants' => $validated['expected_participants'] ?? null,
            ],
            $request->file('request_letter'),
        );

        return back()->with('success', 'Your request has been submitted to CSC HRD.');
    }

    public function storeConfirmation(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        abort_unless($agencyRequest->requested_by === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'confirmed_start' => ['required', 'date'],
            'confirmed_end' => ['required', 'date', 'after_or_equal:confirmed_start'],
            'confirmed_venue' => ['required', 'string', 'max:255'],
            'signed_confirmation_form' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        AgencyRequestService::submitConfirmation(
            $agencyRequest,
            $request->user(),
            $validated,
            $request->file('signed_confirmation_form'),
        );

        return back()->with('success', 'Your confirmation has been recorded.');
    }

    public function storeCompletion(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        abort_unless($agencyRequest->requested_by === $request->user()->getKey(), 403);

        $file = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx'];

        $request->validate([
            // Individually nullable so a resubmission can supply only what was
            // missing; the service is what enforces the complete set.
            AgencyDocumentKind::CertificateOfDuties->value => $file,
            AgencyDocumentKind::AttendanceSheet->value => $file,
            AgencyDocumentKind::AttendanceList->value => $file,
            AgencyDocumentKind::ProofOfPayment->value => $file,
            'payment_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $agencyRequest = AgencyRequestService::submitCompletion(
            $agencyRequest,
            $request->user(),
            [
                AgencyDocumentKind::CertificateOfDuties->value => $request->file(AgencyDocumentKind::CertificateOfDuties->value),
                AgencyDocumentKind::AttendanceSheet->value => $request->file(AgencyDocumentKind::AttendanceSheet->value),
                AgencyDocumentKind::AttendanceList->value => $request->file(AgencyDocumentKind::AttendanceList->value),
                AgencyDocumentKind::ProofOfPayment->value => $request->file(AgencyDocumentKind::ProofOfPayment->value),
            ],
            $request->filled('payment_amount') ? (float) $request->input('payment_amount') : null,
        );

        // A partial upload is kept rather than refused, so this reports what is
        // still outstanding instead of pretending the submission is done.
        $missing = $agencyRequest->missingCompletionDocuments();

        if ($missing !== []) {
            return back()->with('success', 'Saved. Still needed: '.implode(', ', array_map(
                fn (AgencyDocumentKind $kind) => $kind->label(),
                $missing,
            )).'.');
        }

        return back()->with('success', 'Your post-training documents have been submitted for verification.');
    }

    public function cancel(Request $request, AgencyRequest $agencyRequest): RedirectResponse
    {
        abort_unless($agencyRequest->requested_by === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        AgencyRequestService::cancel($agencyRequest, $request->user(), $validated['reason']);

        return back()->with('success', 'Your request has been withdrawn.');
    }

    /**
     * Documents are private. The agency that filed the request and CSC staff,
     * nobody else.
     */
    public function download(Request $request, AgencyRequestDocument $document): StreamedResponse
    {
        $document->loadMissing('agencyRequest');

        $isOwner = $document->agencyRequest->requested_by === $request->user()->getKey();

        abort_unless($isOwner || $request->user()->role->isStaff(), 403);

        return AgencyRequestService::download($document);
    }
}
