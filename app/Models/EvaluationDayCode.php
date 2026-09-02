<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EvaluationDayCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The scannable address of one training day's evaluation form.
 *
 * This class owns the code's lifecycle — issuing, rotating, revoking — and
 * nothing about eligibility. Whether the day it names can actually be evaluated,
 * and by whom, is SmeEvaluationService's question and is asked fresh on every
 * scan. Keeping those apart is what lets an admin print codes a week early: the
 * paper says only "this is day 3", and what day 3 means is decided when someone
 * points a phone at it.
 *
 * See the migration for why the token is stored in the clear.
 */
class EvaluationDayCode extends Model
{
    /** @use HasFactory<EvaluationDayCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'training_id',
        'day_number',
        'token',
        'issued_by',
        'revoked_at',
        'last_scanned_at',
        'scan_count',
    ];

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'scan_count' => 'integer',
            'revoked_at' => 'immutable_datetime',
            'last_scanned_at' => 'immutable_datetime',
        ];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * The code for a day, minted if this is the first time it has been asked for.
     *
     * updateOrCreate rather than create: the admin screen offers "generate codes
     * for this training" as one button, and pressing it twice — or pressing it
     * again after adding a day to the run — must not fail on the unique index or
     * quietly reissue codes for days whose posters are already on a wall.
     * Issuing is idempotent; replacing a code is `regenerate()`, which is a
     * different button with a different warning on it.
     */
    public static function issue(Training $training, int $day, User $issuer): self
    {
        return self::firstOrCreate(
            ['training_id' => $training->getKey(), 'day_number' => $day],
            ['token' => self::mintToken(), 'issued_by' => $issuer->getKey()],
        );
    }

    /**
     * Replace the token, killing every copy of the old one instantly.
     *
     * The scan counters are reset with it. They describe a particular sign on a
     * particular wall, and carrying them across to a code nobody has scanned yet
     * would make the one diagnostic this table offers — "is anybody using it?" —
     * answer for a poster that no longer exists.
     */
    public function regenerate(User $issuer): self
    {
        $this->forceFill([
            'token' => self::mintToken(),
            'issued_by' => $issuer->getKey(),
            'revoked_at' => null,
            'last_scanned_at' => null,
            'scan_count' => 0,
        ])->save();

        return $this;
    }

    public function revoke(): self
    {
        $this->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        return $this;
    }

    public function restore(): self
    {
        $this->forceFill(['revoked_at' => null])->save();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Record that somebody pointed a phone at this.
     *
     * Counted before eligibility is known, deliberately. "Forty scans, three
     * responses" is a fact worth having — it says the sign is working and the
     * form is not — and it is only visible if a scan that ends in "you are not
     * registered" is counted the same as one that ends in the form.
     *
     * Incremented in SQL rather than read-modify-written in PHP, because the
     * one moment this column is written is the one moment it is contended: a
     * room of thirty people scans the same poster within a few seconds of each
     * other, and `$this->scan_count + 1` from thirty processes holding the same
     * stale read undercounts every time.
     */
    public function markScanned(): void
    {
        $this->increment('scan_count', 1, ['last_scanned_at' => CarbonImmutable::now()]);
    }

    /** The URL the QR image encodes. */
    public function url(): string
    {
        return route('evaluations.scan', ['token' => $this->token]);
    }

    private static function mintToken(): string
    {
        return Str::random(40);
    }
}
