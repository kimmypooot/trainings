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
     * Accounts are provisioned by CSC, so this only signs in users that already
     * exist — it never creates one. A Google account whose email is unknown to
     * TIMS is bounced back to the login screen with an explanation.
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

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'form' => 'No TIMS account is linked to '.$googleUser->getEmail().'. Ask your agency coordinator to have one provisioned.',
            ]);
        }

        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'google_avatar' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
