<?php

namespace App\Http\Middleware;

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
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
