<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleController extends Controller
{
    /**
     * Send the user to Google's consent screen.
     */
    public function redirect(): RedirectResponse
    {
        if (! config('services.google.client_id')) {
            return redirect()->route('login')->withErrors([
                'form' => 'Google sign-in is not configured on this server yet.',
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google.
     *
     * Participants self-register, so an unrecognised Google account creates a
     * TIMS account rather than being turned away. An existing account matched
     * by email gets its Google identity linked on first use.
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google OAuth callback failed', ['exception' => $e]);

            return redirect()->route('login')->withErrors([
                'form' => 'We could not complete the Google sign-in. Please try again.',
            ]);
        }

        $user = User::firstOrNew([
            'google_id' => $googleUser->getId(),
        ]);

        if (! $user->exists) {
            $user = User::where('email', $googleUser->getEmail())->first() ?? new User;
        }

        $user->forceFill([
            'name' => $user->name ?: ($googleUser->getName() ?: $googleUser->getNickname()),
            'email' => $user->email ?: $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'google_avatar' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'form' => 'This account has been deactivated. Contact the CSC administrator.',
            ]);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        // A Google sign-up never sees the registration form, so it lands on the
        // same profile gate as an email sign-up.
        if (! $user->hasCompletedProfile()) {
            return redirect()->route('profile.complete');
        }

        return redirect()->intended(route('dashboard'));
    }
}
