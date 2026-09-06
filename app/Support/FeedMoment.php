<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * When something happened, in the four forms a feed entry needs it.
 *
 * The dashboard's activity feed and the notifications list are two renderings
 * of the same events, and this is the part they must agree on: a registration
 * approved this morning has to read "Today" on both, or a participant checking
 * one against the other is looking at a discrepancy that is not there.
 *
 * It was private to DashboardController while the feed was the only caller.
 * Copying it into the notifications controller would have been two clocks —
 * cheap to write and impossible to keep in step, since the interesting part is
 * not the formatting but the *window*, and a window changed in one place is a
 * silent disagreement rather than an error.
 */
class FeedMoment
{
    /**
     * The window is measured on the absolute gap, which is not fussiness.
     *
     * Carbon 3's diff methods return a *signed* value, so a timestamp in the
     * future is negative and passes any `< 7` test however far out it is: a
     * row dated next month sorted to the top of the feed, landed under "This
     * week", and read "2 weeks from now" — a history of something that has
     * not happened. Seeded demo data has such rows today, and real data can
     * get them from a clock skew or a backdated import, so the reading has to
     * hold either way.
     *
     * @return array{at: ?string, at_label: ?string, at_exact: ?string, group: string}
     */
    public static function for(?CarbonInterface $at): array
    {
        $withinTheWeek = $at !== null && abs($at->diffInDays(now())) < 7;

        return [
            'at' => $at?->toIso8601String(),
            'at_label' => $at === null
                ? null
                : ($withinTheWeek ? $at->diffForHumans() : $at->format('d M Y')),
            'at_exact' => $at?->format('d M Y, g:i A'),
            'group' => match (true) {
                $at === null => 'Earlier',
                $at->isToday() => 'Today',
                $at->isYesterday() => 'Yesterday',
                $withinTheWeek => 'This week',
                default => 'Earlier',
            },
        ];
    }
}
