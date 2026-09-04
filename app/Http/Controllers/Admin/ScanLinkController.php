<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ScanLink;
use App\Models\Training;
use App\Support\ScanLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issuing and killing the public scanning stations for a training.
 *
 * The counterpart to the participant's QR code: that identifies a person, this
 * authorises a door. Both are handed out physically and both have to be
 * revocable, because the failure mode is the same — a phone in the wrong hands.
 *
 * There is no update path on purpose. A scan link's code cannot be changed or
 * re-read after issue (see ScanLink::issue), so the remedy for any doubt about
 * a link is always the same one: revoke it and issue another. Keeping that the
 * only option is what makes the code's one-time nature true rather than
 * aspirational.
 */
class ScanLinkController extends Controller
{
    /**
     * Mint a link for one training and flash its code back exactly once.
     *
     * The plaintext code rides home in the flash bag rather than the model,
     * because this response is the only moment it will ever exist outside the
     * issuer's memory.
     */
    public function store(Request $request, Training $training): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            // Bounded rather than free: a station that outlives the training it
            // was cut for is just an unrevoked credential sitting in a chat log.
            'days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'is_test' => ['sometimes', 'boolean'],
        ]);

        // A rehearsal station reaches real verdicts against a real roster and
        // writes nothing. Restricted to super administrators because deciding
        // that a door does not count is a system-level call, not a session one.
        $isTest = (bool) ($validated['is_test'] ?? false);

        abort_if(
            $isTest && $request->user()->role !== Role::SuperAdmin,
            403,
            'Test stations can only be issued by a super administrator.'
        );

        /*
         * Through the service rather than the model, for the audit entry.
         *
         * Note what is deliberately *not* checked here: which training. A
         * training belongs to the region, not to an office, so there is no
         * honest scope to apply — and none is needed, because the link carries
         * its issuer's reach and nothing more. Every response it can ever serve
         * is narrowed by `$link->issuer->scopedFieldOfficeId()`, so a field
         * office minting a station for a run it has nothing to do with gets a
         * door onto its own participants and no one else's. The real gap was
         * that nobody could tell afterwards that it had done so; that is what
         * the trail entry closes.
         */
        [$link, $code] = ScanLinkService::issue(
            $training,
            $request->user(),
            $validated['label'] ?? null,
            isset($validated['days'])
                ? CarbonImmutable::now()->addDays((int) $validated['days'])
                : null,
            $isTest,
        );

        return back()->with('scan_link', [
            'id' => $link->id,
            'url' => route('station.show', $link->token),
            'code' => $code,
            'label' => $link->label,
            'is_test' => $link->is_test,
            'expires_at' => $link->expires_at->format('d M Y'),
        ]);
    }

    /**
     * Revoke a link.
     *
     * Soft rather than a delete: the row records who authorised a door and when
     * it was last used, and that is exactly what someone will ask for after an
     * attendance dispute. Revoking is also immediate for grants already minted —
     * ScanLink::fromGrant re-checks the row every request.
     *
     * Broad, but no longer unbounded. Every staff role in this group could
     * revoke *any* station in the region, including one another office was
     * working a live door with — a cross-office destructive action, and an
     * unrecorded one. The reason revocation is not superadmin-only still holds
     * (a phone goes missing and the person at the venue must be able to kill
     * the link without finding an administrator), so the fix is a bound on
     * whose link rather than on who may revoke: see ScanLinkService::mayRevoke.
     *
     * 404 rather than 403, matching every other office-scoped refusal in the
     * app — another office's stations are not this operator's to enumerate.
     */
    public function destroy(Request $request, ScanLink $scanLink): RedirectResponse
    {
        $scanLink->loadMissing('issuer');

        abort_unless(ScanLinkService::mayRevoke($scanLink, $request->user()), 404);

        ScanLinkService::revoke($scanLink, $request->user());

        return back()->with('success', 'That scanning link has been revoked and can no longer be used.');
    }
}
