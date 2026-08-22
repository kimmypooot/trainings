<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the site to the public and to participants when the maintenance
 * switch (Admin → Maintenance Mode) is on.
 *
 * CSC staff are unaffected and keep working normally. A handful of routes stay
 * open to everyone regardless — see EXEMPT_ROUTES.
 */
class EnsureSiteIsAvailable
{
    /**
     * Routes that must keep working even during maintenance. These are
     * operational rather than promotional: closing them would strand people
     * mid-task — a venue taking attendance, a participant activating an
     * account, a staff member trying to switch maintenance back off.
     */
    private const EXEMPT_ROUTES = [
        // Staff need to get in to turn maintenance back off. There is a single
        // /login for staff and participants alike, so it cannot be closed to
        // staff without closing it to everyone; a participant who signs in
        // anyway meets the notice on the other side of it.
        'login', 'login.store', 'logout',
        'password.request', 'password.email', 'password.reset', 'password.store',

        // Google sign-in is how some staff authenticate too, so the same
        // button on /login cannot be broken for the staff who use it.
        'auth.google', 'auth.google.callback',

        // Participants responding to an emailed verification link — the link is
        // signed and expires, and cannot be re-sent by the participant.
        'verification.verify', 'verification.resend',

        // Public certificate verification, used at venue entrances to check a
        // printed document is genuine while a training is actually running.
        'certificates.verify',

        // The public attendance station — venue door work, and a station may
        // have been synced long before the maintenance switch went on.
        'station.show', 'station.unlock', 'station.roster', 'station.sync',

        // The notice itself, or it would loop.
        'maintenance',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isClosed($request)) {
            return $next($request);
        }

        // 503, not a redirect: tells search engines "temporarily unavailable,
        // come back" rather than letting them index the notice as the homepage.
        //
        // `authenticated` is passed explicitly rather than read from Inertia's
        // shared props: this middleware runs before HandleInertiaRequests (see
        // bootstrap/app.php) precisely so a closed site doesn't assemble props
        // it will never use, which means `auth.user` is not available here.
        // Without this flag a signed-in participant is stranded — every route
        // shows this notice, and the page has no way to know it should offer a
        // way out. POST /logout is exempt and works; nothing could reach it.
        return Inertia::render('Maintenance', [
            'authenticated' => $request->user() !== null,
            'message' => SiteSetting::current()->maintenance_message,
            // Passed for the same reason as `authenticated`, and it matters
            // more here than anywhere: this is the page a participant reads
            // when nothing else works, so the address on it is the one that
            // gets written to. It was hard-coded, and hard-coded wrong — a
            // generic mailbox and the Central Office trunkline in Quezon City,
            // on the page whose entire job is telling someone in Eastern
            // Visayas how to reach help.
            'office' => [
                'name' => config('office.name'),
                'email' => config('office.email'),
                'phone' => config('office.phone'),
            ],
        ])
            ->toResponse($request)
            ->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function isClosed(Request $request): bool
    {
        // Staff work through maintenance; participants and the public do not.
        // Signing in is exempt above, so a staff member can still reach the
        // login page and get back in to switch the site off.
        // Null-safe on role as well as on the user: a record without a role is
        // not treated as staff, so a broken account can't slip past the gate.
        if ($request->user()?->role?->isStaff()) {
            return false;
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return false;
        }

        return SiteSetting::isInMaintenance();
    }
}
