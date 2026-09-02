<?php

namespace App\Enums;

/**
 * What happened when somebody scanned a training day's evaluation code.
 *
 * An enum rather than a loose string because both ends need it: the controller
 * decides between redirecting and rendering on it, and the outcome page picks an
 * icon, a tone and an offer from it. `php artisan tims:types` carries the cases
 * across to TypeScript, so the two cannot come to disagree about the spelling of
 * a state — which is exactly the seam where a renamed case would otherwise show
 * up as a blank page rather than an error.
 *
 * Only two of these are successes, and the other four are all some flavour of
 * "no": kept apart rather than collapsed into one failure case because the
 * participant is owed a different sentence in each, and the office is owed the
 * ability to tell "the wrong people are scanning this" from "this poster is out
 * of date".
 */
enum EvaluationScanOutcome: string
{
    /** The day is open and unanswered. Straight to the form. */
    case Open = 'open';

    /** Already answered. Still the form — amending is allowed by design. */
    case Submitted = 'submitted';

    /** The day exists but cannot be evaluated yet, or at all. Carries a reason. */
    case Blocked = 'blocked';

    /** Signed in, but not on this training's roster. */
    case NotRegistered = 'not_registered';

    /** The run was shortened and this day is no longer part of it. */
    case NoLongerScheduled = 'no_longer_scheduled';

    /** Withdrawn by staff. Reads identically to an unknown token. */
    case Revoked = 'revoked';

    /** Whether this outcome puts the participant into the form. */
    public function opensForm(): bool
    {
        return in_array($this, [self::Open, self::Submitted], true);
    }

    /**
     * The headline for the outcome page.
     *
     * Written from the participant's side of the desk. "Not registered" is a
     * fact about a database row; "You are not on this training's roster" is what
     * a person standing in a function room needs to hear.
     */
    public function title(): string
    {
        return match ($this) {
            self::Open, self::Submitted => 'Opening your evaluation',
            self::Blocked => 'This form is not open yet',
            self::NotRegistered => 'You are not on this training',
            self::NoLongerScheduled => 'This day is no longer part of the training',
            self::Revoked => 'This code is no longer in use',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $outcome) => ['value' => $outcome->value, 'label' => $outcome->title()],
            self::cases()
        );
    }
}
