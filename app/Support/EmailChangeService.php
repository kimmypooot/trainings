<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Moving an account to a different email address.
 *
 * The feature exists because the address *is* the account: it is what a
 * participant signs in with, and it is where every certificate notice, payment
 * receipt and event reminder is sent. When someone transfers out of the agency
 * whose inbox they registered with, that address stops being readable — and
 * until now their only self-service route back into the system was to register
 * again, under a second account, leaving their training history stranded behind
 * an address nobody can open. The duplicate was the visible symptom; the
 * silent one was an account that had quietly stopped receiving anything.
 *
 * Two rules shape the whole flow:
 *
 * 1. The address does not change until the participant proves they can read the
 *    new one. Anything else lets a typo move an account somewhere unreachable —
 *    the exact failure this is meant to end — and there would be no way back,
 *    because the recovery mail would go to the wrong inbox too.
 *
 * 2. The *old* address is always told. A hijacked session controls the app but
 *    not the inbox, and quietly relocating an account is how a session becomes
 *    permanent ownership. Same argument PasswordChanged is built on.
 */
class EmailChangeService
{
    /** How long the confirmation link stays valid, matching VerifyEmail. */
    public const LINK_TTL_MINUTES = 60;

    /**
     * Ask to move to `$email`. Sends the link; changes nothing yet.
     *
     * @throws ValidationException when the address is already spoken for
     */
    public static function request(User $user, string $email): void
    {
        // `trim`, not `mb_trim`: composer.json allows PHP 8.3, where mb_trim
        // does not exist yet.
        $email = trim($email);

        // Checked here as well as in the request rules, because this is the
        // choke point both the participant's form and any later caller pass
        // through — and because the answer can change between the two.
        self::guardAvailability($user, $email);

        $user->forceFill([
            'pending_email' => $email,
            'pending_email_requested_at' => now(),
        ])->save();

        /*
         * On-demand, because the whole point is to reach an address the account
         * does not have yet — `notify()` would send it to the current one, which
         * proves nothing about the new inbox.
         */
        Notification::route('mail', $email)->notify(new ConfirmEmailChange($user, $email));

        // And the security notice, to the address being left behind.
        $user->notify(new EmailChangeRequested($email));

        ActivityLogger::record(
            'email_change.requested',
            $user,
            'Requested a change of email address.',
            // The new address only; the old one is on the record already, and
            // the audit trail should not become a second place it is stored.
            ['to' => $email],
        );
    }

    /**
     * Complete the move, having proved the new address is readable.
     *
     * Returns false when there is nothing pending — a link clicked twice, or
     * one that outlived the request it belonged to.
     */
    public static function confirm(User $user, string $email): bool
    {
        return DB::transaction(function () use ($user, $email) {
            $user = User::whereKey($user->getKey())->lockForUpdate()->first();

            if (! $user || ! self::isPending($user, $email)) {
                return false;
            }

            // Re-checked under the lock. The link has been sitting in an inbox,
            // and the address may have been claimed in the meantime — by a new
            // registration, or by this participant's own second request.
            self::guardAvailability($user, $email);

            $from = $user->email;

            $user->forceFill([
                'email' => $email,
                'pending_email' => null,
                'pending_email_requested_at' => null,
                // Clicking the link is the proof the verification email exists
                // to collect, so asking for it again would be asking twice.
                'email_verified_at' => now(),
            ])->save();

            ActivityLogger::record(
                'email_change.completed',
                $user,
                'Changed the account email address.',
                ['from' => $from, 'to' => $email],
            );

            return true;
        });
    }

    /** Abandon a pending change. */
    public static function cancel(User $user): void
    {
        if (blank($user->pending_email)) {
            return;
        }

        $user->forceFill([
            'pending_email' => null,
            'pending_email_requested_at' => null,
        ])->save();

        ActivityLogger::record('email_change.cancelled', $user, 'Cancelled a pending email change.');
    }

    /**
     * Whether `$email` is the address this user is currently waiting on.
     *
     * The expiry is enforced here rather than left to the signed URL alone, so
     * a pending change that has gone stale reads the same way to the screen, to
     * the link, and to a resend.
     */
    public static function isPending(User $user, string $email): bool
    {
        if (blank($user->pending_email) || ! self::sameAddress($user->pending_email, $email)) {
            return false;
        }

        return $user->pending_email_requested_at?->gt(now()->subMinutes(self::LINK_TTL_MINUTES)) === true;
    }

    /**
     * Refuse an address that belongs to somebody else — or to this account.
     *
     * @throws ValidationException
     */
    private static function guardAvailability(User $user, string $email): void
    {
        if (self::sameAddress($user->email, $email)) {
            throw ValidationException::withMessages([
                'email' => 'That is already the email address on your account.',
            ]);
        }

        $taken = User::where('email', $email)->whereKeyNot($user->getKey())->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'That email address is already in use by another account.',
            ]);
        }
    }

    /**
     * Addresses compared the way the database compares them.
     *
     * The `users.email` index is case-insensitive under the schema's collation,
     * so treating JUAN@x and juan@x as different here would let the service
     * disagree with the constraint that actually decides.
     */
    private static function sameAddress(?string $a, ?string $b): bool
    {
        return mb_strtolower((string) $a) === mb_strtolower((string) $b);
    }
}
