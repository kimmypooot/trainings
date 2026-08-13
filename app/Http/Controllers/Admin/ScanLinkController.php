<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScanLink;
use App\Models\Training;
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
        ]);

        [$link, $code] = ScanLink::issue(
            $training,
            $request->user(),
            $validated['label'] ?? null,
            isset($validated['days'])
                ? CarbonImmutable::now()->addDays((int) $validated['days'])
                : null,
        );

        return back()->with('scan_link', [
            'id' => $link->id,
            'url' => route('station.show', $link->token),
            'code' => $code,
            'label' => $link->label,
            'expires_at' => $link->expires_at->format('d M Y'),
        ]);
    }

    /**
     * Revoke a link.
     *
     * Soft rather than a delete: the row is the only record of who authorised a
     * door and when it was last used, and that is exactly what someone will ask
     * for after an attendance dispute. Revoking is also immediate for grants
     * already minted — ScanLink::fromGrant re-checks the row every request.
     */
    public function destroy(ScanLink $scanLink): RedirectResponse
    {
        $scanLink->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        return back()->with('success', 'That scanning link has been revoked and can no longer be used.');
    }
}
