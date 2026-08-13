<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    /**
     * Show the registration screen.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'googleEnabled' => (bool) config('services.google.client_id'),
        ]);
    }

    /**
     * Register a new participant.
     */
    public function store(Request $request): RedirectResponse
    {
        // The participant's name is collected on the profile form, not here.
        // Email counts as verified at sign-up: participants are authenticated in
        // person by the office when their profile is vetted, so pinning the badge
        // on a separate verification email would strand it out of reach.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'You must accept the Privacy Policy and Terms of Service to register.',
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        // email_verified_at is not mass-assignable (it is not in the model's
        // fillable list), so it is written the same way GoogleController writes
        // it — forceFilled after creation.
        $user->forceFill(['email_verified_at' => now()])->save();

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.complete');
    }
}
