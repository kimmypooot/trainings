<?php

namespace App\Jobs;

use App\Http\Controllers\ProfilePhotoController;
use App\Models\User;
use App\Support\AvatarImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
 * Queued so a slow fetch never sits inside the sign-in round trip. Failure is
 * not an error worth surfacing — the participant simply has initials until
 * they upload something.
 */
class ImportGoogleAvatar implements ShouldQueue
{
    use Queueable;

    /** A photo far larger than this is not a profile picture. */
    private const MAX_BYTES = 5 * 1024 * 1024;

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

        if (! self::isGoogleUrl($this->url)) {
            Log::warning('Refused a non-Google avatar URL', ['user_id' => $this->userId]);

            return;
        }

        try {
            $response = Http::timeout(10)->get($this->url);
        } catch (Throwable $e) {
            Log::info('Google avatar fetch failed', ['user_id' => $this->userId, 'exception' => $e]);

            return;
        }

        if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
            return;
        }

        try {
            $path = AvatarImageService::storeBytes(
                $response->body(),
                'avatars',
                ProfilePhotoController::DISK
            );
        } catch (Throwable $e) {
            Log::info('Google avatar could not be processed', [
                'user_id' => $this->userId,
                'exception' => $e,
            ]);

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

    /**
     * Whether this is a URL Google would actually have given us.
     *
     * The URL arrives inside an OAuth response rather than from a user, so it
     * is not attacker-controlled in the ordinary case — but this job fetches
     * it from the server, and a request-forgery gadget that only fires on a
     * compromised OAuth response is still a gadget. Cheaper to pin the host
     * than to reason about it.
     */
    private static function isGoogleUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        return $host === 'googleusercontent.com'
            || str_ends_with($host, '.googleusercontent.com')
            || str_ends_with($host, '.google.com');
    }
}
