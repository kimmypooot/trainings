<?php

namespace App\Http\Controllers;

use App\Enums\ChargeTo;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Training;
use App\Support\CancellationRequestService;
use App\Support\RegistrationService;
use App\Support\SupervisoryDocumentService;
use App\Support\SupervisoryEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistrationController extends Controller
{
    /**
     * The participant's own registrations.
     */
    public function index(Request $request): Response
    {
        $registrations = Registration::with(['training', 'cancellationRequests', 'outputs'])
            ->where('user_id', $request->user()->getKey())
            ->join('trainings', 'trainings.id', '=', 'registrations.training_id')
            ->orderByDesc('trainings.starts_at')
            ->select('registrations.*')
            ->get();

        return Inertia::render('My/Registrations', [
            'registrations' => $registrations->map(fn (Registration $registration) => [
                'id' => $registration->id,
                'status' => $registration->status->value,
                'registered_at' => $registration->registered_at->format('d M Y'),
                'can_withdraw' => $registration->status->isCancellable()
                    && ! $registration->hasPendingCancellation(),
                'withdrawal_pending' => $registration->hasPendingCancellation(),
                // The document verification state, so a participant knows their
                // proof is sitting in a review queue — and whether they have
                // been asked to fix it.
                'supervisory_document' => $registration->training->is_supervisory
                    && $registration->supervisory_document_status !== null
                        ? [
                            'status' => $registration->supervisory_document_status->value,
                            'status_label' => $registration->supervisory_document_status->label(),
                            'can_resubmit' => $registration->supervisory_document_status->allowsResubmission(),
                            'remarks' => $registration->supervisory_document_remarks,
                        ]
                        : null,
                /*
                 * Post-training deliverables, ported from v1's
                 * `submit-output.php`. A supervisory course is not finished
                 * when the sessions are: the participant owes an output, and
                 * HRD's request queue has been able to review them since the
                 * rewrite without anything on this side able to submit one.
                 *
                 * Offered only once the place is confirmed and the training has
                 * actually started — there is nothing to write up before then,
                 * and a pending registration may yet be refused.
                 */
                'output_submission' => $registration->training->is_supervisory
                    && in_array($registration->status, [
                        RegistrationStatus::Approved,
                        RegistrationStatus::Completed,
                    ], true)
                    && $registration->training->starts_at->isPast()
                        ? [
                            'submitted' => $registration->outputs->map(fn ($output) => [
                                'id' => $output->id,
                                'title' => $output->title,
                                'description' => $output->description,
                                'filename' => $output->original_filename,
                                'size' => $output->readableSize(),
                                'status' => $output->status->value,
                                'status_label' => $output->status->label(),
                                // The reviewer's note is the whole point when a
                                // submission comes back rejected.
                                'remarks' => $output->review_remarks,
                                'submitted_at' => $output->created_at?->format('d M Y'),
                                'download_url' => route('outputs.download', $output),
                            ])->values()->all(),
                        ]
                        : null,
                'training' => [
                    'title' => $registration->training->title,
                    'venue' => $registration->training->venue,
                    'venue_details' => $registration->training->venue_details,
                    'starts_at' => $registration->training->starts_at->format('d M Y, g:i A'),
                    'ends_at' => $registration->training->ends_at?->format('d M Y, g:i A'),
                    'mode' => $registration->training->mode->value,
                    'mode_label' => $registration->training->mode->label(),
                    'level_label' => $registration->training->level?->label(),
                    'category' => $registration->training->category,
                    'duration_days' => $registration->training->duration_days,
                    'payment_required' => $registration->training->payment_required,
                    'payment_amount' => $registration->training->payment_required
                        ? $registration->training->payment_amount
                        : null,
                    'description' => $registration->training->description,
                    'is_past' => $registration->training->starts_at->isPast(),
                    'url' => route('trainings.show', $registration->training->slug),
                ],
            ])->all(),
        ]);
    }

    /** Supporting documents are private, like every other participant upload. */
    public const DISK = 'local';

    /**
     * Register for a training.
     */
    public function store(Request $request, Training $training): RedirectResponse
    {
        $needsDocument = SupervisoryEligibility::requiresSupportingDocument($training, $request->user());

        $validated = $request->validate([
            'charge_to' => ['required', Rule::enum(ChargeTo::class)],
            'needs_certificate' => ['required', 'boolean'],
            'supporting_document' => [
                $needsDocument ? 'required' : 'nullable',
                'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],
        ]);

        RegistrationService::register($request->user(), $training, [
            'charge_to' => ChargeTo::from($validated['charge_to']),
            'needs_certificate' => $validated['needs_certificate'],
            'supporting_document_path' => $request->file('supporting_document')
                ?->store('supporting-documents', self::DISK),
        ]);

        return back()->with(
            'success',
            "Your registration for {$training->title} has been submitted and is awaiting approval by CSC."
        );
    }

    /**
     * The supporting document behind a registration — the participant who
     * uploaded it, and the staff who decide on it.
     */
    public function supportingDocument(Request $request, Registration $registration): StreamedResponse
    {
        abort_unless($registration->supporting_document_path !== null, 404);

        $isOwner = $registration->user_id === $request->user()->getKey();

        abort_unless($isOwner || $request->user()->role->isStaff(), 403);

        return Storage::disk(self::DISK)->download($registration->supporting_document_path);
    }

    /**
     * Re-upload a rejected (or missing) supervisory document.
     *
     * Only the participant who owns the registration may do this, and only
     * while the workflow allows a replacement. A fresh file goes straight back
     * into the verification queue.
     */
    public function resubmitDocument(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);

        $registration->loadMissing('training');

        abort_unless($registration->training->is_supervisory, 404);

        $validated = $request->validate([
            'supporting_document' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);

        $path = $request->file('supporting_document')->store('supporting-documents', self::DISK);

        SupervisoryDocumentService::resubmit($registration, $path);

        return back()->with(
            'success',
            'Your supporting document has been re-uploaded and is awaiting verification.'
        );
    }

    /**
     * Ask to withdraw from a training.
     *
     * The slot is held until CSC decides — catering and materials are ordered
     * against a confirmed head count, so a withdrawal is a request, not a
     * unilateral act.
     */
    public function destroy(Request $request, Registration $registration): RedirectResponse
    {
        abort_unless($registration->user_id === $request->user()->getKey(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        CancellationRequestService::open($registration, $validated['reason']);

        return back()->with(
            'success',
            'Your withdrawal request has been submitted. Your slot is held until CSC reviews it.'
        );
    }
}
