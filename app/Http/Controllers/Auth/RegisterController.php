<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        // Emails start unverified: a link is mailed to the address and the
        // account stays locked until it is clicked. The profile is still
        // collected first (unverified users can reach the gate form), and the
        // completion step tells them to verify before they can use the system.
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

        // No event fired here: the framework's automatic Registered listener
        // would re-send the verification email (the model now implements
        // MustVerifyEmail), and nothing else in this app listens to Registered.
        // The verification link is sent by ProfileController::store, once the
        // registration is actually complete — a signed link fired at account
        // creation would go stale while the draftable profile form is open.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile.complete');
    }
}
