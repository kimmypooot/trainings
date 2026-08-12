<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the participant's check-in code.
 *
 * The code encodes a URL rather than a bare token so a generic phone camera
 * opens the check-in page directly — no dedicated scanner app at the venue.
 * The result is embedded in the page as a data URI, so the code renders from
 * cache with no network at the door.
 */
class ParticipantQrCode
{
    private const SIZE = 600;

    public static function dataUri(User $user): string
    {
        $token = $user->ensureQrToken();

        return Cache::rememberForever(self::cacheKey($token), fn () => self::build($token));
    }

    public static function forget(string $token): void
    {
        Cache::forget(self::cacheKey($token));
    }

    /**
     * Versioned so a change to how codes are drawn invalidates everything
     * already cached, rather than serving stale images forever.
     */
    private static function cacheKey(string $token): string
    {
        return "qr-code:v2:{$token}";
    }

    private static function build(string $token): string
    {
        return QrCodeBuilder::dataUri(route('scan', ['token' => $token]), self::SIZE);
    }
}
