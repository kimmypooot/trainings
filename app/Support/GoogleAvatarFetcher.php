<?php

namespace App\Support;

use App\Http\Controllers\ProfilePhotoController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetch the photo on a linked Google account and store it like any upload.
 *
 * This is the half of the Google photo import that talks to the network, split
 * out of App\Jobs\ImportGoogleAvatar because there are now two callers with
 * genuinely different timing needs and only one of them wants a queue:
 *
 *  - The job, when a Google identity is first attached. Queued, because a slow
 *    fetch must never sit inside the sign-in round trip.
 *  - ProfilePhotoController::syncFromGoogle, when a participant presses the
 *    button. Synchronous, because somebody is watching the avatar and waiting
 *    for it to change — see that method for why queueing it there would be the
 *    wrong answer.
 *
 * What must not be duplicated across those two is this file's actual content:
 * the host allow-list and the size cap. Both are the security-relevant part of
 * the import, and a second copy is a second thing to forget to change.
 */
class GoogleAvatarFetcher
{
    /** A photo far larger than this is not a profile picture. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Long enough for a slow round trip, short enough to fail a dead host. */
    private const TIMEOUT_SECONDS = 10;

    /**
     * Fetch, re-encode and store the photo; null if it could not be had.
     *
     * Every failure is an ordinary outcome rather than an exception: the
     * caller's job is to leave the participant's existing photo alone, not to
     * report a broken remote host to them. The reasons are logged instead.
     *
     * Note what this deliberately does *not* do: it never touches the user
     * row. It returns a path and the caller decides whether that path may
     * claim the slot — which is what lets the job refuse to clobber an upload
     * that landed while it was fetching, and lets the controller swap only
     * after it has the bytes in hand.
     */
    public static function fetch(string $url, ?int $userId = null): ?string
    {
        if (! self::isGoogleUrl($url)) {
            Log::warning('Refused a non-Google avatar URL', ['user_id' => $userId]);

            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $e) {
            Log::info('Google avatar fetch failed', ['user_id' => $userId, 'exception' => $e]);

            return null;
        }

        if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
            return null;
        }

        try {
            return AvatarImageService::storeBytes(
                $response->body(),
                'avatars',
                ProfilePhotoController::DISK
            );
        } catch (Throwable $e) {
            Log::info('Google avatar could not be processed', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Whether this is a URL Google would actually have given us.
     *
     * The URL arrives inside an OAuth response rather than from a user, so it
     * is not attacker-controlled in the ordinary case — but this is fetched
     * from the server, and a request-forgery gadget that only fires on a
     * compromised OAuth response is still a gadget. Cheaper to pin the host
     * than to reason about it.
     */
    public static function isGoogleUrl(string $url): bool
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
