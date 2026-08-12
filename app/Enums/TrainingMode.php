<?php

namespace App\Enums;

/**
 * How a training is delivered. Ported from v1's `trainings.mode`.
 *
 * Venue stays required for every mode — for online runs it holds the platform
 * or meeting link, which is what participants actually need at the door.
 */
enum TrainingMode: string
{
    case FaceToFace = 'face_to_face';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::FaceToFace => 'Face to Face',
            self::Online => 'Online',
            self::Hybrid => 'Hybrid',
        };
    }

    /** Modes where people physically show up, so a venue address is meaningful. */
    public function isOnSite(): bool
    {
        return $this !== self::Online;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases()
        );
    }
}
