<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that one participant has been asked to evaluate one training day.
 *
 * Written by SendEvaluationInvitations before the notification is dispatched,
 * so the unique index — not the command's own bookkeeping — is what stops a
 * second run mailing the same room twice.
 */
#[Fillable(['registration_id', 'day_number', 'sent_at'])]
class EvaluationInvitation extends Model
{
    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
