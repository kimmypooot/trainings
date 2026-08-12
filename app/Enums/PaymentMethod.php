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

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::Online => 'Online Transfer',
            self::CreditCard => 'Credit Card',
        };
    }

    /**
     * Methods where a reference number is the only proof there is.
     *
     * Cash is paid over the counter against a receipt, so it is the one method
     * that cannot be required to carry one.
     */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
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
