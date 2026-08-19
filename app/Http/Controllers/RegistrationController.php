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
        // payments rides along because hasSettledFee() reads it, and the join
        // link below asks that of every row — without it this is one query per
        // registration.
        $registrations = Registration::with(['training', 'cancellationRequests', 'outputs', 'payments'])
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
                // Lets the dialog name the one thing still standing between the
                // participant and the join link, rather than a blanket "not yet".
                'fee_settled' => $registration->hasSettledFee(),
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
                    /*
                     * The rest of the prose, so the list can open a training in
                     * a dialog rather than sending the participant to the detail
                     * page for it.
                     *
                     * Shipped outright rather than fetched on demand the way the
                     * catalogue does it. The catalogue paginates and would carry
                     * a dozen unopened descriptions per page; a participant's own
                     * registrations are a handful, already loaded whole, and
                     * already carry the description — so the lazy machinery would
                     * buy a round trip and nothing else.
                     */
                    'training_code' => $registration->training->training_code,
                    'target_participants' => $registration->training->target_participants,
                    'prerequisites' => $registration->training->prerequisites,
                    'is_supervisory' => $registration->training->is_supervisory,
                    /*
                     * The join link, on the same terms Trainings\Show grants it.
                     *
                     * Withheld on the server rather than sent and hidden in the
                     * page: an Inertia payload is plain JSON in the response
                     * body, so a link shipped here is readable whatever the
                     * template does with it. Only the boolean crosses the wire
                     * when the link itself does not, which is what lets the
                     * dialog say a link exists without disclosing it.
                     */
                    'meeting_link' => $registration->mayViewMeetingLink()
                        ? $registration->training->meeting_link
                        : null,
                    'has_meeting_link' => filled($registration->training->meeting_link),
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
            // No longer asked at registration — everyone attending gets a
            // certificate, which is what the column has always defaulted to.
            // Kept accepted rather than rejected so the roster tooling and the
            // walk-in path, which do set it deliberately, keep working.
            'needs_certificate' => ['sometimes', 'boolean'],
            'supporting_document' => [
                $needsDocument ? 'required' : 'nullable',
                'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx',
            ],
        ]);

        RegistrationService::register($request->user(), $training, [
            'charge_to' => ChargeTo::from($validated['charge_to']),
            'needs_certificate' => $validated['needs_certificate'] ?? true,
            'supporting_document_path' => $request->file('supporting_document')
                ?->store('supporting-documents', self::DISK),
        ]);

        /*
         * Say what actually happened, which is not the same sentence twice.
         *
         * RegistrationService approves a free run outright — there is no fee to
         * settle and nothing to queue behind — so telling those participants to
         * await an approval that already happened left them watching for a
         * decision that was never coming. They stay where they are.
         *
         * A paid run is not finished here. The slot is held at pending until
         * PaymentService::confirmSlotOnSettlement sees the fee settled, and the
         * settling is done on the payments page — so that is where the
         * participant is taken, rather than being told about a page they then
         * have to go and find. The registration just made is already the first
         * row of that page's Awaiting Payment list (it occupies a slot and has
         * no payment against it yet), so the landing is on the very thing they
         * were sent to do.
         */
        if (! $training->payment_required) {
            return back()->with('success', "You are registered for {$training->title}.");
        }

        return redirect()->route('payments.index')->with(
            'success',
            "Your registration for {$training->title} has been submitted. Settle the ₱"
                .number_format((float) $training->payment_amount, 2)
                .' fee below to confirm your slot.'
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
