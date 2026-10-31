<?php

namespace App\Support;

use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The field office a field-office user is working as right now.
 *
 * Someone who heads a second office by designation in IMIS (a concurrent
 * Director) works as one office at a time: IMIS names the offices at sign-in,
 * the person chooses one before anything opens (EnsureFieldOfficeChosen), the
 * session keeps the choice, and `User::scopedFieldOfficeId()` answers with it — so every list, export and detail view stays scoped to a single
 * office exactly as before. Everyone else has one office and none of this
 * applies. Only the signed-in user's own session is ever consulted.
 */
class ActingFieldOffice
{
    private const KEY = 'acting_field_office';

    /**
     * Remember the offices IMIS allows. None is active yet: the person picks
     * one on the next screen, and until then nothing is shown to them.
     *
     * @param  list<int>  $allowed  office ids
     */
    public static function remember(array $allowed): void
    {
        $allowed = array_values(array_unique(array_map('intval', $allowed)));

        if (count($allowed) < 2) {
            session()->forget(self::KEY);

            return;
        }

        session()->put(self::KEY, ['allowed' => $allowed, 'active' => null]);
    }

    /**
     * The office the user is working as, when they have more than one.
     */
    public static function activeId(User $user): ?int
    {
        $state = self::state($user);

        return $state['active'] ?? null;
    }

    /**
     * Whether the user holds several offices and has not chosen one yet.
     */
    public static function needsChoice(User $user): bool
    {
        $state = self::state($user);

        return $state !== null && $state['active'] === null;
    }

    /**
     * Switch to another of the user's offices. False when it is not one of them.
     */
    public static function switchTo(User $user, int $officeId): bool
    {
        $state = self::state($user);

        if ($state === null || ! in_array($officeId, $state['allowed'], true)) {
            return false;
        }

        session()->put(self::KEY, ['allowed' => $state['allowed'], 'active' => $officeId]);

        return true;
    }

    /**
     * What the header switcher shows; null for the many with a single office.
     *
     * @return array{active: int|null, offices: list<array{id: int, name: string, code: string}>}|null
     */
    public static function options(User $user): ?array
    {
        $state = self::state($user);

        if ($state === null) {
            return null;
        }

        $offices = FieldOffice::whereIn('id', $state['allowed'])->get(['id', 'name', 'code'])->keyBy('id');

        return [
            'active' => $state['active'],
            'offices' => collect($state['allowed'])
                ->filter(fn (int $id) => $offices->has($id))
                ->map(fn (int $id) => ['id' => $id, 'name' => $offices[$id]->name, 'code' => $offices[$id]->code])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{allowed: list<int>, active: int|null}|null
     */
    private static function state(User $user): ?array
    {
        if (! $user->isScopedToFieldOffice() || Auth::id() !== $user->getKey() || ! request()->hasSession()) {
            return null;
        }

        $state = request()->session()->get(self::KEY);

        if (! is_array($state) || ! is_array($state['allowed'] ?? null)) {
            return null;
        }

        $active = $state['active'] ?? null;

        return $active === null || in_array($active, $state['allowed'], true)
            ? ['allowed' => $state['allowed'], 'active' => $active]
            : null;
    }
}
