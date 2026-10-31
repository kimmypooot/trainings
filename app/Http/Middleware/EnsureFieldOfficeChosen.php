<?php

namespace App\Http\Middleware;

use App\Support\ActingFieldOffice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A field-office user who heads more than one office has to say which one
 * they are working as before the staff area opens. Everyone else passes
 * straight through. Where they were headed is remembered, so the choice
 * returns them there.
 */
class EnsureFieldOfficeChosen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ActingFieldOffice::needsChoice($user)) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('admin.choose-office');
        }

        return $next($request);
    }
}
