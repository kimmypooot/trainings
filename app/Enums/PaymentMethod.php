<?php

namespace App\Enums;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

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
    /*
     * A walk-in fee already paid at a counter, evidenced only by the physical
     * receipt the participant was handed — not by anything CSC's own records
     * show. Distinct from Cash: Cash is a counter payment the office's own
     * queue can confirm, while this exists for the gap where it cannot, so
     * the participant has a way to file the claim (with a photo of the OR)
     * instead of being stuck with a payment that has nowhere to go.
     */
    case OfficialReceipt = 'official_receipt';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::Online => 'Online Transfer',
            self::CreditCard => 'Credit Card',
            self::Lddap => 'LDDAP-ADA',
            self::Promissory => 'Promissory Note',
            self::OfficialReceipt => 'Official Receipt (already paid, not yet reflected)',
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
     *
     * An official receipt is the opposite case from cash: the whole point of
     * choosing it is that CSC's own records do not show the payment, so a
     * photo of the OR is the only thing staff have to go on while they chase
     * down where it went missing.
     */
    public function expectsProof(): bool
    {
        return in_array($this, [self::Online, self::OfficialReceipt], true);
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
     * Methods that carry a reference number worth recording — asked for, never
     * demanded.
     *
     * A transfer or a cheque has a number on it, and it is what finance matches
     * against the bank statement later, so the counter form offers the field.
     * It stays optional because the officer at the desk does not always have
     * the slip in front of them: the money is in the till and the OR has been
     * issued, and refusing to record that until somebody reads a number off a
     * document that is still with the agency stops a payment that has already
     * happened.
     *
     * Cash is paid over the counter against a receipt and has no such number.
     * An official receipt is excluded for the opposite reason: its proof *is* a
     * receipt number, and the counter form already has a field for exactly
     * that. Asking for both put two boxes for one number in front of the
     * officer — and whichever they filled, the other was either empty or a
     * copy. The OR number is the one that finance reconciles on and the one
     * `or_number` holds, so it is the one that is asked for.
     *
     * This is the counter's rule only. The participant's own form has no OR
     * field — the office's receipt book is not theirs to write in — so it
     * records the number they are holding in `reference_number` and demands it
     * there, in its own `requiredIf` (see PaymentController::store). That is
     * the one place a reference is required, and the two doors differ here on
     * purpose.
     */
    public function collectsReference(): bool
    {
        return ! in_array($this, [self::Cash, self::Promissory, self::OfficialReceipt], true);
    }

    /**
     * The validation rule for a method being recorded against a training.
     *
     * Both payment doors ask the same question here — the counter form in
     * Admin\PaymentController and the participant's own form in
     * PaymentController — and each carried its own copy of it. Two copies of a
     * money rule is one copy too many: a promissory note accepted where the
     * training never offered one is a slot held without payment, and that is
     * precisely the kind of divergence that survives unnoticed because each
     * door is tested on its own.
     *
     * Takes the flag rather than a Training so the enum stays free of the
     * model, and so a caller cannot validate against a different training than
     * the one it resolved.
     *
     * The rest of each door's rules stay where they are, deliberately. They are
     * not the same rule set and should not be forced into one: the counter
     * collects an OR number and a collecting officer, the participant form
     * collects a proof upload, and the two differ on reference_number for a
     * reason covered by
     * PaymentTest::test_a_non_cash_payment_no_longer_needs_a_reference_number.
     */
    public static function rule(bool $acceptsPromissory): Enum
    {
        return Rule::enum(self::class)->when(
            ! $acceptsPromissory,
            fn (Enum $rule) => $rule->except(self::Promissory)
        );
    }

    /**
     * The dropdowns. Selectable methods only, each carrying whether a document
     * is expected with it and whether it has a reference number at all — so a
     * form shows the fields the method actually has from the same source the
     * server reads, rather than a second copy of the rule that drifts.
     *
     * @return array<int, array{value: string, label: string, expects_proof: bool, collects_reference: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $method) => [
                'value' => $method->value,
                'label' => $method->label(),
                'expects_proof' => $method->expectsProof(),
                'collects_reference' => $method->collectsReference(),
            ],
            self::selectable()
        );
    }
}
