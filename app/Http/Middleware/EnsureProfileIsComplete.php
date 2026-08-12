<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in participant on the profile form until it is filled in.
 *
 * Applied to the authenticated area, so the gate cannot be skipped by typing a
 * URL. The profile form and logout are naturally exempt — they are not behind
 * this middleware.
 */
class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Staff are not participants, so the participant profile does not apply.
        if ($user?->role->isStaff()) {
            return $next($request);
        }

        if ($user && ! $user->hasCompletedProfile()) {
            return redirect()->route('profile.complete');
        }

        return $next($request);
    }
}
