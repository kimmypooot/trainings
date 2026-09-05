<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ejects a signed-in user whose account has been switched off.
 *
 * `is_active` used to be checked in exactly two places — LoginController and
 * the Google callback — which made deactivation a lock on the front door and
 * nothing more. A session that already existed kept working, and because a
 * session is refreshed on every request, "kept working" meant indefinitely.
 * That is the wrong shape for this control: switching an account off is what
 * the office reaches for when a staff member leaves or an account is known to
 * be compromised, and in both of those the holder is *already* signed in. The
 * one case it needs to cover was the one case it did not.
 *
 * Worse, deactivation was skippable even at the front door. A "remember me"
 * cookie is honoured inside SessionGuard::user(), which never reaches
 * LoginController, so a deactivated account holding one was quietly signed back
 * in — for the 400 days Laravel's recaller lasts. This middleware sits on the
 * whole `web` group, so it covers the remembered path too.
 *
 * Appended ahead of EnsureSiteIsAvailable and HandleInertiaRequests so a
 * deactivated user is turned away before the app builds a page payload or
 * counts them as a visitor, for the same reason the maintenance gate runs
 * early.
 *
 * The message matches LoginController's word for word. A person who has just
 * been ejected mid-session and then tries to sign back in should be told the
 * same thing both times, rather than being left to guess whether they hit two
 * different problems.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'This account has been deactivated. Contact the CSC administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::logout();

        // Guarded rather than assumed: every route in the web group has a
        // session, but this middleware is cheap enough to be reused somewhere
        // that does not, and a missing session must not turn a deactivation
        // into a 500.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->withErrors(['form' => self::MESSAGE]);
    }
}
