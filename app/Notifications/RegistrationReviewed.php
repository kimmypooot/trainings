<?php

namespace App\Notifications;

use App\Enums\RegistrationStatus;
use App\Models\PaymentSetting;
use App\Models\Registration;

/**
 * HRD has decided on a registration.
 *
 * One class rather than three: approval, waitlisting and rejection differ only
 * in wording, and splitting them would mean three places to keep in step.
 */
class RegistrationReviewed extends ParticipantNotification
{
    private readonly RegistrationStatus $decision;

    public function __construct(private readonly Registration $registration)
    {
        // Remembered at construction so the queued job can tell whether it is
        // still delivering the decision it was created for.
        $this->decision = $registration->status;
    }

    /**
     * Withheld when the decision no longer stands.
     *
     * Delivery is delayed by the undo window, so a staff member who takes a
     * decision back within it leaves nothing for the participant to read. By
     * the time this runs the registration may have moved on for any reason —
     * undone, re-decided, or cancelled — and in every one of those cases the
     * message in hand is about a decision that is no longer true.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $current = $this->registration->fresh();

        if (! $current || $current->status !== $this->decision) {
            return [];
        }

        return parent::via($notifiable);
    }

    public function title(object $notifiable): string
    {
        $training = $this->registration->training->title;

        return match ($this->registration->status) {
            RegistrationStatus::Approved => "You're confirmed for “{$training}”",
            RegistrationStatus::Waitlisted => "You're on the waitlist for “{$training}”",
            RegistrationStatus::Rejected => "Your registration for “{$training}” was not approved",
            default => "Update on your registration for “{$training}”",
        };
    }

    public function body(object $notifiable): string
    {
        $training = $this->registration->training;
        $remarks = $this->registration->review_remarks;

        $body = match ($this->registration->status) {
            RegistrationStatus::Approved => sprintf(
                'Your slot is confirmed for %s at %s. Bring your QR code — it is how we take attendance at the door.%s%s',
                $training->starts_at->format('d M Y, g:i A'),
                $training->venue,
                $this->paymentLine(),
                $this->joinLine()
            ),
            RegistrationStatus::Waitlisted => 'You will be moved up automatically if a slot frees up before the training starts.',
            RegistrationStatus::Rejected => 'Your registration was not approved on this occasion.',
            default => 'Your registration has been updated.',
        };

        // A rejection is required to carry a reason, so it always lands here.
        return $remarks ? "{$body} Remarks: {$remarks}" : $body;
    }

    /**
     * Where to deposit the fee, when approval is the step that unlocks payment.
     *
     * A paid training that still has the fee outstanding needs the account
     * details at this exact moment — the confirmation is what tells the
     * participant the fee is now due. Free trainings and already-paid
     * registrations have nothing to add.
     */
    private function paymentLine(): string
    {
        $registration = $this->registration->fresh(['training', 'payments']);

        if ($registration === null
            || ! $registration->training->payment_required
            || $registration->hasSettledFee()) {
            return '';
        }

        $setting = PaymentSetting::current();

        return sprintf(
            ' To complete your registration, deposit the training fee of ₱%s to %s — Account Name: %s, Account Number: %s. Then upload your proof of payment from the Payments page.%s',
            number_format($registration->training->payment_amount, 2),
            $setting->bank_name,
            $setting->account_name,
            $setting->account_number,
            filled($setting->instructions) ? " {$setting->instructions}" : ''
        );
    }

    public function url(object $notifiable): string
    {
        return route('registrations.index');
    }

    /**
     * The join link, when approval is what unlocked it.
     *
     * Approval and payment verification are the two gates, and either can be
     * the last one cleared — so both notifications carry the link and both ask
     * the same question. On a paid training this stays empty until the money
     * has been verified, which is the whole point of the rule.
     */
    private function joinLine(): string
    {
        $registration = $this->registration->fresh(['training', 'payments']);

        if ($registration === null || ! $registration->mayViewMeetingLink()) {
            return '';
        }

        $link = $registration->training->meeting_link;

        return blank($link) ? '' : " Your join link for this training: {$link}";
    }
}
