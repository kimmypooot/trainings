<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Support\UndoService;
use Illuminate\Http\Request;

/**
 * The three things every roster decision does, shared by the screen that makes
 * them one at a time and the one that makes them in bulk.
 *
 * These lived as private methods on Admin\TrainingController while the roster
 * was still part of it. They are here rather than in a service because each is
 * an HTTP-layer concern — one turns a request into a scoped model, one turns a
 * decision into the flash payload AppToast renders as an Undo button — and a
 * service in this codebase deliberately takes an actor and a scope rather than a
 * Request.
 *
 * The reason they are shared is the reason they were worth extracting at all:
 * RosterController and RosterBulkController apply the same decisions to the same
 * rows, and a scoping check or an undo window present on only one of them would
 * be a hole in exactly the place nobody looks — the bulk path, which touches
 * forty registrations instead of one.
 */
trait ManagesRosterDecisions
{
    /**
     * Re-resolve a route-bound registration against the field-office scope.
     *
     * Route-model binding does not know about scoping, so an action that only
     * took the bound model would let a scoped officer act on another office's
     * participant by posting its id. 404 rather than 403: the registration's
     * existence is not theirs to know, which is the same answer the roster and
     * the participant directory give.
     */
    private function scopedRegistration(Request $request, Registration $registration): Registration
    {
        $officeId = $request->user()->scopedFieldOfficeId();

        return Registration::whereKey($registration->getKey())
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->firstOr(fn () => abort(404));
    }

    /**
     * Build the flash payload the toast turns into an Undo button.
     *
     * @return array{token: string, label: string, seconds: int}|null
     */
    private function undoOffer(Request $request, string $label, array $snapshot): ?array
    {
        $token = UndoService::offer($request->user(), $label, $snapshot);

        return $token === null ? null : [
            'token' => $token,
            'label' => $label,
            'seconds' => UndoService::WINDOW_SECONDS,
        ];
    }

    private function markCompleted(Registration $registration, ?string $remarks = null): void
    {
        $registration->forceFill([
            'status' => RegistrationStatus::Completed,
            // Falls back to the training's start only when completion was
            // forced; otherwise attendance has already set this.
            'attended_at' => $registration->attended_at ?? $registration->training->starts_at,
            'review_remarks' => $remarks ?? $registration->review_remarks,
        ])->save();
    }
}
