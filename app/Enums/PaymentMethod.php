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
    /*
     * Retired, not deleted.
     *
     * Card payments are no longer offered, but the ones already taken are
     * still in the table — and an enum case removed from under stored rows
     * turns every list, export and receipt that touches them into a cast
     * error. So the case stays and `options()` stops offering it: history
     * still reads, nothing new is written.
     */
    case CreditCard = 'credit_card';
    /*
     * How an agency actually settles a training fee: a List of Due and
     * Demandable Accounts Payable – Advice to Debit Account, drawn against a
     * disbursement voucher. It is a bank transfer the agency's accountant
     * initiates, which is why it belongs beside Online rather than Check.
     */
    case Lddap = 'lddap';
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
            self::Lddap => 'LDDAP-ADA',
            self::Promissory => 'Promissory Note',
        };
    }

    /**
     * Offered for a payment being recorded now.
     *
     * Distinct from cases(): a retired method must still be readable on the
     * payments it was recorded against, but must never be selectable again.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $method) => $method !== self::CreditCard,
        ));
    }

    /**
     * Whether a document is expected with this payment — not demanded.
     *
     * An online transfer leaves nothing at the office: no counter receipt, no
     * signed note, and since the participant form stopped asking, no reference
     * number either. So the slip is what finance would otherwise match against
     * the bank statement, and its absence is worth knowing about.
     *
     * Expected rather than required on purpose. Refusing the submission put
     * every participant who cannot scan — no printer, a lost slip, a transfer
     * made by somebody else — through the counter, which is a lot of load to
     * add for a document staff can chase. So the payment is accepted and the
     * gap is raised in the verification queue instead, where somebody can do
     * something about it. Cash and a promissory note carry their own paper and
     * are never flagged.
     */
    public function expectsProof(): bool
    {
        return $this === self::Online;
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
     * The dropdowns. Selectable methods only, each carrying whether it needs a
     * document — so the form marks the field required from the same source the
     * server validates against, rather than a second copy of the rule.
     *
     * @return array<int, array{value: string, label: string, expects_proof: bool, requires_reference: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method) => [
                'value' => $method->value,
                'label' => $method->label(),
                'expects_proof' => $method->expectsProof(),
                'requires_reference' => $method->requiresReference(),
            ],
            self::selectable()
        );
    }
}
