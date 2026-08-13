<?php

namespace App\Http\Controllers;

use App\Models\ScanLink;
use App\Support\ScanStationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public attendance station.
 *
 * Doors are worked by people without accounts — training aides, student
 * volunteers, the host agency's own clerk — on their own phones. This is the
 * station they can be handed: no login, one training, and a credential that
 * expires.
 *
 * "Public" here means unauthenticated, not unguarded. Three things keep it
 * narrow:
 *
 *  - the URL token identifies a link, and a six-digit code proves the holder
 *    was actually given it, so a link pasted into a group chat is inert alone;
 *  - every response is scoped to the *issuer's* field office, so a link can
 *    never surface a roster its creator could not have opened themselves;
 *  - nothing here can create, amend or delete anything except an attendance
 *    check-in, and even that runs through the same idempotent service the staff
 *    scanner uses.
 *
 * The gate is throttled rather than lockout-based on purpose. Locking a link
 * after N wrong codes would hand any passer-by a way to shut the door mid
 * session, which is a worse failure at a venue than a slow guess.
 */
class ScanLinkController extends Controller
{
    /**
     * The station page.
     *
     * Renders whether or not the holder has unlocked yet — the page decides
     * between the code gate and the scanner from what the device already holds.
     * Doing it that way rather than redirecting keeps the whole station on one
     * URL, which is what the service worker caches and what a volunteer
     * bookmarks.
     *
     * A dead link still renders, with an explanation. A blank 404 at a venue
     * door tells the person holding the phone nothing they can act on.
     */
    public function show(string $token): Response
    {
        $link = ScanLink::query()->with('training')->where('token', $token)->first();

        return Inertia::render('Scan/Station', [
            'token' => $token,
            // Deliberately thin until unlocked. Before the code is entered this
            // payload is readable by anyone holding the URL, so it carries the
            // training's name — enough to confirm you are at the right door —
            // and nothing whatsoever about who is registered.
            'link' => $link === null || ! $link->isActive() ? null : [
                'training_title' => $link->training->title,
                'venue' => $link->training->venue,
                'label' => $link->label,
                // Announced before the code is even entered: whoever picks this
                // phone up should know it does not count before they scan a
                // queue of people with it.
                'is_test' => $link->is_test,
                'expires_at' => $link->expires_at->toIso8601String(),
            ],
            'state' => match (true) {
                $link === null => 'unknown',
                $link->revoked_at !== null => 'revoked',
                ! $link->expires_at->isFuture() => 'expired',
                default => 'active',
            },
            'unlockUrl' => route('station.unlock', $token),
            'rosterUrl' => route('station.roster', $token),
            'syncUrl' => route('station.sync', $token),
        ]);
    }

    /**
     * Exchange the code for the device's grant.
     *
     * The grant is what every later request carries; see ScanLink::mintGrant()
     * for why it exists at all rather than a session.
     */
    public function unlock(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $link = ScanLink::query()->where('token', $token)->first();

        // One message for "no such link" and "wrong code" alike. Distinguishing
        // them would turn the gate into an oracle for which tokens exist.
        if ($link === null || ! $link->isActive() || ! $link->verifyCode($validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code does not match this scanning link.',
            ]);
        }

        $link->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        return response()->json([
            'grant' => $link->mintGrant(),
            'training_title' => $link->training->title,
            'expires_at' => $link->expires_at->toIso8601String(),
        ]);
    }

    /**
     * The offline bundle, once unlocked.
     *
     * This is the single point where the station learns who is registered, and
     * it is the reason the code gate exists at all.
     */
    public function roster(Request $request, string $token): JsonResponse
    {
        $link = $this->authorise($request, $token);

        $link->loadMissing(['training', 'issuer']);

        return response()->json([
            ...ScanStationService::roster($link->training, $link->issuer->scopedFieldOfficeId()),
            'scoped_to' => $link->issuer->fieldOffice?->name,
        ]);
    }

    /**
     * Write the device's queue back.
     *
     * The training is taken from the *link*, never from the payload: a station
     * holding a link for one training must not be able to post attendance
     * against another by editing a request body.
     */
    public function sync(Request $request, string $token): JsonResponse
    {
        $link = $this->authorise($request, $token);

        $validated = $request->validate([
            'scans' => ['required', 'array', 'max:500'],
            'scans.*.client_id' => ['required', 'string', 'max:64'],
            'scans.*.registration_id' => ['required', 'integer'],
            'scans.*.scanned_at' => ['required', 'date'],
        ]);

        $link->loadMissing('issuer');

        $results = ScanStationService::sync(
            $link->training_id,
            $validated['scans'],
            // Read off the link, never off the request. The phone holding this
            // station is not ours and does not get a say in whether its scans
            // are real.
            // Attributed to the issuer. Attendance has to name a real user, and
            // the honest answer to "who recorded this" is the staff member who
            // put a station on that door.
            $link->issuer,
            $link->issuer->scopedFieldOfficeId(),
            $link->is_test,
        );

        $link->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        return response()->json([
            'results' => $results,
            'synced_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Resolve the grant on the request, or refuse.
     *
     * 401 rather than 403 or a redirect: the station's fetch layer treats 401
     * as "this device needs to unlock again" and keeps the scan queue intact
     * while it asks — the one behaviour that must never lose a day's scans.
     */
    private function authorise(Request $request, string $token): ScanLink
    {
        $link = ScanLink::fromGrant($request->header('X-Scan-Grant'), $token);

        abort_if($link === null, 401, 'This scanning link needs to be unlocked again.');

        return $link;
    }
}
