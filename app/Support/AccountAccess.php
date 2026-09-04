<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Taking an account's existing access away.
 *
 * Changing what an account *may* do is easy; ending what it is *already* doing
 * is the part this app had never done. Three separate credentials outlive any
 * decision made about the account, and all three have to be dealt with together
 * or the remaining one carries the access on its own:
 *
 *  - the session rows, which are what a signed-in browser is holding;
 *  - the remember-me token, which Laravel honours for 400 days inside
 *    SessionGuard::user() without ever passing through LoginController;
 *  - the password, which is the caller's business rather than this class's.
 *
 * Called on deactivation and on a completed password reset — the two moments
 * that mean "whatever was going on before, stop". It is deliberately *not*
 * called on an ordinary password change: that would sign the person out of the
 * device they are standing at, and AuthenticateSession already ends the other
 * sessions for them.
 */
class AccountAccess
{
    /**
     * End every session this account currently holds, everywhere.
     *
     * The caller's own session is included, so a caller who is revoking their
     * own access must expect to be signed out — which is why the two callers
     * are staff acting on somebody else, and the password-reset flow, where the
     * person resetting is a guest and holds no session to lose.
     */
    public static function revoke(User $user): void
    {
        self::rotateRememberToken($user);
        self::forgetSessions($user);
    }

    /**
     * Invalidate any outstanding "remember me" cookie.
     *
     * Rotating the column is what makes the cookie stop working: the recaller
     * carries the token, and SessionGuard compares it against this value.
     * Laravel's own starter kits do this on every password write; this
     * application's password reset and password change had both dropped it by
     * overriding the write with a forceFill that named only `password`.
     */
    public static function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * Delete the account's server-side sessions.
     *
     * Only meaningful on the database driver, which is what this application
     * runs (SESSION_DRIVER=database). Under any other driver there is no table
     * to sweep and the method is a no-op rather than an error — the test suite
     * runs on the `array` driver, and a revocation must not fail there.
     *
     * Failure is swallowed and reported, on the same reasoning as
     * ActivityLogger: a sessions table that is briefly unavailable must not
     * roll back the deactivation that was the actual point of the request. The
     * remember-token rotation above has already landed by then, and
     * EnsureAccountIsActive ejects the session on its next request regardless —
     * so this is the fastest of three defences, not the only one.
     */
    private static function forgetSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Could not clear sessions for a revoked account.', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
