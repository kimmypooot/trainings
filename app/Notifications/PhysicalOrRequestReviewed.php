<?php

namespace App\Notifications;

use App\Enums\PhysicalOrRequestStatus;
use App\Models\PhysicalOrRequest;

/**
 * Sent on every stage change, not only at the end.
 *
 * v1's refunds notified at each step and that behaviour is worth keeping: a
 * request can sit with the courier for a week, and silence during that stretch
 * is what makes participants ring HRD. The stage names themselves are
 * internal, so the wording comes from PhysicalOrRequestStatus::participantMessage().
 */
class PhysicalOrRequestReviewed extends ParticipantNotification
{
    public function __construct(private readonly PhysicalOrRequest $request) {}

    public function title(object $notifiable): string
    {
        return match ($this->request->status) {
            PhysicalOrRequestStatus::Delivered => 'Your physical official receipt has been delivered',
            PhysicalOrRequestStatus::Rejected => 'Your physical OR request was declined',
            PhysicalOrRequestStatus::Shipped => 'Your official receipt is on its way',
            default => "Physical OR request {$this->request->request_code} update",
        };
    }

    public function body(object $notifiable): string
    {
        $fee = number_format((float) $this->request->courier_fee, 2);
        $body = "{$this->request->status->participantMessage()} (PHP {$fee}, ref. {$this->request->request_code})";

        $request = $this->request;

        // A decline is the one stage where the reason is the whole message, so
        // it is always appended; mid-pipeline notes are internal.
        if ($request->status === PhysicalOrRequestStatus::Rejected && filled($request->rejection_reason)) {
            return "{$body} Reason: {$request->rejection_reason}";
        }

        // Shipping info is exactly what the participant wants the moment the
        // receipt leaves the office — without it, the "on its way" title is a
        // tease.
        if ($request->status === PhysicalOrRequestStatus::Shipped && filled($request->tracking_number)) {
            return "{$body} Courier: {$request->courier_name}, tracking {$request->tracking_number}.";
        }

        return $body;
    }

    public function url(object $notifiable): string
    {
        return route('physical-or.index');
    }
}
