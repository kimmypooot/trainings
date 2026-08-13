<?php

namespace App\Support;

use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Short-lived, server-held undo for staff decisions on the roster.
 *
 * The snapshot lives in the actor's own session, never in the page payload:
 * an undo token that travelled through the browser would let a client name the
 * rows to restore and the state to restore them to. Here the token is an opaque
 * key and the server already knows what it means.
 *
 * Only fields a decision actually writes are captured, so restoring cannot
 * quietly revert something that changed for an unrelated reason.
 */
class UndoService
{
    /**
     * How long an undo stays offered.
     *
     * Also the delay on the participant's notification — the point of the
     * window is that an undone decision is one the participant never hears
     * about, so the mail must not leave before the window closes.
     */
    public const WINDOW_SECONDS = 30;

    /*
     * No dot in the prefix on purpose. Session keys treat dots as nesting, so
     * "undo.{token}" would live under an "undo" parent — the very key the flash
     * payload writes to, which would drop the snapshot on the way out.
     */
    private const SESSION_PREFIX = 'undo_snapshot:';

    /** @var array<int, string> */
    private const TRACKED = ['status', 'review_remarks', 'reviewed_by', 'reviewed_at', 'attended_at'];

    /**
     * Capture the state of these registrations before they are changed.
     *
     * @param  Collection<int, Registration>  $registrations
     */
    public static function capture(Collection $registrations): array
    {
        return $registrations
            ->map(fn (Registration $registration) => [
                'id' => $registration->getKey(),
                'attributes' => collect(self::TRACKED)
                    ->mapWithKeys(fn (string $field) => [$field => $registration->getOriginal($field)])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Offer an undo for a captured snapshot, returning the token to flash.
     *
     * Returns null when nothing was actually changed — an action that applied
     * to no rows has nothing to take back, and offering it anyway would make
     * the button a lie.
     */
    public static function offer(User $actor, string $label, array $snapshot): ?string
    {
        if ($snapshot === []) {
            return null;
        }

        $token = (string) Str::uuid();

        session()->put(self::SESSION_PREFIX.$token, [
            'user_id' => $actor->getKey(),
            'label' => $label,
            'snapshot' => $snapshot,
            'expires_at' => now()->addSeconds(self::WINDOW_SECONDS)->timestamp,
        ]);

        return $token;
    }

    /**
     * Restore a snapshot.
     *
     * @return array{label: string, count: int}|null Null when the token is
     *                                               unknown, expired, or belongs to someone else.
     */
    public static function apply(string $token, User $actor): ?array
    {
        $key = self::SESSION_PREFIX.$token;
        $entry = session()->get($key);

        // One use only, whatever the outcome — a token that survived a failed
        // attempt would be a second chance at someone else's session.
        session()->forget($key);

        if (! $entry
            || $entry['user_id'] !== $actor->getKey()
            || $entry['expires_at'] < now()->timestamp) {
            return null;
        }

        $restored = 0;

        foreach ($entry['snapshot'] as $row) {
            $registration = Registration::find($row['id']);

            if (! $registration) {
                continue;
            }

            $registration->forceFill($row['attributes'])->save();
            $restored++;
        }

        return ['label' => $entry['label'], 'count' => $restored];
    }
}
