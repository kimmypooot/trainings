<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the money screens on the collecting-officer *designation*.
 *
 * This is the one place in the app where authorisation is not a role check,
 * and deliberately so. In v1 collecting is something a staff member is
 * designated to do on top of the job they already have — a field office's
 * collecting officer is a field-office user, and must stay scoped to their own
 * office while taking money. Expressing that as a role forces a choice between
 * the scoping and the till, so it is expressed as a flag instead.
 *
 * `User::collectsPayments()` is the predicate; admins and superadmins carry it
 * by role, as they do everywhere else.
 */
class EnsureUserCollectsPayments
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->role->isStaff(), 403, 'This area is for CSC staff.');

        abort_unless(
            $user->collectsPayments(),
            403,
            'Only a designated collecting officer may handle payments.'
        );

        return $next($request);
    }
}
