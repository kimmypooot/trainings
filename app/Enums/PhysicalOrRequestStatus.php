<?php

namespace App\Enums;

/**
 * The physical OR delivery pipeline.
 *
 * A participant outside Region VIII may ask for a physical copy of their
 * official receipt. That is not a yes/no decision — the receipt has to be
 * prepared, paid for (a GCash courier fee), handed to a courier, and tracked —
 * so it gets its own staged pipeline rather than being folded into the generic
 * RequestStatus.
 *
 * The stages are strictly ordered and a request only ever moves forward, so
 * the pipeline can be read straight off the enum instead of being spelled out
 * in a `match` at every call site. Rejection is reachable from anywhere and so
 * is not part of the sequence.
 */
enum PhysicalOrRequestStatus: string
{
    case RequestSubmitted = 'request_submitted';
    case PaymentVerificationPending = 'payment_verification_pending';
    case PaymentVerified = 'payment_verified';
    case Preparing = 'preparing';
    case ReadyForShipment = 'ready_for_shipment';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::RequestSubmitted => 'Request Submitted',
            self::PaymentVerificationPending => 'Payment Verification Pending',
            self::PaymentVerified => 'Payment Verified',
            self::Preparing => 'Preparing Physical OR',
            self::ReadyForShipment => 'Ready for Shipment',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Rejected => 'Rejected / Cancelled',
        };
    }

    /**
     * What the participant is told. The internal stage names describe CSC's
     * routing, which means nothing to someone waiting on a document in the
     * mail.
     */
    public function participantMessage(): string
    {
        return match ($this) {
            self::RequestSubmitted => 'Your request for a physical copy of your official receipt has been received.',
            self::PaymentVerificationPending => 'Your courier fee payment is being verified.',
            self::PaymentVerified => 'Your courier fee payment has been verified.',
            self::Preparing => 'Your official receipt is being prepared for shipment.',
            self::ReadyForShipment => 'Your official receipt is ready for shipment.',
            self::Shipped => 'Your official receipt has been handed to the courier.',
            self::Delivered => 'Your physical official receipt has been delivered.',
            self::Rejected => 'Your request for a physical official receipt was declined.',
        };
    }

    /**
     * The ordered pipeline. Rejection is reachable from anywhere and so is not
     * part of the sequence.
     *
     * @return array<int, self>
     */
    public static function pipeline(): array
    {
        return [
            self::RequestSubmitted,
            self::PaymentVerificationPending,
            self::PaymentVerified,
            self::Preparing,
            self::ReadyForShipment,
            self::Shipped,
            self::Delivered,
        ];
    }

    /** Nothing moves once the receipt is out or the request is declined. */
    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Rejected;
    }

    /** Still somewhere in the pipeline — the participant's "open" set. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * The single stage this one may advance to, or null at the end of the
     * pipeline. Rejection is handled separately because it needs a reason.
     */
    public function next(): ?self
    {
        $pipeline = self::pipeline();
        $position = array_search($this, $pipeline, true);

        if ($position === false) {
            return null;
        }

        return $pipeline[$position + 1] ?? null;
    }

    /**
     * Forward-only, one step at a time. A skipped stage is almost always a
     * misclick rather than an intent, and the audit trail is worthless if the
     * pipeline can be jumped.
     */
    public function canAdvanceTo(self $target): bool
    {
        return $this->next() === $target;
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
