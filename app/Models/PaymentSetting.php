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
        $setting = static::first();

        if ($setting !== null) {
            return $setting;
        }

        return static::create([
            'bank_name' => 'Land Bank of the Philippines',
            'account_name' => 'Civil Service Commission Regional Office VIII',
            'account_number' => '000000000000',
            'instructions' => implode(' ', [
                'Deposit the training fee to the bank account below, then upload',
                'your proof of payment (deposit slip or transfer receipt) from the',
                'Payments page.',
            ]),
        ]);
    }
}
