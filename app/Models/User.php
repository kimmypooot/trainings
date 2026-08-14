<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Notifications\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'google_id', 'google_avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Database defaults are not reflected on a freshly created model instance,
     * so the role is defaulted here too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => Role::Participant->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function isParticipant(): bool
    {
        return $this->role === Role::Participant;
    }

    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /**
     * Whether this user only sees the participants of one field office.
     *
     * Applies to the field-office role alone: HRD, management, and superadmin
     * see the whole region.
     */
    public function isScopedToFieldOffice(): bool
    {
        return $this->role === Role::FieldOffice;
    }

    /**
     * The office id this user is limited to, or null when unrestricted.
     *
     * A field-office user with no office assigned resolves to 0, which matches
     * nothing — failing closed rather than exposing every participant.
     */
    public function scopedFieldOfficeId(): ?int
    {
        if (! $this->isScopedToFieldOffice()) {
            return null;
        }

        return $this->field_office_id ?? 0;
    }

    /**
     * The participant's stable check-in token, minted on first use.
     */
    public function ensureQrToken(): string
    {
        if (! $this->qr_token) {
            $this->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return $this->qr_token;
    }

    /**
     * Invalidate the current code and issue a new one.
     */
    public function regenerateQrToken(): string
    {
        $this->forceFill(['qr_token' => Str::random(32)])->save();

        return $this->qr_token;
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function hasCompletedProfile(): bool
    {
        return $this->profile_completed_at !== null;
    }

    /**
     * Send the password reset link through the app's branded notification.
     *
     * Called by the password broker during a reset request; the email address
     * rides along so the reset page can be pre-filled without the visitor
     * retyping it.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token, $this->email));
    }
}
