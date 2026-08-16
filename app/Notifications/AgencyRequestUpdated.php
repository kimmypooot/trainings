<?php

namespace App\Notifications;

use App\Enums\AgencyRequestStatus;
use App\Models\AgencyRequest;

/**
 * Tells the requesting agency where their request has got to.
 *
 * Sent on the moves HRD makes, not on the agency's own — someone who has just
 * uploaded a confirmation form does not need an email telling them they
 * uploaded a confirmation form.
 */
class AgencyRequestUpdated extends ParticipantNotification
{
    public function __construct(private readonly AgencyRequest $request) {}

    public function title(object $notifiable): string
    {
        return match ($this->request->status) {
            AgencyRequestStatus::RequirementsSent => "Requirements for {$this->request->request_code}",
            AgencyRequestStatus::Completed => "{$this->request->request_code} is complete",
            AgencyRequestStatus::Rejected => "{$this->request->request_code} was not approved",
            default => "{$this->request->request_code} update",
        };
    }

    public function body(object $notifiable): string
    {
        $body = "“{$this->request->training_title}” — {$this->request->status->requesterMessage()}";

        // The reason is the whole message on a decline, so it is always
        // appended there; the requirements text likewise is the point of that
        // particular mail rather than incidental detail.
        $detail = match ($this->request->status) {
            AgencyRequestStatus::Rejected => $this->request->rejection_reason,
            AgencyRequestStatus::RequirementsSent => $this->request->requirements_text,
            default => null,
        };

        return $detail ? "{$body}\n\n{$detail}" : $body;
    }

    public function url(object $notifiable): string
    {
        return route('agency-requests.index');
    }

    public function action(object $notifiable): ?string
    {
        return 'View request';
    }
}
