<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changing the address an account lives at, from the profile page.
 *
 * The rules themselves are in EmailChangeService — this only collects the
 * request, re-authenticates the person making it, and renders the outcome.
 */
class EmailChangeController extends Controller
{
    /**
     * Ask to move the account, and send the confirmation link.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        /*
         * Read from the record before validation, exactly as
         * ChangePasswordController does: an account created through Google has
         * no password to re-enter, and requiring one it was never issued would
         * make this a dead end for the participants most likely to need it —
         * the ones who signed up with a personal Gmail. Deriving it from input
         * would let a request opt itself out of the check.
         */
        $confirmsWithPassword = $user->hasPassword();

        $validated = $request->validate([
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:255',
                // Cheap, friendly, and not the real guard: the service checks
                // again at request time and once more at confirmation, under a
                // lock, because this answer can go stale in an inbox.
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            // See the note in ChangePasswordController about 'nullable': the
            // form omits this field entirely for a Google-only account, and
            // ConvertEmptyStringsToNull would otherwise fail it against a rule
            // the participant is never shown.
            'current_password' => [Rule::requiredIf($confirmsWithPassword), 'nullable', 'string'],
        ], [
            'email.unique' => 'That email address is already in use by another account.',
            'current_password.required' => 'Enter your current password to confirm this change.',
        ]);

        if ($confirmsWithPassword && ! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        EmailChangeService::request($user, $validated['email']);

        return back()->with(
            'success',
            'Almost there — open the link we sent to '.$validated['email'].' to finish the change. '.
            'Until you do, your account keeps its current address.'
        );
    }

    /**
     * The link from the new address.
     *
     * Open to guests and signed, for the same reason `verification.verify` is:
     * the link is usually opened in whichever browser happens to have the new
     * mailbox open, which is rarely the one holding the session.
     */
    public function confirm(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        // A signed URL already settles who this is about. The hash is what ties
        // it to one *pending address*, so a cancelled or replaced request
        // cannot be completed with a link from the previous one.
        if (! $user
            || blank($user->pending_email)
            || ! hash_equals(sha1($user->pending_email), $hash)) {
            return $this->stale($request);
        }

        /*
         * The address can be registered by somebody else while the link sits in
         * an inbox, and the service refuses on that. Caught here rather than
         * left to bubble: a ValidationException redirects back with an error
         * bag, and "back" from an emailed link is a page with no form on it to
         * render the message — for a signed-out visitor, an invisible failure.
         */
        try {
            $confirmed = EmailChangeService::confirm($user, $user->pending_email);
        } catch (ValidationException) {
            return $this->finished(
                $request,
                'That email address has since been registered to another account, so the change could not be '.
                'completed. Choose a different address from your profile.'
            );
        }

        if (! $confirmed) {
            return $this->stale($request);
        }

        if ($request->user()?->is($user)) {
            return redirect()->route('profile.edit')
                ->with('success', 'Your email address has been changed to '.$user->refresh()->email.'.');
        }

        // Signed out, or signed in as somebody else: the address moved, but
        // this browser is not the one that gets let in on the strength of it.
        return redirect()->route('login')->with(
            'status',
            'Your email address has been changed. Please sign in with your new address.'
        );
    }

    /**
     * Abandon a pending change, from the profile page.
     */
    public function destroy(Request $request): RedirectResponse
    {
        EmailChangeService::cancel($request->user());

        return back()->with('success', 'The pending email change has been cancelled.');
    }

    /**
     * One reply for every dead link — expired, already used, or superseded.
     *
     * Deliberately not itemised. Telling an anonymous visitor which of those it
     * was is telling them something about an account they have not proved they
     * own, and the participant's own next step is the same in all three cases.
     */
    private function stale(Request $request): RedirectResponse
    {
        return $this->finished(
            $request,
            'That confirmation link is no longer valid. Request the change again from your profile.'
        );
    }

    /**
     * Land somewhere that can actually show `$message`.
     *
     * Signed in, that is the profile page the participant would go to anyway;
     * signed out it is the login screen. Never `back()` — an emailed link's
     * previous page is the mail client, and a flash written there is a message
     * nobody ever sees.
     */
    private function finished(Request $request, string $message): RedirectResponse
    {
        return $request->user()
            ? redirect()->route('profile.edit')->with('error', $message)
            : redirect()->route('login')->with('status', $message);
    }
}
