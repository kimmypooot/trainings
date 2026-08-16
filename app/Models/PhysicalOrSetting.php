<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The singleton row of GCash and delivery settings for physical OR requests.
 *
 * The participant's request modal renders these, so Admin/Super Admin editing
 * them is the same thing as updating what participants see. A single row,
 * created lazily with sensible defaults and overwritten in place.
 */
#[Fillable([
    'gcash_number', 'account_name', 'courier_fee', 'delivery_instructions', 'updated_by',
])]
class PhysicalOrSetting extends Model
{
    protected function casts(): array
    {
        return [
            'courier_fee' => 'decimal:2',
        ];
    }

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
            'gcash_number' => '09069681944',
            'account_name' => 'Kim Benedick M. Banoyo',
            'courier_fee' => 200.00,
            'delivery_instructions' => implode(' ', [
                'To have your official receipt delivered, please pay the ₱200.00',
                'courier fee to the GCash account below, then upload a screenshot',
                'of your transaction. Any excess amount will be refunded',
                'accordingly. Delivery instructions follow.',
            ]),
        ]);
    }
}
