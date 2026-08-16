<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\PasswordChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Password change from the header menu, mirroring the recruitment system's
 * flow: the current password must be re-entered — a hijacked session must not
 * be able to swap the password on its own — and the new one follows the same
 * policy as registration and reset.
 *
 * An account created through Google has no password to re-enter. Those
 * participants are not locked out of email sign-in; they simply have not
 * chosen a password yet, and this is where they create one. Requiring a
 * current password they were never issued is what made the menu item a dead
 * end, so the rule is conditional rather than absolute — and it is the *stored*
 * password that decides, never anything the request can claim.
 */
class ChangePasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Read once, from the record, before validation. Deriving it from
        // input would let a request opt itself out of the check.
        $isCreating = ! $user->hasPassword();

        $validated = $request->validate([
            'current_password' => [Rule::requiredIf(! $isCreating), 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! $isCreating && ! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        // password is a hashed cast, so writing the raw value is enough — the
        // cast does the hashing on save, exactly as PasswordResetController does.
        $user->forceFill(['password' => $validated['password']])->save();

        // Sent to the address on the account, which is the one place a hijacked
        // session cannot reach. See PasswordChanged.
        $user->notify(new PasswordChanged($isCreating));

        return back()->with('success', $isCreating
            ? 'Your password has been created. You can now sign in with your email address or with Google.'
            : 'Your password has been updated.');
    }
}
