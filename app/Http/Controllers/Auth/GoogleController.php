<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\ImportGoogleAvatar;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleController extends Controller
{
    /**
     * Session key holding a pending "connect this account" intent.
     *
     * Google sends the browser back to one callback URL for both flows, so the
     * intent has to be parked somewhere the callback can find it. The session
     * is the right somewhere: it is already what Socialite's stateful CSRF
     * check uses, it cannot be forged by the round trip through Google, and it
     * dies with the session.
     */
    private const LINK_INTENT = 'google.link_intent';

    /** How long a started connect flow stays valid. */
    private const LINK_INTENT_TTL_MINUTES = 10;

    /**
     * Marks that a sign-in has already been restarted once after a lost state.
     *
     * Socialite compares a `state` value it parked in the session against the
     * one Google echoes back, and the comparison fails whenever the callback
     * lands on a *different* session than the one that began the flow — most
     * often because the browser was on a different host than the configured
     * redirect URI (127.0.0.1 vs localhost in development), so the cookie the
     * state lives in is simply not sent. Nothing is wrong with the account and
     * nothing is wrong with the consent; the round trip merely has to be made
     * again from the host the callback actually arrives on, which the restart
     * below does silently. Without it the participant meets the login page,
     * clicks the same button a second time, and it works — which reads as the
     * system asking them to sign in twice.
     *
     * The marker is what stops that recovery from becoming a redirect loop
     * against Google when the cause is something a retry cannot fix.
     */
    private const STATE_RETRY = 'google.state_retry';

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
     * Begin connecting a Google account to the signed-in TIMS account.
     *
     * The entry point for participants who registered with an email address
     * and a password: the account already exists, so this flow attaches an
     * identity to it rather than creating one or signing anybody in.
     */
    public function link(Request $request): RedirectResponse
    {
        if (! config('services.google.client_id')) {
            return back()->with('error', 'Google sign-in is not configured on this server yet.');
        }

        if ($request->user()->hasGoogleAccount()) {
            return back()->with('error', 'Your account is already connected to Google.');
        }

        $request->session()->put(self::LINK_INTENT, [
            // Pinned to the user who started it. A session that changes hands
            // mid-flow (sign out, sign in as someone else, come back) must not
            // land the identity on the wrong account.
            'user_id' => $request->user()->getKey(),
            'expires_at' => now()->addMinutes(self::LINK_INTENT_TTL_MINUTES)->timestamp,
        ]);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Disconnect the Google account.
     *
     * Refused when Google is the only way in. Accounts created through Google
     * have no password — `password` is nullable precisely for them — and
     * disconnecting one would lock the participant out of a system holding
     * their training records.
     */
    public function unlink(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasGoogleAccount()) {
            return back()->with('error', 'Your account is not connected to Google.');
        }

        if (! $user->hasPassword()) {
            return back()->with(
                'error',
                'Create a password first, from Create Password in the account menu — Google is currently '.
                'the only way to sign in to this account.'
            );
        }

        // The photo is deliberately left alone. It was copied into this system
        // when the account was connected and has been the participant's own
        // photo ever since — disconnecting a sign-in method is no reason to
        // delete it. They can remove it themselves on the same page.
        // The URL goes even though the photo stays: it points at an account
        // this one is no longer connected to, and keeping a Google address for
        // a disconnected identity is retaining a record of it for nothing.
        $user->forceFill([
            'google_id' => null,
            'google_email' => null,
            'google_avatar_url' => null,
        ])->save();

        return back()->with('success', 'Your Google account has been disconnected.');
    }

    /**
     * Handle the callback from Google.
     *
     * Participants self-register, so an unrecognised Google account creates a
     * TIMS account rather than being turned away. An existing account matched
     * by email gets its Google identity linked on first use.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Taken, not read: whichever way this callback goes, the intent is
        // spent. Leaving it behind would let an ordinary Google sign-in some
        // minutes later be mistaken for the tail of a connect flow.
        $intent = $request->session()->pull(self::LINK_INTENT);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google OAuth callback failed', ['exception' => $e]);

            if ($intent) {
                return redirect()->route('profile.edit')
                    ->with('error', 'We could not complete the connection to Google. Please try again.');
            }

            // A lost state is recoverable, so recover from it rather than
            // handing the participant an error and letting them do by hand
            // what we can do for them. `pull` both reads and clears: a second
            // failure in a row falls through to the message below, so the
            // restart happens at most once per attempt.
            if ($e instanceof InvalidStateException && ! $request->session()->pull(self::STATE_RETRY, false)) {
                $request->session()->put(self::STATE_RETRY, true);

                return redirect()->route('auth.google');
            }

            return redirect()->route('login')->withErrors([
                'form' => 'We could not complete the Google sign-in. Please try again.',
            ]);
        }

        // Spent. Leaving it set would cost the *next* lost state its one
        // restart, turning an otherwise recoverable failure into an error.
        $request->session()->forget(self::STATE_RETRY);

        if ($intent) {
            return $this->completeLink($request, $intent, $googleUser);
        }

        // A known Google identity is matched on `google_id` alone. That linkage
        // was established deliberately — by a connect flow, or by an earlier
        // sign-in that passed the check below — and the id is stable, so no
        // further proof is needed to honour it.
        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user) {
            // No known identity, so the *email address* is about to decide
            // which account this is — or create one. That is only sound if
            // Google says it confirmed the address. Without this check,
            // controlling any Google account that merely claims a TIMS user's
            // address is enough to sign in as them, with no password.
            if (! self::googleConfirmedTheEmail($googleUser)) {
                Log::warning('Google sign-in refused: unconfirmed email claim', [
                    'google_id' => $googleUser->getId(),
                ]);

                return redirect()->route('login')->withErrors([
                    'form' => 'Google has not confirmed the email address on that account, '.
                        'so it cannot be used to sign in. Verify it with Google and try again.',
                ]);
            }

            $user = User::where('email', $googleUser->getEmail())->first() ?? new User;
        }

        // Captured before the fill, while `google_id` still says whether this
        // identity was already attached. The photo is imported only on the
        // first attach — see ImportGoogleAvatar.
        $isFirstAttach = blank($user->google_id);

        $user->forceFill([
            'name' => $user->name ?: ($googleUser->getName() ?: $googleUser->getNickname()),
            'email' => $user->email ?: $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'google_email' => $googleUser->getEmail(),
            'google_avatar_url' => $googleUser->getAvatar(),
            // Same rule as the connect flow: a Google account only vouches for
            // the TIMS address when it carries that address *and* Google says
            // it confirmed it. A participant signing in with a personal Gmail
            // against an agency registration still owes us the verification
            // email.
            'email_verified_at' => $user->email_verified_at
                ?? (self::googleVouchesFor($googleUser, $user->email ?: $googleUser->getEmail()) ? now() : null),
        ])->save();

        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'form' => 'This account has been deactivated. Contact the CSC administrator.',
            ]);
        }

        if ($isFirstAttach) {
            self::importAvatar($user, $googleUser->getAvatar());
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Kept in step with LoginController: the column is what tells staff a
        // account has gone dormant, and it would quietly stop being true for
        // anyone who signs in with Google rather than a password.
        $user->forceFill(['last_login_at' => now()])->save();

        // Staff have their own shell and are not asked to complete a
        // participant profile, so they are sent to it before the gate below —
        // exactly as the password sign-in does. Some staff authenticate with
        // Google (EnsureSiteIsAvailable exempts the route for that reason),
        // and without this they land on the participant dashboard.
        if ($user->role->isStaff()) {
            return redirect()->intended(route('admin.dashboard'));
        }

        // A Google sign-up never sees the registration form, so it lands on the
        // same profile gate as an email sign-up.
        if (! $user->hasCompletedProfile()) {
            return redirect()->route('profile.complete');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Finish a connect flow started by `link()`.
     *
     * Deliberately never signs anybody in and never creates an account: this
     * path only ever attaches an identity to the account that asked for it, so
     * a failure here is an error message on the profile page, not a login.
     *
     * @param  array{user_id: int, expires_at: int}  $intent
     */
    private function completeLink(Request $request, array $intent, mixed $googleUser): RedirectResponse
    {
        $failed = fn (string $message) => redirect()->route('profile.edit')->with('error', $message);

        if (($intent['expires_at'] ?? 0) < now()->timestamp) {
            return $failed('That connection request expired. Please try again.');
        }

        // The session must still belong to the user who started the flow.
        if ($request->user()?->getKey() !== ($intent['user_id'] ?? null)) {
            return $failed('That connection request is no longer valid. Please try again.');
        }

        $user = $request->user();

        // One Google identity, one TIMS account. Without this a participant
        // could attach their Google account to a second registration and end
        // up with two sets of training records reachable by one sign-in.
        $taken = User::where('google_id', $googleUser->getId())
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($taken) {
            return $failed('That Google account is already connected to another TIMS account.');
        }

        // The addresses are deliberately not required to match. Participants
        // register with the agency address the CSC corresponds with and sign in
        // with a personal Gmail; demanding they be the same would restrict the
        // feature to agencies on Google Workspace, which is most of them not.
        //
        // What keeps that safe is that connecting is not an email change: the
        // TIMS address is never touched here, so the account keeps the identity
        // the office knows it by and only gains a second way to sign in.
        $user->forceFill([
            'google_id' => $googleUser->getId(),
            'google_email' => $googleUser->getEmail(),
            'google_avatar_url' => $googleUser->getAvatar(),
            // Only a Google account carrying the *same* address, confirmed by
            // Google, proves control of that address. Connecting a personal
            // Gmail proves control of the Gmail and says nothing about the
            // agency inbox, so it must not stand in for the verification email.
            'email_verified_at' => $user->email_verified_at
                ?? (self::googleVouchesFor($googleUser, $user->email) ? now() : null),
        ])->save();

        // Connecting is always a first attach — `link()` refuses an account
        // that already has an identity.
        self::importAvatar($user, $googleUser->getAvatar());

        return redirect()->route('profile.edit')->with(
            'success',
            'Connected to '.$googleUser->getEmail().'. You can sign in with Google from now on.'
        );
    }

    /**
     * Queue a copy of the Google photo, if there is one and no photo already.
     *
     * Both conditions are re-checked inside the job — this is only about not
     * queueing obvious no-ops.
     */
    private static function importAvatar(User $user, ?string $avatarUrl): void
    {
        if (blank($avatarUrl) || filled($user->avatar_path)) {
            return;
        }

        ImportGoogleAvatar::dispatch($user->getKey(), $avatarUrl);
    }

    /**
     * Whether Google says it confirmed the address on the account.
     *
     * OpenID Connect carries this as `email_verified`; Google's older userinfo
     * endpoint spelled it `verified_email`, and Socialite has used both across
     * versions, so both are read. The claim arrives as a real boolean or as a
     * string depending on the endpoint — hence the loose parse rather than a
     * identity comparison.
     *
     * Absent or unparseable counts as *not* verified. This gate only ever
     * stands between a stranger and an account, so the safe default when we
     * cannot tell is to refuse.
     */
    private static function googleConfirmedTheEmail(mixed $googleUser): bool
    {
        $raw = (array) ($googleUser->user ?? []);

        $claim = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        return filter_var($claim, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Whether this Google account is proof of control of `$email`.
     *
     * Both halves are required: the addresses must be the same one, and Google
     * must have confirmed it. Used wherever a Google sign-in would otherwise
     * substitute for the verification email.
     */
    private static function googleVouchesFor(mixed $googleUser, ?string $email): bool
    {
        if (blank($email) || ! self::googleConfirmedTheEmail($googleUser)) {
            return false;
        }

        return hash_equals(
            mb_strtolower($email),
            mb_strtolower((string) $googleUser->getEmail())
        );
    }
}
