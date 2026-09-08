<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\EmailVerificationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in user on the verification notice until the emailed link
 * has been clicked.
 *
 * Sits alongside EnsureProfileIsComplete on the participant area: staff are
 * created verified by the office (or come in through Google), so this gate is
 * for participants alone. The profile form, verification notice, and logout
 * are deliberately outside this middleware.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Staff are not asked to verify a self-service email address.
        if ($user?->role->isStaff()) {
            return $next($request);
        }

        if ($user && ! $user->hasVerifiedEmail()) {
            // Say why, in the same words the notice page's own "continue"
            // button gets: landing back on the page you just left, with
            // nothing said, reads as a dead control rather than as an answer.
            return redirect()->route('verification.notice')
                ->with('error', EmailVerificationController::NOT_YET_VERIFIED);
        }

        return $next($request);
    }
}
