<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AvatarImageService;
use App\Support\GoogleAvatarFetcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The profile photo shown next to a user's name across the app.
 *
 * There is one photo per account and one place it lives: `avatar_path` on the
 * private disk. A photo taken from a linked Google account is imported into
 * that same slot once, when the account is connected (App\Jobs\
 * ImportGoogleAvatar), so from here on there is no such thing as a "Google
 * photo" to arbitrate against — only a photo the participant has, or has not.
 *
 * Photos never touch a public disk. Like every other user-supplied file in
 * this system they are streamed back through an authorising route.
 */
class ProfilePhotoController extends Controller
{
    /** Photos never touch a public disk. */
    public const DISK = 'local';

    /**
     * Upload a new photo and switch the account over to it.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => [
                'required',
                'image',
                // An allow-list, not a deny-list: `image` alone still admits
                // SVG, which is a script-execution vector when served back.
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:min_width=100,min_height=100',
            ],
        ], [
            'photo.max' => 'The photo may not be larger than 2 MB.',
            'photo.dimensions' => 'The photo must be at least 100 × 100 pixels.',
        ]);

        $user = $request->user();
        $previous = $user->avatar_path;

        // Squared, downscaled, and re-encoded before it is stored — the
        // original bytes are never kept. See AvatarImageService for why.
        // Throws a ValidationException on `photo` if GD cannot read the file,
        // which is the same shape as the rule failures above.
        $path = AvatarImageService::store($request->file('photo'), 'avatars', self::DISK);

        $user->forceFill(['avatar_path' => $path])->save();

        $this->deleteStoredPhoto($previous);

        return back()->with('success', 'Your profile photo has been updated.');
    }

    /**
     * Take the photo from the linked Google account again, on request.
     *
     * The automatic import runs once, when the identity is first attached, and
     * that is deliberate — see App\Jobs\ImportGoogleAvatar. It left one thing
     * unreachable, and ImportGoogleAvatarCommand's own docblock names it: if
     * the queued import is dispatched and never runs (no worker, a flushed
     * queue), nothing retries it, because the "already attached" state that
     * gates the dispatch is exactly what the next sign-in sees. A participant
     * could not recover from that on their own — disconnecting and
     * reconnecting would re-import, but disconnecting is refused when Google
     * is the only way in, which is true of precisely the accounts created
     * through it. The office had a command; the participant now has a button.
     *
     * Synchronous, unlike the import at sign-in, and the difference is the
     * point: nothing is waiting on the sign-in round trip, whereas here
     * somebody has pressed a button and is watching the avatar. Queued, the
     * honest flash would be "we will get to it", and on a deployment whose
     * worker is down that is the same silent nothing this exists to undo.
     *
     * The old file is deleted only after the new one is stored, so a dead
     * Google host leaves the participant exactly as they were rather than
     * with no photo at all — which is the trap in doing this by clearing the
     * slot first and letting the job fill it.
     */
    public function syncFromGoogle(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Two different situations behind one refusal, so they get two
        // different sentences: the card offers this button only when there is
        // something to fetch, so reaching here at all means the state moved
        // under the page (Google disconnected in another tab) or the request
        // was made by hand.
        if (! $user->hasGoogleAccount()) {
            return back()->with('error', 'Connect a Google account first.');
        }

        if (blank($user->google_avatar_url)) {
            return back()->with('error', 'There is no photo on your connected Google account.');
        }

        $path = GoogleAvatarFetcher::fetch($user->google_avatar_url, $user->getKey());

        // Deliberately not an exception. The remote host is not the
        // participant's problem to debug, and their existing photo is
        // untouched, so the honest report is that it did not work.
        if ($path === null) {
            return back()->with('error', 'Your Google photo could not be fetched. Please try again, or upload one.');
        }

        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $path])->save();

        $this->deleteStoredPhoto($previous);

        return back()->with('success', 'Your profile photo has been synced from Google.');
    }

    /**
     * Remove the photo and fall back to initials.
     *
     * It stays removed — nothing puts it back on the next sign-in. The Google
     * photo is imported once, when the account is connected, and after that
     * only a deliberate press of Sync from Google returns it.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => null])->save();

        $this->deleteStoredPhoto($previous);

        return back()->with('success', 'Your profile photo has been removed.');
    }

    /**
     * Stream a stored photo.
     *
     * Open to the owner and to staff, who see participant photos on rosters
     * and participant detail pages; nobody else, and never by direct storage
     * URL. Field-office scoping is deliberately not applied here: a photo
     * carries no information a staff member could not already read from the
     * participant lists their role admits, and the alternative — resolving the
     * office on every avatar render — buys nothing.
     */
    public function show(Request $request, User $user): StreamedResponse
    {
        $isOwner = $user->getKey() === $request->user()->getKey();

        abort_unless($isOwner || $request->user()->role->isStaff(), 403);
        abort_unless(filled($user->avatar_path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($user->avatar_path), 404);

        return Storage::disk(self::DISK)->response($user->avatar_path, null, [
            // The URL is cache-busted on change (see User::avatarUrl), so the
            // browser may hold on to it — but only in a private cache, since
            // the response is authorised per user.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Drop a replaced file. Best-effort: a missing file is not an error worth
     * failing a save that has already been committed.
     */
    private function deleteStoredPhoto(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
