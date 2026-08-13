<?php

namespace App\Enums;

/**
 * How demanding a training is, ported from v1's free-text `trainings.level`.
 *
 * v1 let HRD type anything here, which meant "Basic", "basic" and "Foundational"
 * all coexisted and none of them could be reported on. The vocabulary HRD
 * actually used was these three, so it is fixed here — a level that cannot be
 * grouped is a level that cannot appear in a report.
 *
 * Nullable on the column: a draft may not have decided yet.
 */
enum TrainingLevel: string
{
    case Foundational = 'foundational';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $level) => ['value' => $level->value, 'label' => $level->label()],
            self::cases()
        );
    }
}
