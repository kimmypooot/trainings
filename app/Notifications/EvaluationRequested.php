<?php

namespace App\Notifications;

use App\Models\Registration;
use App\Models\SubjectMatterExpert;
use Illuminate\Support\Collection;

/**
 * Sent at the end of a training day, asking the participant to rate the experts
 * who delivered it.
 *
 * Names the experts in the body. The point of the message is to be answered on
 * the evening it arrives, while the room is still fresh, and "evaluate today's
 * session" asks somebody who attended three sessions this month to work out
 * which one is meant.
 */
class EvaluationRequested extends ParticipantNotification
{
    /**
     * @param  Collection<int, SubjectMatterExpert>  $experts
     */
    public function __construct(
        private readonly Registration $registration,
        private readonly int $day,
        private readonly Collection $experts,
    ) {}

    public function title(object $notifiable): string
    {
        $training = $this->registration->training;

        return $training->duration_days > 1
            ? "How was day {$this->day} of “{$training->title}”?"
            : "How was “{$training->title}”?";
    }

    public function body(object $notifiable): string
    {
        $names = $this->experts
            ->map(fn (SubjectMatterExpert $expert) => $expert->displayName())
            ->all();

        return sprintf(
            'Please take a moment to evaluate %s. It takes about a minute, and what you write goes to the training staff — not to the resource person during the session.',
            match (count($names)) {
                0 => 'today’s session',
                1 => $names[0],
                2 => implode(' and ', $names),
                default => implode(', ', array_slice($names, 0, -1)).' and '.end($names),
            }
        );
    }

    public function url(object $notifiable): string
    {
        return route('evaluations.show', [
            'registration' => $this->registration->getKey(),
            'day' => $this->day,
        ]);
    }

    public function action(object $notifiable): ?string
    {
        return 'Evaluate this session';
    }
}
