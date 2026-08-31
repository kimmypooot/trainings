<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use App\Support\PendingActionCounter;
use App\Support\VisitorCounter;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Who this deployment is, for the public footer. Static config
            // rather than a lazy closure: it never changes within a request and
            // is wanted on the very first paint of every public page.
            /*
             * The site's own absolute base URL.
             *
             * Home.vue used to read window.location.origin at module scope to
             * build its og:* and canonical tags. That is correct in a browser
             * and fatal anywhere else: the moment Inertia SSR is switched on,
             * module scope runs in Node, where `window` does not exist, and the
             * landing page throws before it renders. The server already knows
             * this value with more authority than the browser does — APP_URL is
             * what every mailed link and the sitemap are built from, so a
             * canonical derived from it cannot disagree with them.
             */
            'appUrl' => rtrim(config('app.url'), '/'),
            'appVersion' => config('app.version'),
            'office' => [
                'name' => config('office.name'),
                'short_name' => config('office.short_name'),
                'region' => config('office.region'),
                'address' => config('office.address'),
                'phone' => config('office.phone'),
                'email' => config('office.email'),
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    // Just the given name, cased for prose — the auth splash
                    // greets by it, and `name` is upper-cased for most accounts.
                    'first_name' => $request->user()->firstName(),
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->avatarUrl(),
                    'role' => $request->user()->role->value,
                    'role_label' => $request->user()->role->label(),
                    'email_verified' => $request->user()->email_verified_at !== null,
                    'profile_completed' => $request->user()->hasCompletedProfile(),
                    // Collecting is a designation rather than a role, so the
                    // sidebar cannot decide the money items from `role` alone.
                    'collects_payments' => $request->user()->collectsPayments(),
                    // Google-created accounts have no password yet. Shared so
                    // the header menu can offer to create one instead of
                    // asking for a current password that was never issued.
                    'has_password' => $request->user()->hasPassword(),
                    'has_google' => $request->user()->hasGoogleAccount(),
                ] : null,
            ],
            'unreadNotifications' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            // Sidebar badges: nav item key => items awaiting a decision for the
            // signed-in role, scoped to a field office where one applies.
            'pendingActions' => fn () => $request->user()
                ? PendingActionCounter::for($request->user())
                : [],
            'visitors' => fn () => $request->isMethod('GET') ? VisitorCounter::countOnce() : VisitorCounter::total(),
            // Staff pass straight through maintenance mode (see
            // EnsureSiteIsAvailable), so without a banner the switch can sit on
            // for days unnoticed — on the public site they see a working page,
            // in the shell they see a working app. The flag is false for guests,
            // who are held on the notice and never reach these props.
            'maintenanceMode' => fn () => $request->user()
                ? SiteSetting::isInMaintenance()
                : false,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Present only while a decision can still be taken back; the
                // toast turns it into the Undo button.
                'undo' => fn () => $request->session()->get('undo'),
                // A newly issued scanning station, carrying the one and only
                // copy of its code. Flashed rather than queried back because
                // the plaintext is never stored — see Admin\ScanLinkController.
                'scan_link' => fn () => $request->session()->get('scan_link'),
                // Present on exactly the one request that lands right after a
                // sign-in. Only a sign-in arriving on a *fresh document* — the
                // Google round trip — needs it: that boots a new JS context
                // where the login page's splash no longer exists, so app.js
                // reads this to play the welcome beat itself.
                'just_logged_in' => fn () => $request->session()->get('just_logged_in'),
            ],
        ];
    }
}
