<?php

namespace App\Enums;

/**
 * Ported from v1's `payments.payment_method`.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case Online = 'online';
    case CreditCard = 'credit_card';
    /*
     * Not a payment at all, but it travels the same road: the participant
     * records it, the collecting officer verifies it, and it is what the
     * office holds while the money is outstanding. Modelling it as a method
     * rather than a flag on the registration means it shows up in the payment
     * queue, the exports and the audit trail without any of them learning a
     * second concept.
     */
    case Promissory = 'promissory';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::Online => 'Online Transfer',
            self::CreditCard => 'Credit Card',
            self::Promissory => 'Promissory Note',
        };
    }

    /**
     * Whether this settles what is owed, as opposed to merely promising to.
     *
     * The distinction is what lets a promissory note open the training room
     * door while still holding back the certificate.
     */
    public function isSettlement(): bool
    {
        return $this !== self::Promissory;
    }

    /**
     * Methods where a reference number is the only proof there is.
     *
     * Cash is paid over the counter against a receipt, so it is the one method
     * that cannot be required to carry one.
     */
    public function requiresReference(): bool
    {
        return ! in_array($this, [self::Cash, self::Promissory], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method) => ['value' => $method->value, 'label' => $method->label()],
            self::cases()
        );
    }
}
