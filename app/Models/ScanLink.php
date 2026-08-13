<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ScanLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A shareable, expiring grant to scan one training's door.
 *
 * The credential is deliberately two halves — see the migration for why. This
 * class owns the rules for both halves and for the third artefact they produce,
 * the *grant*, which is what the device actually carries once it is unlocked.
 *
 * The grant exists because of the offline case. A session cookie is the obvious
 * way to remember "this phone entered the code", and it is the wrong one: the
 * whole point of the station is that it may sit in a function room for six
 * hours with no signal and then sync, and Laravel's session would have expired
 * somewhere in the middle of that. Losing the credential at that moment would
 * strand a day of attendance on a phone. So unlocking mints a self-contained,
 * encrypted grant that the device stores itself and presents on every later
 * request; it is stateless, it cannot be forged without APP_KEY, and it dies
 * exactly when the link it came from dies.
 */
class ScanLink extends Model
{
    /** @use HasFactory<ScanLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'training_id',
        'token',
        'code_hash',
        'issued_by',
        'label',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        // Never serialisable into an Inertia payload by accident. The hash is
        // the credential's private half and the admin screen has no use for it.
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * How long a freshly issued link lasts.
     *
     * Long enough to cover setting a station up the night before and running a
     * multi-day course; short enough that a link forgotten in a group chat
     * stops working well before the next quarter's intake.
     */
    public const DEFAULT_LIFETIME_DAYS = 14;

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Mint a link and hand back the one and only copy of its code.
     *
     * The plaintext code is returned rather than stored, exactly like a personal
     * access token: the issuer sees it once, on the screen where they created
     * it, and after that nobody can recover it — a lost code means issuing a new
     * link, which is the correct and cheap remedy.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(
        Training $training,
        User $issuer,
        ?string $label = null,
        ?CarbonImmutable $expiresAt = null
    ): array {
        // Six digits rather than four. The gate is throttled, so this is not
        // guarded against a patient offline attacker — but it costs the operator
        // nothing to type and removes any temptation to brute-force the door.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $link = self::create([
            'training_id' => $training->getKey(),
            // 40 random characters: unguessable, and short enough to survive
            // being typed off a printed handover sheet if the QR will not scan.
            'token' => Str::random(40),
            'code_hash' => Hash::make($code),
            'issued_by' => $issuer->getKey(),
            'label' => $label,
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addDays(self::DEFAULT_LIFETIME_DAYS),
        ]);

        return [$link, $code];
    }

    /**
     * Usable right now — neither revoked nor past its expiry.
     *
     * Revocation is checked first only for readability; both are absolute. A
     * revoked link is refused even inside its lifetime, because revoking is what
     * an operator reaches for when a phone is lost.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function verifyCode(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    /**
     * The device's credential, valid for as long as the link itself.
     *
     * Encrypted rather than merely signed so the link id is not readable off a
     * phone's localStorage, and bounded by the link's own expiry so a grant can
     * never outlive its source. Revocation is still checked on every request
     * against the row, so a revoked link kills grants already in the wild.
     */
    public function mintGrant(): string
    {
        return Crypt::encryptString(json_encode([
            'link' => $this->getKey(),
            'token' => $this->token,
            'exp' => $this->expires_at->getTimestamp(),
        ]));
    }

    /**
     * Resolve a grant back to its link, or null if it is in any way unusable.
     *
     * Deliberately total: every failure mode — corrupt ciphertext, a grant for
     * another link, an expired payload, a revoked row — collapses to null, so
     * the caller has exactly one thing to check and no way to accidentally
     * accept a partially valid credential.
     */
    public static function fromGrant(?string $grant, string $token): ?self
    {
        if ($grant === null || $grant === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($grant), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || ($payload['token'] ?? null) !== $token) {
            return null;
        }

        if (($payload['exp'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        $link = self::query()->whereKey($payload['link'] ?? 0)->first();

        return $link !== null && $link->token === $token && $link->isActive() ? $link : null;
    }
}
