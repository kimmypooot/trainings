<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\User;
use App\Support\ScanStationService;
use App\Support\WalkInService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The venue scanner: a standalone check-in station for a phone or tablet.
 *
 * The point of this controller is the *offline* case. Training venues are
 * function rooms, gymnasiums and provincial capitol halls where the signal
 * dies at the door, and a queue of forty participants cannot wait for a
 * request to time out per person. So the scanner downloads the roster it needs
 * before the session starts, decides everything locally while scanning, and
 * hands its results back when a connection returns.
 *
 * That shape drives three endpoints rather than one:
 *
 *  - index()  the station page itself, listing what can be scanned;
 *  - roster() the offline bundle, downloaded once per training per day;
 *  - sync()   the batch write-back, idempotent so a retry is always safe.
 *
 * Nothing here is public. A roster resolves participant identities in bulk, so
 * it is staff-only and field-office scoped exactly like TrainingController@roster
 * — see FieldOfficeScopingTest, which is the guard on that.
 *
 * The station a volunteer can be handed instead lives at ScanLinkController.
 * Both share ScanStationService, which is where the roster shape and the
 * write-back rules actually live, so the public door cannot drift into being
 * more permissive than this one.
 */
class ScannerController extends Controller
{
    /**
     * The scanning station.
     *
     * Trainings running today come first because that is what a scanner at a
     * door needs; the rest of the current window is offered too, so a station
     * can be prepared the night before while the office still has wifi.
     */
    public function index(Request $request): Response
    {
        $trainings = Training::query()
            // A window rather than "today": staff set the tablet up in advance,
            // and a multi-day run must stay downloadable on days two and three.
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(30)])
            // A draft has no approved participants and a cancelled run has no
            // door to stand at; offering either is just a way to mis-scan.
            ->whereNotIn('status', [TrainingStatus::Draft, TrainingStatus::Cancelled])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Training $training) => [
                'id' => $training->id,
                'title' => $training->title,
                'venue' => $training->venue,
                'starts_at' => $training->starts_at->format('d M Y, g:i A'),
                'date_label' => $training->starts_at->format('D, d M Y'),
                'duration_days' => max(1, $training->duration_days ?? 1),
                'is_today' => $training->isRunningToday(),
                'roster_url' => route('admin.scanner.roster', $training),
            ])
            ->values()
            ->all();

        return Inertia::render('Staff/Scanner', [
            'trainings' => $trainings,
            'syncUrl' => route('admin.scanner.sync'),
            'walkInUrl' => route('admin.scanner.walk-in'),
            'scopedTo' => $request->user()->fieldOffice?->name,
            'operator' => $request->user()->name,
            // Whether this operator may rehearse. Sent so the toggle is simply
            // absent for everyone else rather than present and refused.
            'canTest' => $request->user()->role === Role::SuperAdmin,
        ]);
    }

    /**
     * The offline bundle for one training.
     *
     * Deliberately *not* an Inertia response: this is downloaded by fetch() and
     * written straight into IndexedDB, and it has to be re-downloadable without
     * disturbing whatever the station is currently showing.
     *
     * Tokens are sent as SHA-256 digests, never in the clear — see
     * ScanStationService, which shapes the bundle for both stations.
     */
    public function roster(Request $request, Training $training): JsonResponse
    {
        return response()->json([
            ...ScanStationService::roster(
                $training,
                $request->user()->scopedFieldOfficeId(),
                ScanStationService::since($request),
            ),
            'scoped_to' => $request->user()->fieldOffice?->name,
        ]);
    }

    /**
     * Write a batch of offline scans back.
     *
     * The batch is validated here and applied by ScanStationService, which is
     * also what the public scan-link station posts to — one write path, so an
     * idempotency or scoping rule can only ever be changed for both at once.
     */
    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'training_id' => ['required', 'integer', 'exists:trainings,id'],
            'scans' => ['required', 'array', 'max:500'],
            'scans.*.client_id' => ['required', 'string', 'max:64'],
            'scans.*.registration_id' => ['required', 'integer'],
            'scans.*.scanned_at' => ['required', 'date'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        // Refused rather than quietly downgraded to a live sync. A rehearsal
        // that silently became real would be the worst possible outcome here,
        // and an operator who cannot rehearse should be told so plainly.
        $dryRun = (bool) ($validated['dry_run'] ?? false);

        abort_if(
            $dryRun && $request->user()->role !== Role::SuperAdmin,
            403,
            'Test mode is available to super administrators only.'
        );

        $results = ScanStationService::sync(
            $validated['training_id'],
            $validated['scans'],
            $request->user(),
            $request->user()->scopedFieldOfficeId(),
            $dryRun,
        );

        return response()->json([
            'results' => $results,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Admit a walk-in from the desk.
     *
     * The one endpoint here that is deliberately *online*.
     *
     * Everything else on this controller is built so a device can work with no
     * signal, and a walk-in cannot be. The station holds one training's roster
     * and nothing else, so a code belonging to somebody not on it is
     * unidentifiable locally — the device is carrying digests, not names. The
     * alternative, shipping every participant in the region to every tablet,
     * would undo the reason the roster ships digests in the first place: a
     * device left in a function room overnight is currently worth nothing to
     * whoever picks it up.
     *
     * So this is the walk-in *desk*, not the door. Put it where there is
     * signal, and leave the doors offline as they are.
     */
    public function walkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'training_id' => ['required', 'integer', 'exists:trainings,id'],
            // The raw token off the QR, not a digest: the desk is online, so
            // there is no reason to make the server search by hash.
            'token' => ['required', 'string', 'max:64'],
        ]);

        $training = Training::findOrFail($validated['training_id']);
        $officeId = $request->user()->scopedFieldOfficeId();

        /*
         * Resolved under the actor's own office scope, exactly as the roster
         * is. A field office admitting a walk-in from another office would be
         * enrolling someone it cannot otherwise see, and the whole scoping
         * invariant is that no surface hands over a participant outside it —
         * a new one must not become the exception. Not-found rather than
         * forbidden, so the answer does not confirm the code belongs to
         * somebody real.
         */
        $participant = User::where('qr_token', $validated['token'])
            ->when($officeId !== null, fn ($query) => $query->whereHas(
                'profile',
                fn ($profile) => $profile->where('field_office_id', $officeId)
            ))
            ->first();

        if ($participant === null) {
            return response()->json([
                'message' => 'That code does not match a participant you can admit.',
            ], 404);
        }

        $result = WalkInService::admit($participant, $training, $request->user());

        return response()->json([
            'admitted' => $result['admitted'],
            'checked_in' => $result['checked_in'],
            'over_capacity' => $result['over_capacity'],
            'over_by' => $result['over_by'],
            'message' => $result['message'],
            /*
             * The roster row, not a summary of it. The station appends this to
             * the roster it already holds, so the walk-in is recognised by the
             * next scan of the same badge instead of coming back as unknown a
             * second time — and it must be byte-for-byte the shape a download
             * would have produced, digest included.
             */
            'participant' => ScanStationService::participantRow($result['registration']),
        ]);
    }
}
