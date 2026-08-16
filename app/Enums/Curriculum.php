<?php

namespace App\Enums;

/**
 * The fixed curricula a training can belong to.
 *
 * Values are the proper-noun display text itself ("Leadership and Management"),
 * so every surface that echoes them — the catalogue card, the public modal, the
 * registration list — shows the correct casing with no mapping table.
 */
enum Curriculum: string
{
    case Technical = 'Technical';
    case LeadershipAndManagement = 'Leadership and Management';
    case Foundation = 'Foundation';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $curriculum) => ['value' => $curriculum->value, 'label' => $curriculum->label()],
            self::cases()
        );
    }
}
