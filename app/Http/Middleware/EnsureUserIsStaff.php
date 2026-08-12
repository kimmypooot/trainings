<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsStaff
{
    /**
     * Restricts the admin area to CSC staff roles.
     */
    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $user = $request->user();

        abort_unless($user && $user->role->isStaff(), 403, 'This area is for CSC staff.');

        if ($only) {
            $allowed = explode('|', $only);
            abort_unless(in_array($user->role->value, $allowed, true), 403);
        }

        return $next($request);
    }
}
