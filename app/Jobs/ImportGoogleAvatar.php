<?php

namespace App\Jobs;

use App\Http\Controllers\ProfilePhotoController;
use App\Models\User;
use App\Support\GoogleAvatarFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Take a copy of the photo on a newly connected Google account.
 *
 * The photo is imported *once*, when the Google identity is first attached,
 * and then belongs to TIMS like any upload. It is deliberately not a live
 * mirror of the Google account:
 *
 *  - Rendering Google's URL directly would tell Google which participant
 *    viewed which page of a government system holding their personal data,
 *    on every avatar in the app.
 *  - Those URLs rotate, so a profile photo would eventually 404. A stored
 *    copy does not, and it prints — rosters and attendance sheets go to
 *    paper, where a remote image is exactly what fails.
 *  - Re-syncing on every sign-in would fight the participant's own choice.
 *    Importing once means "remove my photo" stays removed.
 *
 * That last point is about what happens *automatically*. A participant asking
 * for the photo again is not the system overriding them, and there is now a
 * button for it — see ProfilePhotoController::syncFromGoogle, which does the
 * same import synchronously because somebody is watching the avatar when they
 * press it.
 *
 * Queued so a slow fetch never sits inside the sign-in round trip. Failure is
 * not an error worth surfacing — the participant simply has initials until
 * they upload something.
 */
class ImportGoogleAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $userId,
        public string $url,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        // Both are ordinary outcomes, not failures: the account may have been
        // deleted, or the participant may have uploaded their own photo
        // between the dispatch and this job running. Their choice wins.
        if (! $user || filled($user->avatar_path)) {
            return;
        }

        $path = GoogleAvatarFetcher::fetch($this->url, $this->userId);

        if ($path === null) {
            return;
        }

        // Re-read before claiming the slot: an upload may have landed while the
        // image was being fetched and resized, and it must not be clobbered by
        // a photo the participant did not ask for.
        $user->refresh();

        if (filled($user->avatar_path)) {
            Storage::disk(ProfilePhotoController::DISK)->delete($path);

            return;
        }

        $user->forceFill(['avatar_path' => $path])->save();
    }
}
