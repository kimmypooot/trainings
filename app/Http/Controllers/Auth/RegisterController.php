<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
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
            // `bail` so a malformed address is rejected on its shape alone and
            // never reaches the lookup below.
            'email' => ['bail', 'required', 'string', 'email', 'max:255', self::addressIsFree(...)],
            'password' => ['required', 'confirmed', Password::defaults()],
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

    /**
     * The uniqueness check, in place of a bare `unique:users,email`.
     *
     * Same verdict, better sentence. Someone who first arrived through Google
     * has no password to remember and no reason to expect that they already
     * registered — "The email has already been taken" leaves them stuck on a
     * form, retrying, when one click on the button above it would sign them in.
     *
     * It does say which sign-in method the address uses, which the bare rule
     * did not. That is a deliberate and narrow trade: the *existence* of the
     * account is already disclosed by any uniqueness rule (which is why this
     * route is throttled — see routes/web.php), and knowing the account is
     * Google-only tells an attacker only that guessing passwords against it is
     * pointless. The login form makes the opposite call and stays vague, and
     * that is right too: there, no address has been disclosed yet, so there is
     * nothing to build on.
     *
     * The database's unique index remains the actual guard. This runs before
     * it, and two simultaneous submissions can still both pass here.
     */
    private static function addressIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        $existing = User::where('email', $value)->first();

        if (! $existing) {
            return;
        }

        $fail($existing->hasGoogleAccount() && ! $existing->hasPassword()
            ? 'You already have an account with this email address. Use the "Continue with Google" '.
                'button above to sign in.'
            : 'An account with this email address already exists. Sign in instead, or use "Forgot '.
                'password" if you cannot remember it.');
    }
}
