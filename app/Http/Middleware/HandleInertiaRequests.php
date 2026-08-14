<?php

namespace App\Http\Middleware;

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
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'avatar' => $request->user()->google_avatar,
                    'role' => $request->user()->role->value,
                    'role_label' => $request->user()->role->label(),
                    'profile_completed' => $request->user()->hasCompletedProfile(),
                ] : null,
            ],
            'unreadNotifications' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            // Sidebar badges: nav item key => items awaiting a decision for the
            // signed-in role, scoped to a field office where one applies.
            'pendingActions' => fn () => $request->user()
                ? PendingActionCounter::for($request->user())
                : [],
            'visitors' => fn () => $request->isMethod('GET') ? VisitorCounter::countOnce() : VisitorCounter::total(),
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
            ],
        ];
    }
}
