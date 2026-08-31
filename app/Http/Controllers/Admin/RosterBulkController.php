<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Concerns\ManagesRosterDecisions;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Training;
use App\Support\RegistrationService;
use App\Support\UndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One decision applied to a selection from the roster.
 *
 * Its own controller because it is its own risk. The per-row actions in
 * RosterController touch one registration and a mistake is visible; this touches
 * up to a roster at a time, which is why it deliberately does less than they do —
 * it never forces a completion past a short attendance record, because the
 * override has to carry a reason for that specific person to stay auditable, and
 * one reason smeared across forty people is not one.
 *
 * Shares ManagesRosterDecisions with RosterController so the undo window and the
 * completion write cannot drift between the single and the bulk path.
 * RosterBulkActionTest is the guard.
 */
class RosterBulkController extends Controller
{
    use ManagesRosterDecisions;

    /**
     * Apply one decision to a selection from the roster.
     *
     * Bulk deliberately does less than the per-row actions: it never forces a
     * completion past a short attendance record. An override has to carry a
     * reason for that specific person to stay auditable, and a single reason
     * smeared across forty people is not one. Anything ineligible is skipped
     * and reported rather than silently dropped.
     */
    public function __invoke(Request $request, Training $training): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approved', 'waitlisted', 'rejected', 'completed'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['action'] === 'rejected' && blank($validated['remarks'] ?? null)) {
            return back()->withErrors(['remarks' => 'Give a reason when rejecting registrations.']);
        }

        $officeId = $request->user()->scopedFieldOfficeId();

        /*
         * Re-resolved from the training and the office scope rather than taken
         * on trust: a selection posted from the page must not become a way to
         * act on a registration the staff member cannot even see.
         */
        $registrations = Registration::with(['user', 'training', 'attendances'])
            ->where('training_id', $training->getKey())
            ->whereIn('id', $validated['ids'])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'user.profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->get();

        $skipped = 0;
        // Snapshots are taken per row, immediately before it changes, so an
        // undo restores exactly what this action touched and nothing it skipped.
        $snapshot = [];

        if ($validated['action'] === 'completed') {
            foreach ($registrations as $registration) {
                if ($registration->status !== RegistrationStatus::Approved
                    || ! $registration->hasSufficientAttendance()) {
                    $skipped++;

                    continue;
                }

                $snapshot = [...$snapshot, ...UndoService::capture(collect([$registration]))];
                $this->markCompleted($registration);
            }

            return back()
                ->with('success', $this->bulkMessage(
                    count($snapshot).' participant(s) marked as completed.',
                    $skipped,
                    'not approved, or short on attendance — complete those individually'
                ))
                ->with('undo', $this->undoOffer($request, 'Completions undone.', $snapshot));
        }

        $decision = RegistrationStatus::from($validated['action']);

        foreach ($registrations as $registration) {
            if ($registration->status !== RegistrationStatus::Pending) {
                $skipped++;

                continue;
            }

            $snapshot = [...$snapshot, ...UndoService::capture(collect([$registration]))];
            RegistrationService::review($registration, $decision, $request->user(), $validated['remarks'] ?? null);
        }

        return back()
            ->with('success', $this->bulkMessage(
                count($snapshot)." registration(s) — {$decision->label()}.",
                $skipped,
                'no longer pending'
            ))
            ->with('undo', $this->undoOffer($request, 'Decisions taken back.', $snapshot));
    }

    /** Compose the outcome so a partial result never reads as a full one. */
    private function bulkMessage(string $summary, int $skipped, string $reason): string
    {
        return $skipped === 0 ? $summary : "{$summary} {$skipped} skipped ({$reason}).";
    }
}
