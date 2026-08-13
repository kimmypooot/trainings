<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Support\ScanStationService;
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
            'scopedTo' => $request->user()->fieldOffice?->name,
            'operator' => $request->user()->name,
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
            ...ScanStationService::roster($training, $request->user()->scopedFieldOfficeId()),
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
        ]);

        $results = ScanStationService::sync(
            $validated['training_id'],
            $validated['scans'],
            $request->user(),
            $request->user()->scopedFieldOfficeId(),
        );

        return response()->json([
            'results' => $results,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }
}
