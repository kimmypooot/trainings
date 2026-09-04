<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * The authentication log the application did not have.
 *
 * `users.last_login_at` was the whole of it — a single column, overwritten on
 * every sign-in. That answers "is this account dormant" and nothing else. It
 * cannot answer how many failed attempts preceded a compromise, whether an
 * account was signed into from somewhere unusual, or when somebody was locked
 * out, which are the three questions asked after an incident and the reason a
 * government system is expected to keep an authentication trail at all.
 *
 * Deliberately a *log channel* rather than rows in `activity_logs`, and the
 * reasoning is `LoginController`'s own: v1's activity log was mostly
 * login/logout pairs, and that volume is exactly what buried the decisions
 * worth auditing. Sign-ins are high-volume and uninteresting individually;
 * administrative decisions are low-volume and interesting individually. Mixing
 * them makes the second unreadable. They are separate stores because they are
 * different kinds of record, retained on different schedules — see the `auth`
 * channel in config/logging.php.
 *
 * Method names avoid the `handle` prefix on purpose: Laravel discovers
 * listeners by scanning app/Listeners for `handle*` methods and reading their
 * first parameter type, so a `handleLogin(Login $event)` here would be
 * registered by discovery *and* by the subscriber below — and every sign-in
 * would be written to the log twice. Verified, because it happened.
 *
 * Written as a subscriber on the framework's own auth events rather than as
 * calls inside LoginController, because the controller is not the only door:
 * a Google sign-in goes through GoogleController, and a "remember me" sign-in
 * happens inside SessionGuard without reaching either. Listening to the events
 * catches all three by construction.
 */
class RecordAuthenticationEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'whenSignedIn']);
        $events->listen(Failed::class, [self::class, 'whenSignInFailed']);
        $events->listen(Lockout::class, [self::class, 'whenLockedOut']);
        $events->listen(Logout::class, [self::class, 'whenSignedOut']);
        $events->listen(PasswordReset::class, [self::class, 'whenPasswordWasReset']);
        $events->listen(Registered::class, [self::class, 'whenRegistered']);
    }

    public function whenSignedIn(Login $event): void
    {
        $this->write('login', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->getAttribute('email'),
            'role' => $event->user->getAttribute('role')?->value,
            // True when the session was rebuilt from a recaller cookie rather
            // than from a password. Worth separating: it is the path that
            // bypasses LoginController entirely, and the one an attacker
            // holding a stolen cookie would appear on.
            'via_remember' => auth()->viaRemember(),
        ]);
    }

    public function whenSignInFailed(Failed $event): void
    {
        $this->write('login.failed', [
            // The address only. `Failed::$credentials` carries the password —
            // it is marked #[\SensitiveParameter] for exactly that reason — and
            // a failed attempt is very often a real password typed against the
            // wrong account, so logging the array would put working
            // credentials in a file with a wider audience than the database.
            'email' => $event->credentials['email'] ?? null,
            // Whether the address matched an account at all. This is the
            // difference between "somebody mistyped their own password" and
            // "somebody is walking a list of addresses", and it is not
            // recoverable from the log afterwards.
            'account_exists' => $event->user !== null,
        ]);
    }

    public function whenLockedOut(Lockout $event): void
    {
        $this->write('login.lockout', [
            'email' => $event->request->input('email'),
        ]);
    }

    public function whenSignedOut(Logout $event): void
    {
        /*
         * The nullsafe operators are load-bearing, whatever PHPStan says.
         *
         * Laravel annotates Logout::$user as non-nullable, so the analyser
         * reports `?->` here as unnecessary — and it is wrong. `POST /logout`
         * carries no auth middleware, and SessionGuard::logout() reads the user
         * *before* clearing storage and dispatches the event with whatever it
         * found. A guest posting to that route therefore fires Logout with a
         * null user; verified against a running application, not assumed.
         * Taking the operators off would turn a harmless request into a fatal.
         *
         * Ignored in phpstan.neon rather than baselined, on the same reasoning
         * as the DB::transaction() entries there: a baseline entry is a promise
         * to come back and fix something, and there is nothing here to fix.
         */
        $this->write('logout', [
            'user_id' => $event->user?->getAuthIdentifier(),
            'email' => $event->user?->getAttribute('email'),
        ]);
    }

    public function whenPasswordWasReset(PasswordReset $event): void
    {
        $this->write('password.reset', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->getAttribute('email'),
        ]);
    }

    public function whenRegistered(Registered $event): void
    {
        $this->write('registered', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->getAttribute('email'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $event, array $context): void
    {
        /*
         * The address is only meaningful because config/trustedproxy.php now
         * works. While proxy trust was inert every request behind a load
         * balancer reported the proxy's address, which would have made this
         * whole log a column of one repeated value.
         */
        Log::channel('auth')->info($event, [
            ...$context,
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
