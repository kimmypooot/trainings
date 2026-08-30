<?php

namespace App\Support;

use App\Models\Training;

/**
 * Where a selection taken off one training can be moved to.
 *
 * A read model, not domain rules — which is why it sits here beside the other
 * query builders rather than in RegistrationService, where the transfer itself
 * lives. It is shared because two screens offer the same move: the roster
 * transfer dialog and the affected list a reschedule produces. Those two
 * offering different destinations is the bug this exists to prevent.
 */
class TransferTargets
{
    /**
     * Where a selection taken off this training can be moved to.
     *
     * Open runs only, and never this one — a transfer to the training you are
     * already on is a misclick. Shared by the roster's transfer dialog and the
     * affected list so the two cannot offer different destinations.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function for(Training $training): array
    {
        return Training::visible()
            ->whereKeyNot($training->getKey())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Training $option) => [
                'value' => $option->id,
                'label' => $option->title.' — '.$option->starts_at->format('d M Y').(
                    $option->ends_at->isSameDay($option->starts_at)
                        ? ''
                        : ' – '.$option->ends_at->format('d M Y')
                ),
            ])
            ->all();
    }
}
