<?php

namespace App\Enums;

/**
 * Ported from v1's `attendance.status`.
 *
 * A scan can only ever produce Present or Late — the clock decides which.
 * Absent and Excused are staff judgements, so they are set from the roster.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Statuses that count toward completing a training.
     *
     * Excused counts: the participant was accounted for and CSC does not
     * penalise an approved absence when releasing certificates.
     *
     * @return array<int, self>
     */
    public static function crediting(): array
    {
        return [self::Present, self::Late, self::Excused];
    }

    public function credits(): bool
    {
        return in_array($this, self::crediting(), true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases()
        );
    }
}
