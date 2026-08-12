<?php

namespace App\Http\Middleware;

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
            'visitors' => fn () => $request->isMethod('GET') ? VisitorCounter::countOnce() : VisitorCounter::total(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
