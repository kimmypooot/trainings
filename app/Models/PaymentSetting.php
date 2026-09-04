<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The singleton row of bank-deposit details for training fees.
 *
 * Modelled on PhysicalOrSetting: one row, created lazily with safe defaults
 * and overwritten in place. The approval notification and the participant's
 * payments page both render these, so Admin editing them is the same thing as
 * updating what participants are asked to deposit into.
 */
#[Fillable([
    'bank_name', 'account_name', 'account_number', 'instructions', 'updated_by',
])]
class PaymentSetting extends Model
{
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one row, created on first read so the rest of the app never has to
     * decide what the defaults are — those live here, in the one place that
     * owns them.
     */
    public static function current(): self
    {
        /*
         * `oldest('id')`, not a bare `first()`.
         *
         * There is meant to be one row, and nothing in the database enforces
         * that: two requests arriving together can both find none and both
         * create one, and a restore or a hand-run insert can do the same. An
         * unordered read then returns whichever row the storage engine feels
         * like, so two requests a second apart can disagree about a setting —
         * and the answer is stable in development right up until the day it
         * is not.
         *
         * Ordering does not stop a duplicate being written. It makes every
         * reader agree on which row is the real one, which is the half that
         * actually matters: the older row is the one the application has been
         * using, so it wins.
         */ $setting = static::oldest('id')->first();

        if ($setting !== null) {
            return $setting;
        }

        return static::create([
            'bank_name' => 'Land Bank of the Philippines',
            // The account holder as the bank has it, which is the Commission
            // itself — not the regional office that operates the account. It
            // has to match what a depositor will be shown at the counter, so
            // this is deliberately narrower than the office name used to sign
            // off notifications.
            'account_name' => 'Civil Service Commission',
            'account_number' => '000000000000',
            'instructions' => implode(' ', [
                'Deposit the training fee to the bank account below, then upload',
                'your proof of payment (deposit slip or transfer receipt) from the',
                'Payments page.',
            ]),
        ]);
    }
}
