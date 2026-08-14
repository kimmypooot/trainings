<?php

namespace App\Support;

use App\Enums\AgencyDocumentKind;
use App\Enums\AgencyRequestStatus;
use App\Models\AgencyRequest;
use App\Models\AgencyRequestDocument;
use App\Models\User;
use App\Notifications\AgencyRequestUpdated;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The agency-request correspondence, ported from v1's
 * `training_request_actions.php` and the participant half of
 * `participant/training-requests.php`.
 *
 * Each method is one move in the exchange, and every one of them is guarded by
 * the status it is legal from. v1 had an `update_status` action that wrote any
 * status over any other with no check at all, which is how requests ended up
 * `completed` with no completion documents attached — the screen simply offered
 * the option and the endpoint took it.
 *
 * Documents are never deleted. Re-uploading a kind supersedes it for display
 * while the earlier version stays on record, because "they sent the wrong form
 * first" is the kind of thing that gets disputed months later.
 */
class AgencyRequestService
{
    /** Agency letters and payment records: private disk, always. */
    public const DISK = 'local';

    /**
     * File an agency request. The letter of request is what makes it real, so
     * it is required rather than something that can follow later.
     */
    public static function submit(User $requester, array $attributes, UploadedFile $letter): AgencyRequest
    {
        $request = DB::transaction(function () use ($requester, $attributes, $letter) {
            $created = AgencyRequest::create([
                'request_code' => AgencyRequest::nextRequestCode(),
                'requested_by' => $requester->getKey(),
                // Falls back to the account name so a request is never filed
                // against a blank agency, even from a thin profile.
                'agency_name' => $attributes['agency_name']
                    ?: ($requester->profile?->organization_name ?? $requester->name),
                'training_title' => $attributes['training_title'],
                'proposed_start' => $attributes['proposed_start'],
                'proposed_end' => $attributes['proposed_end'],
                'proposed_venue' => $attributes['proposed_venue'],
                'expected_participants' => $attributes['expected_participants'] ?? null,
                'status' => AgencyRequestStatus::Pending,
            ]);

            self::attach($created, AgencyDocumentKind::RequestLetter, $letter, $requester);

            ActivityLogger::record(
                'agency-request.submitted',
                $created,
                "{$created->request_code}: “{$created->training_title}” requested by {$created->agency_name}.",
                ['agency' => $created->agency_name],
                $requester,
            );

            return $created;
        });

        return $request;
    }

    /**
     * Record that the Office of the Regional Director has been told.
     *
     * Idempotent by refusal rather than by silence: sending the ORD a second
     * notification for the same request is a small embarrassment, and an
     * endpoint that quietly does nothing hides the double-click that caused it.
     */
    public static function notifyOrd(AgencyRequest $request, User $staff): AgencyRequest
    {
        if ($request->ord_notified_at !== null) {
            throw ValidationException::withMessages([
                'ord' => 'The ORD has already been notified for this request.',
            ]);
        }

        $request->forceFill(['ord_notified_at' => now()])->save();

        ActivityLogger::record(
            'agency-request.ord-notified',
            $request,
            "{$request->request_code}: ORD notified.",
            [],
            $staff,
        );

        return $request;
    }

    /**
     * Take ownership. One assignee at a time — two people writing to the same
     * agency is worse than nobody doing it.
     */
    public static function assign(AgencyRequest $request, User $staff): AgencyRequest
    {
        $request = DB::transaction(function () use ($request, $staff) {
            $locked = AgencyRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->assigned_to !== null && $locked->assigned_to !== $staff->getKey()) {
                throw ValidationException::withMessages([
                    'assignment' => 'This request is already assigned to someone else.',
                ]);
            }

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'assignment' => "A {$locked->status->label()} request cannot be picked up.",
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'assigned_to' => $staff->getKey(),
                'assigned_at' => now(),
                // Only advances a brand-new request; picking up one already
                // further along must not drag it backwards.
                'status' => $from === AgencyRequestStatus::Pending
                    ? AgencyRequestStatus::UnderReview
                    : $from,
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.assigned',
                $locked,
                $from,
                $locked->status,
                "{$locked->request_code}: picked up by {$staff->name}.",
                [],
                $staff,
            );

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Reply with the requirements: the response letter, optionally a blank
     * confirmation form for the agency to sign and return, and the text of what
     * is being asked for.
     */
    public static function sendRequirements(
        AgencyRequest $request,
        User $staff,
        string $requirementsText,
        UploadedFile $responseLetter,
        ?UploadedFile $blankForm = null,
    ): AgencyRequest {
        $request = DB::transaction(function () use ($request, $staff, $requirementsText, $responseLetter, $blankForm) {
            $locked = AgencyRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [AgencyRequestStatus::Pending, AgencyRequestStatus::UnderReview], true)) {
                throw ValidationException::withMessages([
                    'status' => "Requirements cannot be sent for a {$locked->status->label()} request.",
                ]);
            }

            $from = $locked->status;

            self::attach($locked, AgencyDocumentKind::ResponseLetter, $responseLetter, $staff);

            if ($blankForm !== null) {
                self::attach($locked, AgencyDocumentKind::BlankConfirmationForm, $blankForm, $staff);
            }

            $locked->forceFill([
                'requirements_text' => $requirementsText,
                'requirements_sent_at' => now(),
                'status' => AgencyRequestStatus::RequirementsSent,
                // Sending the requirements is taking the request on, so an
                // unassigned one becomes this officer's.
                'assigned_to' => $locked->assigned_to ?? $staff->getKey(),
                'assigned_at' => $locked->assigned_at ?? now(),
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.requirements-sent',
                $locked,
                $from,
                AgencyRequestStatus::RequirementsSent,
                "{$locked->request_code}: requirements sent by {$staff->name}.",
                [],
                $staff,
            );

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * The agency returns the signed confirmation form, with the dates and venue
     * as actually agreed.
     */
    public static function submitConfirmation(
        AgencyRequest $request,
        User $requester,
        array $attributes,
        UploadedFile $signedForm,
    ): AgencyRequest {
        $request = DB::transaction(function () use ($request, $requester, $attributes, $signedForm) {
            $locked = AgencyRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AgencyRequestStatus::RequirementsSent) {
                throw ValidationException::withMessages([
                    'status' => 'A confirmation can only be returned once HRD has sent the requirements.',
                ]);
            }

            self::attach($locked, AgencyDocumentKind::SignedConfirmationForm, $signedForm, $requester);

            $locked->forceFill([
                // Kept beside the proposed dates rather than overwriting them:
                // the gap between what was asked for and what was agreed is
                // exactly what gets queried later.
                'confirmed_start' => $attributes['confirmed_start'],
                'confirmed_end' => $attributes['confirmed_end'],
                'confirmed_venue' => $attributes['confirmed_venue'],
                'confirmed_at' => now(),
                'status' => AgencyRequestStatus::Confirmed,
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.confirmed',
                $locked,
                AgencyRequestStatus::RequirementsSent,
                AgencyRequestStatus::Confirmed,
                "{$locked->request_code}: confirmation returned by {$locked->agency_name}.",
                [
                    'confirmed_start' => $attributes['confirmed_start'],
                    'confirmed_end' => $attributes['confirmed_end'],
                ],
                $requester,
            );

            return $locked;
        });

        return $request;
    }

    /**
     * The agency submits the post-training documents.
     *
     * Whatever is supplied is kept, even when the set is incomplete. Agencies
     * gather these over days — the certificate of duties comes from one office
     * and the proof of payment from another — and rejecting a partial upload
     * wholesale would throw away files the agency had already found and make
     * them start over. `completion_submitted_at` is what marks the set as
     * actually complete, so an incomplete upload simply does not set it.
     *
     * This does not complete the *request* either — HRD still has to verify the
     * payment. v1 let the agency's own submission set `completed`, which meant
     * they effectively signed off on their own payment.
     *
     * @param  array<string, UploadedFile|null>  $documents  Keyed by AgencyDocumentKind value.
     */
    public static function submitCompletion(
        AgencyRequest $request,
        User $requester,
        array $documents,
        ?float $paymentAmount = null,
    ): AgencyRequest {
        return DB::transaction(function () use ($request, $requester, $documents, $paymentAmount) {
            $locked = AgencyRequest::with('documents')
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== AgencyRequestStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Post-training documents can only be submitted for a confirmed request.',
                ]);
            }

            foreach ($documents as $kind => $file) {
                if ($file instanceof UploadedFile) {
                    self::attach($locked, AgencyDocumentKind::from($kind), $file, $requester);
                }
            }

            $locked->load('documents');

            // Assessed against everything the request now holds, not just this
            // upload, so a follow-up supplying the last missing piece completes
            // the set.
            $complete = $locked->missingCompletionDocuments() === [];

            $locked->forceFill([
                'completion_submitted_at' => $complete ? now() : null,
                'payment_amount' => $paymentAmount ?? $locked->payment_amount,
            ])->save();

            ActivityLogger::record(
                $complete
                    ? 'agency-request.completion-submitted'
                    : 'agency-request.completion-partial',
                $locked,
                $complete
                    ? "{$locked->request_code}: post-training documents submitted."
                    : "{$locked->request_code}: partial post-training documents received.",
                ['payment_amount' => $paymentAmount, 'complete' => $complete],
                $requester,
            );

            return $locked;
        });
    }

    /**
     * HRD verifies the payment, which is what actually closes the request.
     */
    public static function verifyPayment(AgencyRequest $request, User $staff, ?string $notes = null): AgencyRequest
    {
        $request = DB::transaction(function () use ($request, $staff, $notes) {
            $locked = AgencyRequest::with('documents')
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->completion_submitted_at === null) {
                throw ValidationException::withMessages([
                    'payment' => 'The post-training documents have not been submitted yet.',
                ]);
            }

            if ($locked->status === AgencyRequestStatus::Completed) {
                throw ValidationException::withMessages([
                    'payment' => 'This request has already been completed.',
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'payment_verified_by' => $staff->getKey(),
                'payment_verified_at' => now(),
                'status' => AgencyRequestStatus::Completed,
                'closed_at' => now(),
                'review_notes' => $notes ?? $locked->review_notes,
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.completed',
                $locked,
                $from,
                AgencyRequestStatus::Completed,
                "{$locked->request_code}: payment verified by {$staff->name}.",
                ['payment_amount' => (float) $locked->payment_amount],
                $staff,
            );

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Decline the request. Reachable from any open stage — HRD may find out
     * late that a training cannot be run, and a request with no way to close
     * sits in the queue forever.
     */
    public static function reject(
        AgencyRequest $request,
        User $staff,
        string $reason,
        ?UploadedFile $rejectionLetter = null,
    ): AgencyRequest {
        $request = DB::transaction(function () use ($request, $staff, $reason, $rejectionLetter) {
            $locked = AgencyRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => "This request is already {$locked->status->label()}.",
                ]);
            }

            $from = $locked->status;

            if ($rejectionLetter !== null) {
                self::attach($locked, AgencyDocumentKind::RejectionLetter, $rejectionLetter, $staff);
            }

            $locked->forceFill([
                'status' => AgencyRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'closed_at' => now(),
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.rejected',
                $locked,
                $from,
                AgencyRequestStatus::Rejected,
                "{$locked->request_code}: declined by {$staff->name}.",
                ['reason' => $reason],
                $staff,
            );

            return $locked;
        });

        self::notify($request);

        return $request;
    }

    /**
     * Withdraw the request. The agency's own move, so it is not available once
     * the training has been confirmed — by then CSC has committed to it.
     */
    public static function cancel(AgencyRequest $request, User $actor, string $reason): AgencyRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $locked = AgencyRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => "This request is already {$locked->status->label()}.",
                ]);
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => AgencyRequestStatus::Cancelled,
                'cancellation_reason' => $reason,
                'closed_at' => now(),
            ])->save();

            ActivityLogger::recordTransition(
                'agency-request.cancelled',
                $locked,
                $from,
                AgencyRequestStatus::Cancelled,
                "{$locked->request_code}: withdrawn.",
                ['reason' => $reason],
                $actor,
            );

            return $locked;
        });
    }

    /**
     * Store one document against a request.
     */
    private static function attach(
        AgencyRequest $request,
        AgencyDocumentKind $kind,
        UploadedFile $file,
        User $uploader,
    ): AgencyRequestDocument {
        return AgencyRequestDocument::create([
            'agency_request_id' => $request->getKey(),
            'kind' => $kind,
            'file_path' => $file->store("agency-requests/{$request->getKey()}", self::DISK),
            // The agency's own filename, kept for the download so a letter
            // comes back named as they sent it rather than as a hash.
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'uploaded_by' => $uploader->getKey(),
            'created_at' => now(),
        ]);
    }

    private static function notify(AgencyRequest $request): void
    {
        $request->loadMissing('requester');
        $request->requester?->notify(new AgencyRequestUpdated($request));
    }

    /** Read a stored document back. */
    public static function download(AgencyRequestDocument $document)
    {
        return Storage::disk(self::DISK)->download(
            $document->file_path,
            $document->original_filename,
        );
    }
}
