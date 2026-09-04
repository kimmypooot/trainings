<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AccountAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    /**
     * Show the "request a reset link" screen.
     */
    public function showForgotForm(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Send a reset link, if the address belongs to an account.
     *
     * The reply is deliberately identical whether the address exists or not, so
     * the form cannot be used to probe which emails have accounts. The broker's
     * throttling (60s between resets per address) still applies server-side.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::broker()->sendResetLink($request->only('email'));

        return back()->with('status', 'If the address matches an account, a reset link is on its way.');
    }

    /**
     * Show the reset form. The token is the secret; the email only pre-fills.
     */
    public function showResetForm(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Verify the token and write the new password.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                // password is a hashed cast, so writing the raw value here is
                // enough — the cast does the hashing on save.
                $user->forceFill(['password' => $password])->save();

                /*
                 * A reset is an account-recovery action, so it ends whatever
                 * was already going on: every session this account holds, and
                 * the remember-me token that would otherwise sign it back in
                 * for 400 days.
                 *
                 * Both were being kept. This callback overrides the broker's
                 * default write, and the default is the one that rotates
                 * `remember_token` — so a stolen recaller cookie survived the
                 * very action a person takes *because* they think they have
                 * been compromised.
                 *
                 * Safe to clear every session here, unlike on a password
                 * change: whoever is resetting is a guest holding no session of
                 * their own, so there is nothing of theirs to sign out.
                 */
                AccountAccess::revoke($user);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['form' => __($status)])->onlyInput('email');
    }
}
