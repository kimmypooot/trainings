<?php

namespace App\Enums;

/**
 * Who the training fee is billed to, ported from v1's `registrations.charge_to`.
 *
 * Finance needs this before the official receipt is cut, not after: an agency
 * charge is receipted to the agency and usually settled by LDDAP-ADA against a
 * disbursement voucher, while a personal charge is receipted to the individual.
 * Correcting the payee after issuance means cancelling an OR, which is why it
 * is asked at registration rather than at the counter.
 */
enum ChargeTo: string
{
    case Personal = 'personal';
    case Agency = 'agency';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Agency => 'Agency',
        };
    }

    /** The line shown under the option on the registration form. */
    public function description(): string
    {
        return match ($this) {
            self::Personal => 'You pay the fee yourself and the receipt is issued in your name.',
            self::Agency => 'Your agency settles the fee and the receipt is issued to the agency.',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $charge) => [
                'value' => $charge->value,
                'label' => $charge->label(),
                'description' => $charge->description(),
            ],
            self::cases()
        );
    }
}
