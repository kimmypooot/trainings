<?php

namespace App\Enums;

/**
 * How a training is delivered. Ported from v1's `trainings.mode`.
 *
 * Venue stays required for every mode — an online run still names its platform
 * ("Zoom", "Google Meet") there. The join link itself lives in `meeting_link`,
 * which the modes below decide is mandatory.
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
     * Modes where somebody attends remotely and so needs a link to join.
     *
     * Hybrid counts: half the room is dialling in, and a hybrid run published
     * without a link is broken for exactly the people who cannot travel.
     */
    public function requiresMeetingLink(): bool
    {
        return $this !== self::FaceToFace;
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
