<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AvatarImageService;
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
     * Remove the photo and fall back to initials.
     *
     * It stays removed. The Google photo is imported once, when the account is
     * connected, so there is nothing left to put it back on the next sign-in.
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
