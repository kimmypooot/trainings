<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\PasswordChanged;
use App\Support\AccountAccess;
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
            // 'nullable' matters more than it looks. The dialog hides the
            // current-password field while creating, but useForm still sends
            // the key as an empty string, which ConvertEmptyStringsToNull turns
            // into null before validation runs. Without 'nullable' the 'string'
            // rule then rejects that null — and the message lands on a field
            // the creating dialog never renders, so the whole change failed in
            // complete silence. 'required' still fires on null for an ordinary
            // account, so the check itself is untouched.
            'current_password' => [Rule::requiredIf(! $isCreating), 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! $isCreating && ! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        // password is a hashed cast, so writing the raw value is enough — the
        // cast does the hashing on save, exactly as PasswordResetController does.
        $user->forceFill(['password' => $validated['password']])->save();

        /*
         * Invalidate any outstanding "remember me" cookie.
         *
         * Only the token is rotated here, not the sessions: AuthenticateSession
         * (see bootstrap/app.php) already ends every *other* session by
         * comparing the stored password hash, and it leaves this one alone —
         * which is what a person changing their own password expects. Clearing
         * the session rows outright would sign them out of the device they are
         * standing at, mid-task.
         *
         * The recaller cookie needs saying separately because it is not a
         * session: SessionGuard honours it for 400 days without consulting one,
         * and this write used to name only `password`, so the cookie outlived
         * the password it was issued against.
         */
        AccountAccess::rotateRememberToken($user);

        // Sent to the address on the account, which is the one place a hijacked
        // session cannot reach. See PasswordChanged.
        $user->notify(new PasswordChanged($isCreating));

        return back()->with('success', $isCreating
            ? 'Your password has been created. You can now sign in with your email address or with Google.'
            : 'Your password has been updated.');
    }
}
