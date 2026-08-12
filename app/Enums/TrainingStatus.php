<?php

namespace App\Enums;

enum TrainingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Only published trainings are visible to participants. */
    public function isOpenToParticipants(): bool
    {
        return $this === self::Published;
    }
}
